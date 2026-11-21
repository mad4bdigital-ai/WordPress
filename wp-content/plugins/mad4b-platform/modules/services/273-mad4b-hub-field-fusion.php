<?php
/**
 * Plugin Name: MAD4B Hub Field-Level Fusion Intelligence
 * Description: Per-company/per-content-type/per-meta-field policy and reversible merge proposals. Read-only.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Field_Fusion_V1 {
    const CONTRACT='mad4b.hub.field-fusion-proposal.v1';
    const OPTION='mad4b_hub_field_fusion_policies_v1';
    const STRATEGIES=['richest'=>'Prefer rich verified content','freshest'=>'Prefer freshest valid value',
        'union_review'=>'Propose union with provenance','exact'=>'Require exact identity',
        'manual'=>'Manual review','keep_separate'=>'Never unify'];
    const MAX_TEXT=15000;
    const MAX_FIELDS=75;
    private static $custom=[];
    public static function enabled(): bool {
        return wp_get_environment_type()==='staging' &&
            strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))==='tours.dev.mad4b.com';
    }

    /** Extend the registry, never override a registered key silently. */
    public static function register_field($type,$field,array $spec): bool {
        if(!is_string($type)||!is_string($field)||
            !preg_match('/^[a-z0-9_-]{2,55}$/D',$type)||
            !preg_match('/^[a-z][a-z0-9_.-]{1,80}$/D',$field)||
            isset(self::$custom[$type][$field])||
            !in_array($spec['strategy']??null,array_keys(self::STRATEGIES),true)||
            !in_array($spec['value_type']??null,['text','number','id','set'],true))return false;
        self::$custom[$type][$field]=[
            'strategy'=>$spec['strategy'],'value_type'=>$spec['value_type'],
            'sensitive'=>!empty($spec['sensitive']),
            'localized'=>!empty($spec['localized']),
            'weight'=>max(0,min(100,(int)($spec['weight']??50))),
        ];
        return true;
    }
    public static function registry($type): array {
        $global=[
            'title'=>['strategy'=>'richest','value_type'=>'text','localized'=>true,'weight'=>50],
            'description'=>['strategy'=>'richest','value_type'=>'text','localized'=>true,'weight'=>85],
            'summary'=>['strategy'=>'richest','value_type'=>'text','localized'=>true,'weight'=>55],
            'source_url'=>['strategy'=>'manual','value_type'=>'id','weight'=>20],
            'source_slug'=>['strategy'=>'manual','value_type'=>'id','weight'=>20],
            'source_wpml_trid'=>['strategy'=>'keep_separate','value_type'=>'id','weight'=>0],
            'taxonomy_refs'=>['strategy'=>'union_review','value_type'=>'set','weight'=>60],
        ];
        $typed=[
            'tours-and-activities'=>[
                'origin_place_id'=>['strategy'=>'exact','value_type'=>'id','weight'=>100],
                'origin_location_term'=>['strategy'=>'manual','value_type'=>'id','weight'=>65],
            ],
            'properties'=>[
                'property_place_id'=>['strategy'=>'exact','value_type'=>'id','weight'=>100],
                'property_address'=>['strategy'=>'manual','value_type'=>'text','weight'=>90],
            ],
            'transportations'=>[
                'from_station_id'=>['strategy'=>'exact','value_type'=>'id','weight'=>100],
                'to_station_id'=>['strategy'=>'exact','value_type'=>'id','weight'=>100],
            ],
        ];
        $raw=array_merge($global,$typed[$type]??[],self::$custom[$type]??[]);
        foreach($raw as $key=>&$spec){
            $spec['localized']=!empty($spec['localized']);
            $spec['sensitive']=!empty($spec['sensitive']);
            $spec['weight']=(int)($spec['weight']??50);
        }
        unset($spec);
        return $raw;
    }
    public static function policies($company,$type): array {
        $registry=self::registry($type);
        $all=get_option(self::OPTION,[]);
        $rules=$all[$company][$type]??[];
        if(!is_array($rules))return $registry;
        foreach($rules as $field=>$override){
            if(!isset($registry[$field])||!is_array($override))continue;
            $strategy=$override['strategy']??null;
            if(in_array($strategy,array_keys(self::STRATEGIES),true))
                $registry[$field]['strategy']=$strategy;
            if(isset($override['weight']) && is_numeric($override['weight']))
                $registry[$field]['weight']=max(0,min(100,(int)$override['weight']));
            // No override can lower the sensitive or identity isolation boundary.
            if($registry[$field]['sensitive'])$registry[$field]['strategy']='keep_separate';
            if(($registry[$field]['strategy']??'')!=='keep_separate' &&
                (self::registry($type)[$field]['strategy']??null)==='exact')
                $registry[$field]['strategy']='exact';
            if($field==='source_wpml_trid')$registry[$field]['strategy']='keep_separate';
        }
        return $registry;
    }
    /** Save a bounded policy override. Does NOT run, approve or publish any fusion. */
    public static function set_policy($company,$type,$field,$strategy,$weight) {
        if(!self::enabled()||!current_user_can('manage_options'))return new WP_Error('fusion_forbidden','Central Staging admin only');
        if(!is_string($company)||!preg_match('/^[a-z0-9-]{3,70}$/D',$company)||
            !is_string($type)||!preg_match('/^[a-z0-9_-]{2,55}$/D',$type)||
            !is_string($field)||!isset(self::registry($type)[$field])||
            !is_string($strategy)||!isset(self::STRATEGIES[$strategy])||
            !is_numeric($weight)||(int)$weight<0||(int)$weight>100)
            return new WP_Error('fusion_policy_invalid','Invalid policy scope or rule');
        $spec=self::registry($type)[$field];
        if(class_exists('MAD4B_Central_Company_REST_V1') &&
            !array_key_exists($company,MAD4B_Central_Company_REST_V1::COMPANIES))
            return new WP_Error('fusion_company_unknown','Company is not registered');
        if($spec['strategy']==='exact' && $strategy!=='exact')
            return new WP_Error('fusion_identity_required','An identity field must remain exact');
        if($spec['sensitive'] && $strategy!=='keep_separate')
            return new WP_Error('fusion_sensitive_field','Sensitive data cannot be fused');
        if(in_array($field,['source_wpml_trid'],true)&&$strategy!=='keep_separate')
            return new WP_Error('fusion_translation_identity','WPML identity cannot be merged');
        $config=get_option(self::OPTION,[]);
        if(!is_array($config))$config=[];
        $config[$company][$type][$field]=['strategy'=>$strategy,'weight'=>(int)$weight];
        update_option(self::OPTION,$config,false);
        return ['company'=>$company,'type'=>$type,'field'=>$field,
            'strategy'=>$strategy,'weight'=>(int)$weight];
    }
    private static function sample($value): string {
        if(is_array($value))return '[set '.count($value).' items]';
        $v=trim(wp_strip_all_tags((string)$value));
        return mb_substr($v,0,140,'UTF-8');
    }
    private static function canon($v): string {
        if(is_array($v)) {
            $items=[];
            foreach($v as $k=>$item) {
                if(is_array($item))$item=wp_json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if(is_scalar($item))$items[]=trim((string)$k).':'.trim((string)$item);
            }
            sort($items,SORT_STRING);
            return implode('|',$items);
        }
        if(is_bool($v))return $v?'true':'false';
        $v=trim(wp_strip_all_tags((string)$v));
        $v=mb_strtolower($v,'UTF-8');
        return (string)preg_replace('/[\p{Z}\p{P}\s]+/u',' ',$v);
    }
    private static function valid($v,$kind): bool {
        if($v===null||$v==='')return false;
        if($kind==='set')return is_array($v)&&count($v)>0&&count($v)<=100;
        if(is_array($v)||is_object($v)||is_bool($v))return false;
        if($kind==='number')return is_numeric($v)&&is_finite((float)$v);
        if($kind==='id')return strlen((string)$v)<=250&&trim((string)$v)!=='';
        return mb_strlen((string)$v,'UTF-8')<=self::MAX_TEXT && trim((string)$v)!=='';
    }
    private static function richness($v,$kind): float {
        if(!self::valid($v,$kind))return 0.0;
        if($kind==='set')return min(1.0,count($v)/12);
        if($kind==='id'||$kind==='number')return .8;
        $text=trim(wp_strip_all_tags((string)$v));
        if(preg_match('/^(?:n\/a|none|null|test|lorem ipsum|coming soon|tba)$/iu',$text))
            return .05;
        $len=mb_strlen($text,'UTF-8');
        $words=preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY);
        return round(min(1.0,.14+min(.70,$len/900)+min(.16,count($words)/100)),3);
    }
    private static function read($hubId,$field) {
        $post=get_post($hubId);
        if(!$post)return null;
        if($field==='title')return $post->post_title;
        if($field==='description')return $post->post_content;
        if($field==='summary')return $post->post_excerpt;
        if($field==='source_url')return get_post_meta($hubId,'_mad4b_hub_import_source_url',true);
        if($field==='source_slug')return get_post_meta($hubId,'_mad4b_hub_import_source_slug',true);
        if($field==='source_wpml_trid')return get_post_meta($hubId,'_mad4b_hub_import_translation_trid',true);
        if($field==='taxonomy_refs')return get_post_meta($hubId,'_mad4b_hub_import_taxonomy_refs',true);
        $facts=get_post_meta($hubId,'_mad4b_hub_import_identity_facts',true);
        if(is_array($facts)&&array_key_exists($field,$facts))return $facts[$field];
        $custom=get_post_meta($hubId,'_mad4b_hub_import_source_metadata',true);
        return is_array($custom)?($custom[$field]??null):null;
    }
    /**
     * Reversible proposal. This never chooses a merged product, price or source authority.
     * No post meta writes, no taxonomy reassignment, no cross-tenant publication.
     */
    public static function compare($left,$right,array $scope=[]) {
        if(!self::enabled() || !is_int($left)||!is_int($right)||$left<1||$right<1||$left===$right)
            return new WP_Error('fusion_invalid_pair','Invalid pair');
        $ownerA=(string)get_post_meta($left,'_mad4b_hub_import_owner',true);
        $ownerB=(string)get_post_meta($right,'_mad4b_hub_import_owner',true);
        $typeA=(string)get_post_meta($left,'_mad4b_hub_import_type',true);
        $typeB=(string)get_post_meta($right,'_mad4b_hub_import_type',true);
        $langA=(string)get_post_meta($left,'_mad4b_hub_import_language',true);
        $langB=(string)get_post_meta($right,'_mad4b_hub_import_language',true);
        if($ownerA===''||$ownerA!==$ownerB||$typeA===''||$typeA!==$typeB||
            $langA===''||$langA!==$langB)
            return new WP_Error('fusion_scope_mismatch','Company, kind or language mismatch');
        if(isset($scope['company_id'])&&$scope['company_id']!==$ownerA)
            return new WP_Error('fusion_company_mismatch','Owner mismatch');
        if(isset($scope['source_type'])&&$scope['source_type']!==$typeA)
            return new WP_Error('fusion_type_mismatch','Type mismatch');
        $sourceA=(int)get_post_meta($left,'_mad4b_hub_import_source_id',true);
        $sourceB=(int)get_post_meta($right,'_mad4b_hub_import_source_id',true);
        if($sourceA<1||$sourceB<1)return new WP_Error('fusion_source_missing','Missing source provenance');
        $policies=self::policies($ownerA,$typeA);
        if(count($policies)>self::MAX_FIELDS)return new WP_Error('fusion_registry_limit','Too many fields');
        $rows=[];$coverage=0;$quality=[(string)$left=>0,(string)$right=>0];$blocks=[];
        $draft=[];$issues=[];$unverifiedIdentity=[];
        $modifiedA=(string)get_post_meta($left,'_mad4b_hub_import_source_modified',true);
        $modifiedB=(string)get_post_meta($right,'_mad4b_hub_import_source_modified',true);
        foreach($policies as $field=>$spec) {
            $strategy=$spec['strategy'];
            $a=self::read($left,$field);$b=self::read($right,$field);
            $presentA=self::valid($a,$spec['value_type']);
            $presentB=self::valid($b,$spec['value_type']);
            $same=$presentA&&$presentB&&self::canon($a)===self::canon($b);
            $rankA=$presentA?self::richness($a,$spec['value_type']):0.0;
            $rankB=$presentB?self::richness($b,$spec['value_type']):0.0;
            $coverage+=($presentA||$presentB)?1:0;
            $quality[(string)$left]+=$rankA*$spec['weight'];
            $quality[(string)$right]+=$rankB*$spec['weight'];
            $state='missing_both';$choice=null;
            if($spec['sensitive']||$strategy==='keep_separate')$state='keep_separate';
            elseif($strategy==='exact' && (!$presentA || !$presentB)) {
                $state='identity_unverified';
                $unverifiedIdentity[]=$field;
            }
            elseif(!$presentA&&!$presentB)$state='missing_both';
            elseif($same){$state='same_value_preserve_provenance';$choice=$left;}
            elseif($strategy==='manual')$state='review_conflict_or_gap';
            elseif($strategy==='exact') {
                $state=($presentA&&$presentB)?'identity_conflict_block':'identity_unverified';
                if($state==='identity_conflict_block')$blocks[]=$field;
            } elseif($strategy==='union_review')$state='review_union';
            elseif($strategy==='freshest') {
                $stampA=strtotime($modifiedA);
                $stampB=strtotime($modifiedB);
                if($presentA&&$presentB&&$stampA&&$stampB&&$stampA!==$stampB &&
                    $stampA>946684800 && $stampB>946684800) {
                    $state='propose_fresher_value_review';
                    $choice=$stampA>$stampB?$left:$right;
                }else $state='review_freshness_requires_verified_revision';
            }
            elseif($strategy==='richest'){
                if($presentA&&!$presentB){$state='propose_fill_missing';$choice=$left;}
                elseif(!$presentA&&$presentB){$state='propose_fill_missing';$choice=$right;}
                elseif(abs($rankA-$rankB)>.08) {
                    $state='propose_richer_value_review';
                    $choice=$rankA>$rankB?$left:$right;
                }else $state='review_equal_quality_conflict';
            }
            if($choice!==null && !$spec['sensitive'])$draft[$field]=[
                'value_from_hub_id'=>$choice,
                'source_post_id'=>$choice===$left?$sourceA:$sourceB,
                'value_preview'=>self::sample($choice===$left?$a:$b),
                'selection_state'=>$state,
                'pending_review'=>true
            ];
            if($state==='identity_conflict_block'||$state==='review_equal_quality_conflict'||
                $state==='review_conflict_or_gap')$issues[]=$field;
            // Never silently accept field winners as a full entity merge.
            $rows[$field]=[
                'strategy'=>$strategy,'value_type'=>$spec['value_type'],
                'weight'=>$spec['weight'],'localized'=>$spec['localized'],
                'present'=>[(string)$left=>$presentA,(string)$right=>$presentB],
                'quality'=>[(string)$left=>$rankA,(string)$right=>$rankB],
                'value_preview'=>[(string)$left=>$spec['sensitive']?'[redacted]':self::sample($a),
                    (string)$right=>$spec['sensitive']?'[redacted]':self::sample($b)],
                'value_sha256'=>[(string)$left=>$presentA&&!$spec['sensitive']?hash('sha256',self::canon($a)):null,
                    (string)$right=>$presentB&&!$spec['sensitive']?hash('sha256',self::canon($b)):null],
                'decision'=>$state,'proposed_value_from_hub_id'=>$choice,
                'requires_review'=>!$same && ($presentA||$presentB),
                'auto_apply'=>false,
            ];
        }
        // The absence of decisive identity evidence is a HOLD, not proof of sameness.
        // An address requires verified corroboration; by itself it never licenses auto-merge.
        if($typeA==='properties') {
            $placeA=self::read($left,'property_place_id');
            $placeB=self::read($right,'property_place_id');
            $addressA=self::read($left,'property_address');
            $addressB=self::read($right,'property_address');
            if(!self::valid($placeA,'id') || !self::valid($placeB,'id')) {
                if(!in_array('property_place_id',$unverifiedIdentity,true))
                    $unverifiedIdentity[]='property_place_id';
            }
            if(self::valid($addressA,'text') && self::valid($addressB,'text') &&
               self::canon($addressA)!==self::canon($addressB)) {
                $issues[]='property_address';
            }
        }
        $state=$blocks?'identity_conflict_hold':
            ($unverifiedIdentity?'identity_unverified_hold':'candidate_not_verified');
        return [
            'contract'=>self::CONTRACT,'company_id'=>$ownerA,'source_type'=>$typeA,
            'language'=>$langA,'record_ids'=>[$left,$right],
            'source_ids'=>[$sourceA,$sourceB],
            'field_policy_count'=>count($policies),'field_coverage'=>$coverage,
            'per_record_quality_score'=>$quality,
            'identity_conflict_fields'=>$blocks,
            'identity_unverified_fields'=>array_values(array_unique($unverifiedIdentity)),
            'entity_resolution_state'=>$state,
            'field_proposals'=>$rows,
            'canonical_draft'=>[
                'status'=>$blocks?'blocked_identity_conflict':
                    ($unverifiedIdentity?'blocked_unverified_identity':'review_required'),
                'field_source_selection'=>$draft,
                'unresolved_fields'=>array_values(array_unique($issues)),
                'all_original_source_ids'=>[$sourceA,$sourceB],
                'can_apply'=>false,
            ],
            'auto_merge'=>false,'auto_publish'=>false,'source_records_untouched'=>true,
        ];
    }
    public static function save_action(): void {
        if(!self::enabled()||!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_hub_field_fusion_update');
        $company=isset($_POST['company'])?sanitize_key(wp_unslash($_POST['company'])):'';
        $type=isset($_POST['source_type'])?sanitize_key(wp_unslash($_POST['source_type'])):'';
        $field=isset($_POST['field'])?sanitize_text_field(wp_unslash($_POST['field'])):'';
        $strategy=isset($_POST['strategy'])?sanitize_key(wp_unslash($_POST['strategy'])):'';
        $weight=isset($_POST['weight'])?(int)$_POST['weight']:-1;
        $result=self::set_policy($company,$type,$field,$strategy,$weight);
        wp_safe_redirect(add_query_arg(['post_type'=>'mad4b_hub_nile','page'=>'mad4b-hub-field-fusion',
            'company'=>$company,'source_type'=>$type,
            'result'=>is_wp_error($result)?$result->get_error_code():'saved'],admin_url('edit.php')));
        exit;
    }
    public static function menu(): void {
        if(!self::enabled())return;
        add_submenu_page('edit.php?post_type=mad4b_hub_nile',
            'Content Hub Field Fusion Policies','Field Fusion Policies',
            'manage_options','mad4b-hub-field-fusion',[__CLASS__,'screen']);
    }
    public static function screen(): void {
        if(!self::enabled()||!current_user_can('manage_options'))return;
        $company=isset($_GET['company'])?sanitize_key(wp_unslash($_GET['company'])):'tourism-almallahgroup';
        $type=isset($_GET['source_type'])?sanitize_key(wp_unslash($_GET['source_type'])):'properties';
        $fields=self::policies($company,$type);
        echo '<div class="wrap"><h1>Content Hub — Field-Level Fusion</h1><p>Review-only policy. Nothing is merged, published or deleted.</p>';
        echo '<p>Company: '.esc_html($company).' | Type: '.esc_html($type).'</p>';
        echo '<form method="get" action="'.esc_url(admin_url('edit.php')).'">';
        echo '<input type="hidden" name="post_type" value="mad4b_hub_nile"><input type="hidden" name="page" value="mad4b-hub-field-fusion">';
        echo '<label>Source Company <select name="company">';
        $companies=class_exists('MAD4B_Central_Company_REST_V1')?array_keys(MAD4B_Central_Company_REST_V1::COMPANIES):[$company];
        foreach($companies as $id)
            echo '<option value="'.esc_attr($id).'" '.selected($company,$id,false).'>'.esc_html($id).'</option>';
        echo '</select></label> <label>Content type <select name="source_type">';
        foreach(['tours-and-activities'=>'Tours','properties'=>'Hotels & Properties','transportations'=>'Transportations'] as $slug=>$name)
            echo '<option value="'.esc_attr($slug).'" '.selected($type,$slug,false).'>'.esc_html($name).'</option>';
        echo '</select></label> ';
        submit_button('Load field policies','secondary','submit',false);
        echo '</form><hr>';
        foreach($fields as $field=>$spec) {
            echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post" style="margin:7px 0;padding:12px;background:#fff;border:1px solid #ccc;display:flex;flex-wrap:wrap;gap:14px;align-items:center">';
            wp_nonce_field('mad4b_hub_field_fusion_update');
            echo '<input type="hidden" name="action" value="mad4b_hub_field_fusion_update">';
            foreach(['company'=>$company,'source_type'=>$type,'field'=>$field] as $key=>$value)
                echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
            echo '<strong style="min-width:210px">'.esc_html($field).'</strong><label>Strategy <select name="strategy">';
            foreach(self::STRATEGIES as $key=>$label)
                echo '<option value="'.esc_attr($key).'" '.selected($spec['strategy'],$key,false).'>'.esc_html($label).'</option>';
            echo '</select></label><label>Weight <input name="weight" type="number" min="0" max="100" value="'.(int)$spec['weight'].'" style="width:75px"></label>';
            submit_button('Save rule','secondary','submit',false);
            echo '</form>';
        }
        echo '<p>Field-level proposals preserve competing values and provenance; review before choosing any canonical representation.</p></div>';
    }
    public static function boot(): void {
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_post_mad4b_hub_field_fusion_update',[__CLASS__,'save_action']);
    }

    /**
     * Read-only JetEngine definition inventory for original Company Manager.
     * Explicitly scoped schemas are portable *candidates*, never apply receipts.
     * Unscoped/global definitions must receive separate ownership review.
     */
    public static function reusable_jetengine_definition_manifest($post_type): array {
        $post_type=sanitize_key((string)$post_type);
        if(!$post_type || !post_type_exists($post_type))
            return ['contract'=>'mad4b.field-fusion.jetengine-definition-manifest.v1',
                'state'=>'INVALID_POST_TYPE','auto_apply'=>false,'definitions'=>[]];
        // Discover registered tour taxonomy plus related rate post type. This
        // inventory is non-authorizing; each target still needs its own readback.
        $relatedTypes=$post_type==='tours-and-activities' && post_type_exists('tour-rates')
            ? ['tour-rates'] : [];
        $relatedTypes=array_values(array_filter(array_map('sanitize_key',
            (array)apply_filters('mad4b_field_fusion_schema_related_post_types',$relatedTypes,$post_type)),
            'post_type_exists'));
        $relevantTypes=array_values(array_unique(array_merge([$post_type],$relatedTypes)));
        $relevantTaxes=array_values(array_map('sanitize_key',(array)get_object_taxonomies($post_type)));
        $definitions=[];
        foreach(array_slice((array)get_option('jet_engine_meta_boxes',[]),0,80,true) as $box_id=>$box){
            if(!is_array($box))continue;
            $args=(array)($box['args']??[]);
            $objectType=(string)($args['object_type']??'post');
            $types=array_values(array_filter(array_map('sanitize_key',(array)($args['allowed_post_type']??[]))));
            $taxonomies=array_values(array_filter(array_map('sanitize_key',(array)($args['allowed_tax']??[]))));
            if($objectType==='post'){
                if($types && !array_intersect($types,$relevantTypes))continue;
                $schemaScope=!$types?'UNSCOPED_REQUIRES_OWNER_REVIEW':
                    (in_array($post_type,$types,true)?'EXPLICIT_POST_TYPE':'EXPLICIT_RELATED_POST_TYPE');
            }elseif($objectType==='tax'||$objectType==='taxonomy'){
                if(!$taxonomies || !array_intersect($taxonomies,$relevantTaxes))continue;
                $schemaScope='EXPLICIT_TAXONOMY';
            }else continue;
            $fields=[];
            foreach(array_slice((array)($box['meta_fields']??[]),0,160) as $entry){
                if(!is_array($entry))continue;
                $key=(string)($entry['name']??'');
                if(!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{1,90}$/D',$key))continue;
                $fields[$key]=(string)($entry['type']??'unknown');
            }
            ksort($fields,SORT_STRING);
            $definitions[]=[
                'id'=>(string)$box_id,
                'name'=>(string)($args['name']??''),
                'post_types'=>$types,
                'taxonomies'=>$taxonomies,
                'object_type'=>$objectType,
                'scope'=>$schemaScope,
                'definition_sha256'=>hash('sha256',wp_json_encode($box)),
                'semantic_sha256'=>hash('sha256',wp_json_encode([
                    'id'=>(string)$box_id,'object_type'=>$objectType,
                    'post_types'=>$types,'taxonomies'=>$taxonomies,'field_types'=>$fields])),
                'field_types'=>$fields,
                'auto_apply'=>false,
                'target_schema_receipt_required'=>true,
            ];
        }
        return ['contract'=>'mad4b.field-fusion.jetengine-definition-manifest.v1',
            'post_type'=>$post_type,'source_origin'=>home_url(),
            'definition_count'=>count($definitions),
            'explicit_post_type_count'=>count(array_filter($definitions,static fn($v)=>$v['scope']==='EXPLICIT_POST_TYPE')),
            'related_post_type_count'=>count(array_filter($definitions,static fn($v)=>$v['scope']==='EXPLICIT_RELATED_POST_TYPE')),
            'explicit_taxonomy_count'=>count(array_filter($definitions,static fn($v)=>$v['scope']==='EXPLICIT_TAXONOMY')),
            'definitions'=>$definitions,'auto_apply'=>false,
            'company_values_included'=>false,'target_schema_readback_verified'=>false,
            'source_definition_presence_is_not_target_readback'=>true];
    }

    /**
     * Provider-neutral, read-only semantic dependencies for the ORIGINAL Field Fusion
     * owner. It discovers declarations, not secrets or commercial values. Client
     * rendering and computed CSS are not inferred from authored Elementor data.
     * Intentionally on-demand: never traverse templates on normal page loads.
     */
    public static function semantic_snapshot($post_id,array $options=[]) {
        if(!(defined('WP_CLI') && WP_CLI) && !current_user_can('manage_options'))
            return new WP_Error('fusion_semantic_forbidden','Operator read permission required');
        $id=absint($post_id); $post=$id?get_post($id):null;
        if(!$post)return new WP_Error('fusion_semantic_post_missing','Unknown content record');
        $max_fields=160; $max_boxes=80;
        $field_names=[]; $invalid=0;
        foreach((array)($options['field_names']??[]) as $f){
            if(!is_string($f)||!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{1,90}$/D',$f)){$invalid++;continue;}
            $field_names[$f]=true;
            if(count($field_names)>=30)break;
        }
        $catalog=[]; $add=static function($name,$origin,$type='unknown') use (&$catalog,$max_fields) {
            if(!is_string($name)||!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{1,90}$/D',$name))return;
            if(!isset($catalog[$name])){
                if(count($catalog)>=$max_fields)return;
                $catalog[$name]=['origins'=>[],'declared_type'=>$type,'value_exposed'=>false];
            }
            $catalog[$name]['origins'][$origin]=true;
            if($type!=='unknown'&&$catalog[$name]['declared_type']==='unknown')
                $catalog[$name]['declared_type']=$type;
        };
        foreach((array)get_registered_meta_keys('post',$post->post_type) as $name=>$definition)
            $add($name,'wordpress_registered_meta',(string)($definition['type']??'unknown'));
        // Term schemas are independently owned: no assumption they are post meta.
        $taxonomy_defs=[];
        foreach(array_slice((array)get_object_taxonomies($post->post_type,'names'),0,45) as $tax){
            $registered=(array)get_registered_meta_keys('term',$tax);
            $assigned=get_the_terms($id,$tax);
            $terms=[];
            if(is_array($assigned)){
                foreach(array_slice($assigned,0,30) as $term)
                    $terms[]=['term_id'=>(int)$term->term_id,'taxonomy'=>$tax,
                        'wpml_translation_state'=>'NATIVE_READBACK_REQUIRED'];
            }
            $taxonomy_defs[$tax]=[
                'registered_term_meta_fields'=>array_slice(array_keys($registered),0,120),
                'assigned_terms'=>$terms,
                'term_meta_source'=>'registered_meta_and_current_taxonomy_only'];
        }
        $boxes=(array)get_option('jet_engine_meta_boxes',[]);
        $definitions=[];$box_count=0;
        foreach($boxes as $key=>$box){
            if(!is_array($box)||++$box_count>$max_boxes)break;
            $args=(array)($box['args']??[]);
            $scope=(string)($args['object_type']??'post');
            $post_types=array_values(array_filter(array_map('strval',(array)($args['allowed_post_type']??[])),'strlen'));
            $taxonomies=array_values(array_filter(array_map('strval',(array)($args['allowed_tax']??[])),'strlen'));
            $applicable=$scope==='post'&&(!$post_types||in_array($post->post_type,$post_types,true));
            $related_tax=$scope==='tax'&&((!$taxonomies && $taxonomy_defs) ||
                count(array_intersect($taxonomies,array_keys($taxonomy_defs)))>0);
            $declared=[];
            foreach(array_slice((array)($box['meta_fields']??[]),0,160) as $entry){
                if(!is_array($entry))continue;
                $name=$entry['name']??'';
                if(!is_string($name)||!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{1,90}$/D',$name))continue;
                $kind=(string)($entry['type']??'unknown');
                $declared[$name]=['type'=>$kind,'schema_sha256'=>hash('sha256',wp_json_encode($entry))];
                if($applicable)$add($name,'jetengine_option:'.(string)$key,$kind);
            }
            ksort($declared,SORT_STRING);
            $definitions[]=[
                'id'=>(string)$key,'name'=>(string)($args['name']??''),
                'object_type'=>$scope,'post_types'=>$post_types,
                'taxonomies'=>$taxonomies,'applies_to_post_type'=>$applicable,
                'applies_to_related_taxonomy'=>$related_tax,
                'relevant_to_consumers'=>$applicable||$related_tax,
                'fields'=>$declared,'definition_sha256'=>hash('sha256',wp_json_encode($box))
            ];
        }
        foreach(array_slice(array_keys((array)get_post_meta($id)),0,160) as $name)
            $add($name,'observed_post_meta');
        if(class_exists(__CLASS__)){
            foreach(self::registry($post->post_type) as $name=>$rule)
                $add($name,'field_fusion_registry',(string)($rule['value_type']??'unknown'));
        }
        foreach($catalog as $name=>&$spec){
            $spec['origins']=array_keys($spec['origins']);
            sort($spec['origins'],SORT_STRING);
            $spec['present_on_post']=metadata_exists('post',$id,$name);
            $spec['consumer_evidence']='NOT_YET_INSPECTED';
        }
        unset($spec); ksort($catalog,SORT_STRING);
        $scan_fields=$field_names?array_keys($field_names):array_keys($catalog);
        $scan_fields=array_slice($scan_fields,0,100);
        $consumer_ids=[$id=>$id];
        foreach((array)($options['consumer_ids']??[]) as $cid){
            $cid=absint($cid); if($cid && count($consumer_ids)<60)$consumer_ids[$cid]=$cid;
        }
        $discovery_requested=!empty($options['discover_consumers']) && (bool)$field_names;
        if($discovery_requested){
            global $wpdb;
            $like=[];
            foreach(array_slice(array_keys($field_names),0,6) as $f)
                $like[]=$wpdb->prepare('meta_value LIKE %s','%'.$wpdb->esc_like($f).'%');
            if($like){
                $sql="SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data' AND (".implode(' OR ',$like).") LIMIT 60";
                foreach((array)$wpdb->get_col($sql) as $cid){
                    $cid=absint($cid); if($cid&&count($consumer_ids)<60)$consumer_ids[$cid]=$cid;
                }
            }
        }
        $consumers=[]; $widgets_count=0; $truncated=false; $style_assets=[]; $visibility_only_count=0;
        foreach($consumer_ids as $cid){
            $consumer=get_post($cid);if(!$consumer)continue;
            $raw=get_post_meta($cid,'_elementor_data',true);
            $els=is_string($raw)&&strlen($raw)<1500000?json_decode($raw,true):null;
            if(is_string($raw)&&strlen($raw)>=1500000)$truncated=true;
            $asset=WP_CONTENT_DIR.'/uploads/elementor/css/post-'.$cid.'.css';
            $exists=is_file($asset);
            $style_assets[]=[
                'post_id'=>$cid,'generated_css_exists'=>$exists,
                'generated_css_sha256'=>$exists&&filesize($asset)<3000000?hash_file('sha256',$asset):null,
                'regeneration_required_if_source_settings_change'=>true,
            ];
            if(!is_array($els))continue;
            $walk=function($nodes,$parent_conditions=[]) use (&$walk,$cid,&$consumers,&$widgets_count,&$truncated,$scan_fields,$field_names,&$visibility_only_count){
                foreach((array)$nodes as $node){
                    if(!is_array($node))continue;
                    if(++$widgets_count>1800){$truncated=true;return;}
                    $settings=(array)($node['settings']??[]);
                    $encoded=wp_json_encode($settings,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                    if(!is_string($encoded))$encoded='';
                    $refs=[];
                    foreach($scan_fields as $name)
                        if(strpos($encoded,$name)!==false)$refs[]=$name;
                    $has_cond=array_key_exists('jedv_enabled',$settings) ||
                        array_key_exists('jedv_conditions',$settings);
                    $conditions=(array)($settings['jedv_conditions']??[]);
                    $condition_enabled=in_array(strtolower((string)($settings['jedv_enabled']??'')),['yes','1','true','on'],true);
                    $visible_only=!$field_names && $has_cond &&
                        (count($conditions)>0||$condition_enabled) &&
                        $visibility_only_count<25;
                    if($refs||$visible_only){
                        if(!$refs)$visibility_only_count++;
                        $styles=[];$breakpoints=[];$interactions=[];
                        foreach(array_keys($settings) as $k){
                            if(!is_string($k))continue;
                            if(preg_match('/(?:color|font|typography|padding|margin|background|border|shadow|width|height|gap|align|z_index|position|overflow|css)/i',$k))$styles[]=$k;
                            if(preg_match('/_(?:tablet|mobile|widescreen|laptop|tablet_extra)$/',$k))$breakpoints[]=$k;
                            if(preg_match('/(?:click|link|url|popup|action|trigger|anchor|toggle|share)/i',$k))$interactions[]=$k;
                        }
                        $visibility=$has_cond?'CONDITIONS_REQUIRE_NATIVE_EVALUATION':'NO_JEDV_RULE_DETECTED';
                        $consumers[]=[
                            'document_id'=>$cid,'widget_id'=>(string)($node['id']??''),
                            'widget_type'=>(string)($node['widgetType']??$node['elType']??''),
                            'field_references'=>$refs,
                            'reference_detection'=>'authored_settings_text_match_not_runtime_proof',
                            'visibility_status'=>$visibility,
                            'jedv_enabled'=>$settings['jedv_enabled']??null,
                            'condition_count'=>count($conditions),
                            'conditions_sha256'=>hash('sha256',wp_json_encode($conditions)),
                            'ancestor_visibility_condition_count'=>count($parent_conditions),
                            'ancestor_visibility_sha256'=>hash('sha256',wp_json_encode($parent_conditions)),
                            'style_setting_keys'=>array_slice($styles,0,40),
                            'responsive_setting_keys'=>array_slice($breakpoints,0,30),
                            'interaction_setting_keys'=>array_slice($interactions,0,20),
                            'authored_style_sha256'=>hash('sha256',wp_json_encode(array_intersect_key($settings,array_flip($styles)))),
                            'query_reference_keys'=>array_values(array_intersect(
                                ['query_id','custom_query_id','jet_engine_query_id','listing_id'],array_keys($settings))),
                            'computed_style_status'=>'BROWSER_READBACK_REQUIRED',
                            'interaction_status'=>'BROWSER_READBACK_REQUIRED',
                            'query_result_status'=>'NATIVE_QUERY_READBACK_REQUIRED'
                        ];
                        if(count($consumers)>=180){$truncated=true;return;}
                    }
                    if(!empty($node['elements'])){
                        $inherited=$parent_conditions;
                        if($has_cond && ($conditions||$condition_enabled))
                            $inherited[]=['id'=>(string)($node['id']??''),
                                'enabled'=>$condition_enabled,
                                'conditions_sha256'=>hash('sha256',wp_json_encode($conditions))];
                        $walk($node['elements'],array_slice($inherited,-12));
                        if($truncated)return;
                    }
                }
            };
            $walk($els);
            if($truncated)break;
        }
        $language=['wpml_installed'=>defined('ICL_SITEPRESS_VERSION'),'language'=>'','trid'=>0,
            'term_translation_readback'=>'NATIVE_WPML_READBACK_REQUIRED'];
        if(has_filter('wpml_post_language_details')){
            $details=apply_filters('wpml_post_language_details',null,$id);
            if(is_array($details)){
                $language['language']=(string)($details['language_code']??'');
                $language['trid']=absint($details['trid']??0);
            }
        }
        $term_links=[];
        foreach($catalog as $field=>$spec){
            if(!preg_match('/(?:term_id|taxonomy|_term)$/i',$field)||!$spec['present_on_post'])continue;
            $value=get_post_meta($id,$field,true);
            $tid=is_scalar($value)?absint($value):0;
            if(!$tid)continue;
            $term=get_term($tid);
            $term_links[]=['field'=>$field,'term_id'=>$tid,
                'resolved'=>!is_wp_error($term)&&$term instanceof WP_Term,
                'taxonomy'=>$term instanceof WP_Term?$term->taxonomy:'',
                'translated_term_readback'=>'REQUIRED'];
            if(count($term_links)>=25)break;
        }
        // Fluent Forms consumer declarations: inspect form *definitions*, never submissions.
        $fluent_refs=[];
        if($field_names){
            global $wpdb;
            $table=$wpdb->prefix.'fluentform_forms';
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table){
                $forms=$wpdb->get_results("SELECT id,form_fields FROM {$table} ORDER BY id DESC LIMIT 80",ARRAY_A);
                foreach((array)$forms as $form){
                    $raw=(string)($form['form_fields']??'');
                    if(strlen($raw)>750000)continue;
                    $refs=[];
                    foreach(array_keys($field_names) as $field)
                        if(strpos($raw,$field)!==false)$refs[]=$field;
                    if($refs)$fluent_refs[]=[
                        'form_id'=>(int)$form['id'],'field_references'=>$refs,
                        'config_sha256'=>hash('sha256',$raw),
                        'visibility_state'=>'FORM_RUNTIME_READBACK_REQUIRED',
                        'interaction_state'=>'FORM_RUNTIME_READBACK_REQUIRED'];
                }
            }
        }
        // Reuse MAD4B's original WPML design-reconciliation owner. The family
        // readback enumerates all configured/active languages, including missing
        // translations. Source-only graph success is never a whole-site PASS.
        $wpml_family=class_exists('MAD4B_WPML_Design_Reconciliation_V1')
            ?MAD4B_WPML_Design_Reconciliation_V1::family_coverage_readback($id)
            :['contract'=>'mad4b.wpml.family-coverage.v1',
               'status'=>'NATIVE_WPML_OWNER_UNAVAILABLE',
               'structural_readback_pass'=>false,
               'business_pass_allowed'=>false,'mutation_performed'=>false];
        $wpml_manifest=class_exists('MAD4B_WPML_Design_Reconciliation_V1')
            ?MAD4B_WPML_Design_Reconciliation_V1::dependency_family_manifest($id,12)
            :['contract'=>'mad4b.wpml.dependency-family-manifest.v1',
               'summary'=>['unmet_locale_instances'=>-1,'scope_truncated'=>true,
                   'all_families_structurally_ready'=>false],
               'overall_reconciliation_pass_allowed'=>false,'mutation_performed'=>false];
        $data=[
            'contract'=>'mad4b.field-fusion.semantic-dependency-graph.v1',
            'source_site'=>rtrim(home_url(),'/'),'source_post_id'=>$id,
            'post_type'=>$post->post_type,'post_status'=>$post->post_status,
            'schema'=>['jetengine_option_boxes_total'=>count($boxes),
                'jetengine_definitions'=>$definitions,'fields'=>$catalog,
                'taxonomy_definitions'=>$taxonomy_defs],
            'consumers'=>$consumers,'fluent_forms_consumers'=>$fluent_refs,
            'shortcode_dependencies'=>self::semantic_shortcode_graph(array_values($consumer_ids),$options),
            'style_assets'=>$style_assets,
            'style_ownership'=>[
                'native_css_owner'=>'MAD4B_Elementor_CSS_Native_Reconcile_CLI',
                'asset_owner'=>'MAD4B_SCP_Adaptive_Asset_Intelligence',
                'asset_owner_available'=>class_exists('MAD4B_SCP_Adaptive_Asset_Intelligence'),
                'css_mode'=>get_option('elementor_css_print_method','external'),
                'kit_id'=>absint(get_option('elementor_active_kit')),
                'kit_source_sha256'=>hash('sha256',wp_json_encode(
                    get_post_meta(absint(get_option('elementor_active_kit')),'_elementor_page_settings',true))),
                'copy_generated_css'=>false,
                'invalidate'=>'native_target_selective_after_approved_apply',
                'rtl_and_responsive'=>'BROWSER_COMPUTED_STYLE_REQUIRED'
            ],
            'language'=>$language,
            'wpml_family_coverage'=>$wpml_family,
            'wpml_dependency_manifest'=>$wpml_manifest,
            'term_dependencies'=>$term_links,
            'coverage'=>[
                'inspected_consumer_documents'=>count($style_assets),
                'widgets_examined'=>min($widgets_count,1800),
                'field_filter_count'=>count($scan_fields),
                'discovery_requested'=>$discovery_requested,
                'invalid_field_names_ignored'=>$invalid,
                'truncated'=>$truncated,'runtime_conditions_evaluated'=>false,
                'query_results_evaluated'=>false,'computed_style_evaluated'=>false,
                'dom_interactions_tested'=>false,
                'required_wpml_languages'=>(array)($wpml_family['required_languages']??[]),
                'wpml_family_structural_pass'=>(bool)($wpml_family['structural_readback_pass']??false),
                'wpml_unmet_locale_count'=>(int)($wpml_family['summary']['unmet_language_count']??-1),
                'wpml_nested_family_count'=>(int)($wpml_manifest['family_count']??0),
                'wpml_nested_unmet_instances'=>(int)($wpml_manifest['summary']['unmet_locale_instances']??-1),
                'wpml_nested_scope_truncated'=>(bool)($wpml_manifest['summary']['scope_truncated']??true)
            ],
            'required_readback'=>['visibility_native','query_results','computed_css_all_breakpoints',
                'dom_interaction','wpml_all_required_language_family',
                'wpml_term_translations','cache_variant'],
            'automatic_apply_eligible'=>false,'mutation_performed'=>false
        ];
        $data['snapshot_sha256']=hash('sha256',wp_json_encode($data,JSON_UNESCAPED_SLASHES));
        return $data;
    }


    /**
     * Existing Field Fusion's provider-neutral Shortcode -> PHP owner -> JS/CSS
     * discovery. Static source candidates are not execution proofs. Does not
     * execute callbacks, shortcode bodies, Ajax, REST or mutate content.
     */
    public static function semantic_shortcode_graph(array $document_ids,array $options=[]) {
        global $shortcode_tags;
        $registry=(array)$shortcode_tags;
        $max_docs=60; $max_occurrences=200;
        $selected=[]; $hits=[]; $discarded=0; $truncated=false;
        $nested_edges=[];$query_dependencies=[];$visited=[];$root_ids=[];
        $docIds=[];
        foreach($document_ids as $v){
            $v=absint($v);
            if($v&&count($docIds)<$max_docs)$docIds[$v]=$v;
        }
        $parse=function($raw,$id,$origin) use (&$selected,&$hits,&$discarded,&$truncated,$max_occurrences) {
            if(!is_string($raw)||$raw==='')return;
            if(strlen($raw)>5500000){$truncated=true;return;}
            // Detect unknown tags as well: get_shortcode_regex() only finds tags
            // already registered and would hide target missing implementations.
            if(!preg_match_all('/(?<!\\[)\\[([a-zA-Z][a-zA-Z0-9_-]{1,78})(?=[\\s\\]\\/])([^\\]\\r\\n]{0,450})\\]/',$raw,$matches,PREG_SET_ORDER))
                return;
            foreach($matches as $m){
                $tag=strtolower((string)$m[1]);
                // Avoid interpreting markdown and CSS attribute selectors as proof.
                if(in_array($tag,['http','https','data','class','id','aria','button','script','style','object','array','a','div','img'],true)){
                    $discarded++; continue;
                }
                $isRegistered=isset($GLOBALS['shortcode_tags'][$tag]);
                $explicit=str_starts_with($origin,'elementor:shortcode:shortcode');
                if(!isset($selected[$tag]))$selected[$tag]=[
                    'registered'=>$isRegistered,'used_in'=>[],
                    'explicit_shortcode_widget'=>false,
                    'unregistered_token_may_be_a_placeholder'=>false];
                if($explicit)$selected[$tag]['explicit_shortcode_widget']=true;
                if(!$isRegistered&&!$explicit)
                    $selected[$tag]['unregistered_token_may_be_a_placeholder']=true;
                if(count($hits)>=$max_occurrences){$truncated=true;break;}
                $attribute=(string)($m[2]??'');
                $atts=shortcode_parse_atts(trim($attribute));
                $keys=[];
                if(is_array($atts))foreach($atts as $key=>$value){
                    if(is_string($key)&&preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,60}$/D',$key))
                        $keys[]=$key;
                }
                $sample=['document_id'=>(int)$id,'source'=>$origin,
                    'attributes'=>array_slice(array_values(array_unique($keys)),0,20),
                    'tag'=>$tag,'registered_at_snapshot'=>$isRegistered,
                    'usage_confidence'=>$explicit?'ELEMENTOR_SHORTCODE_WIDGET':
                        ($isRegistered?'REGISTERED_WORDPRESS_SHORTCODE':
                        'UNREGISTERED_TOKEN_MAY_BE_PLACEHOLDER')];
                $hits[]=$sample;
                if(count($selected[$tag]['used_in'])<12)$selected[$tag]['used_in'][]=[
                    'document_id'=>(int)$id,'source'=>$origin];
            }
        };
        // Traverse both Elementor template refs and JetEngine Listing
        // documents, bounded with explicit cycle/missing-target evidence.
        $queue=[];
        foreach($docIds as $id){
            $root_ids[]=$id;
            $queue[]=['id'=>$id,'depth'=>0,'ancestry'=>[$id],'source'=>'root'];
        }
        $max_depth=6;
        while($queue){
            $task=array_shift($queue);
            $id=(int)$task['id'];
            if(isset($visited[$id]))continue;
            if(count($visited)>=$max_docs){$truncated=true;break;}
            $visited[$id]=['depth'=>$task['depth'],'source'=>$task['source']];
            $post=get_post($id);
            if(!$post)continue;
            $attach=function($target,$kind,$element_id='',$dom_id='') use (&$queue,&$nested_edges,&$visited,
                &$truncated,$task,$id,$max_depth,$max_docs){
                $target=absint($target);
                if(!$target)return;
                $linked=get_post($target);
                $is_supported=$linked&&in_array($linked->post_type,
                    ['elementor_library','jet-engine','page','post','tours-and-activities'],true)
                    &&!in_array($linked->post_status,['trash','auto-draft'],true);
                $cycle=in_array($target,$task['ancestry'],true);
                $is_duplicate=isset($visited[$target]);
                $depth=(int)$task['depth']+1;
                $status=!$is_supported?'MISSING_OR_INACTIVE_TARGET':
                    ($cycle?'CYCLE_DETECTED':($depth>$max_depth?'DEPTH_LIMIT':
                        ($is_duplicate?'ALREADY_INSPECTED':'LINKED_DOCUMENT')));
                $edge=['from_document_id'=>$id,'to_document_id'=>$target,
                    'kind'=>$kind,'widget_id'=>$element_id,'widget_dom_id'=>$dom_id,
                    'depth'=>$depth,'status'=>$status,
                    'target_post_type'=>$linked?$linked->post_type:'',
                    'target_post_status'=>$linked?$linked->post_status:'',
                    'wpml_translation_check'=>'NATIVE_LANGUAGE_READBACK_REQUIRED'];
                if($linked&&has_filter('wpml_post_language_details')){
                    $details=apply_filters('wpml_post_language_details',null,$target);
                    if(is_array($details)){
                        $edge['target_language']=(string)($details['language_code']??'');
                        $edge['target_trid']=absint($details['trid']??0);
                    }
                }
                if(count($nested_edges)<240)$nested_edges[]=$edge;
                else $truncated=true;
                if($status==='LINKED_DOCUMENT'){
                    if(count($queue)+count($visited)>=$max_docs)$truncated=true;
                    else $queue[]=['id'=>$target,'depth'=>$depth,
                        'ancestry'=>array_merge($task['ancestry'],[$target]),
                        'source'=>$kind];
                }elseif($status==='DEPTH_LIMIT')$truncated=true;
            };
            // An Elementor template shortcode can appear in post_content or
            // text widgets and must resolve recursively rather than as a tag only.
            $link_shortcode_templates=function($raw,$origin)use($attach){
                if(!is_string($raw)||strlen($raw)>5500000)return;
                if(preg_match_all('/\[(?:elementor-template|elementor_template)\s+[^\]\r\n]{0,240}?\bid\s*=\s*["\x27]?(\d{1,9})/i',$raw,$m))
                    foreach(array_slice($m[1],0,40) as $tid)
                        $attach($tid,'elementor_shortcode_template',$origin);
            };
            $link_shortcode_templates($post->post_content,'post_content');
            $parse($post->post_content,$id,'post_content');
            $parse($post->post_content,$id,'post_content');
            $parse($post->post_excerpt,$id,'post_excerpt');
            $link_shortcode_templates($post->post_excerpt,'post_excerpt');
            $data=get_post_meta($id,'_elementor_data',true);
            if(!is_string($data)||strlen($data)>=2000000){
                if(is_string($data)&&strlen($data)>=2000000)$truncated=true;
                continue;
            }
            $elements=json_decode($data,true);
            if(!is_array($elements))continue;
            $walk=function($nodes)use(&$walk,$parse,$id,&$truncated,$attach,
                $link_shortcode_templates,&$query_dependencies){
                foreach((array)$nodes as $element){
                    if(!is_array($element))continue;
                    $widget=(string)($element['widgetType']??$element['elType']??'');
                    $settings=(array)($element['settings']??[]);
                    $element_id=(string)($element['id']??'');
                    $tkeys=['template_id','templateID','global_widget_id'];
                    $lkeys=['listing_id','lisitng_id','listing_grid_id'];
                    foreach($tkeys as $key){
                        if(isset($settings[$key]) && is_scalar($settings[$key]) &&
                            ctype_digit((string)$settings[$key]))
                            $attach($settings[$key],'elementor_template:'.$key,$element_id);
                    }
                    // Some Listing Grid widgets use 'lisitng_id' (original
                    // JetEngine spelling), do not normalize a source field.
                    foreach($lkeys as $key){
                        if(isset($settings[$key]) && is_scalar($settings[$key]) &&
                            ctype_digit((string)$settings[$key]))
                            $attach($settings[$key],'jetengine_listing:'.$key,$element_id,
                                (string)($settings['_element_id']??''));
                    }
                    foreach(['custom_query_id','query_id','jet_engine_query_id'] as $key){
                        if(!isset($settings[$key])||!is_scalar($settings[$key]) ||
                            !ctype_digit((string)$settings[$key]) ||
                            count($query_dependencies)>=150)continue;
                        $query_dependencies[]=[
                            'document_id'=>$id,'widget_id'=>$element_id,
                            'query_id'=>(int)$settings[$key],
                            'query_settings_key'=>$key,
                            'query_registered_in_native_owner'=>'NOT_EVALUATED',
                            'query_result_status'=>'NATIVE_QUERY_READBACK_REQUIRED'
                        ];
                    }
                    foreach($settings as $k=>$value){
                        if(!is_string($k)||!preg_match('/(?:shortcode|html|editor|text|content|description|code|template)/i',$k))
                            continue;
                        // Nested settings/repeaters may contain literal shortcodes.
                        $stack=[$value];$visited=0;
                        while($stack && ++$visited<100){
                            $v=array_pop($stack);
                            if(is_string($v)){
                                $parse($v,$id,'elementor:'.$widget.':'.$k);
                                if($widget==='shortcode'||$widget==='text-editor')
                                    $link_shortcode_templates($v,$element_id);
                            }
                            elseif(is_array($v))foreach(array_slice($v,0,70) as $item)
                                if(is_scalar($item)||is_array($item))$stack[]=$item;
                        }
                    }
                    if(!empty($element['elements']))$walk($element['elements']);
                    if($truncated)return;
                }
            };
            $walk($elements);
        }
        // JetEngine owns query resolution and Listing Grid contracts.
        // Resolve definitions, never execute a query or change authored widgets.
        $native_queries=[];
        $queryOwner=null;
        if(class_exists('\Jet_Engine\Query_Builder\Manager'))
            $queryOwner=\Jet_Engine\Query_Builder\Manager::instance();
        foreach($query_dependencies as $queryRef){
            $qid=absint($queryRef['query_id']??0);
            if(!$qid||isset($native_queries[$qid]))continue;
            $result=['query_id'=>$qid,'status'=>'QUERY_OWNER_UNAVAILABLE',
                'query_type'=>'','name'=>'','expected_source'=>'',
                'expected_object_type'=>'','query_results_evaluated'=>false];
            if($queryOwner){
                try{
                    $query=$queryOwner->get_query_by_id($qid);
                    if(is_object($query)){
                        $type=(string)($query->query_type??'');
                        $opts=(array)($query->query??[]);
                        $refValue=$type==='terms'?($opts['taxonomy']??[]):($opts['post_type']??[]);
                        $scope=array_values(array_filter(array_map('sanitize_key',
                            (array)$refValue)));
                        $result['status']='REGISTERED_QUERY';
                        $result['query_type']=$type;
                        $result['name']=(string)($query->name??'');
                        $result['expected_source']=$type;
                        $result['expected_object_type']=count($scope)===1?$scope[0]:'';
                        $result['scopes']=$scope;
                    }else $result['status']='MISSING_QUERY';
                }catch(Throwable $e){
                    $result['status']='NATIVE_QUERY_LOOKUP_FAILED';
                }
            }
            $native_queries[$qid]=$result;
            if(count($native_queries)>=120){$truncated=true;break;}
        }
        $refsByWidget=[];
        foreach($query_dependencies as $qref){
            $key=(int)$qref['document_id'].'|'.(string)$qref['widget_id'];
            $refsByWidget[$key]=absint($qref['query_id']);
        }
        $listing_checks=[];
        foreach($nested_edges as $edge){
            if(!str_starts_with((string)$edge['kind'],'jetengine_listing:'))continue;
            $refId=(int)$edge['to_document_id'];
            $post=get_post($refId);
            $valid=$post&&$post->post_type==='jet-engine'&&
                $post->post_status==='publish';
            $cfg=$valid?(array)get_post_meta($refId,'_listing_data',true):[];
            $qid=$refsByWidget[(int)$edge['from_document_id'].'|'.(string)$edge['widget_id']]??0;
            $query=$native_queries[$qid]??[];
            $type=(string)($query['query_type']??'');
            $object=(string)($query['expected_object_type']??'');
            $matchesQuery=$valid && (!$type || $type===(string)($cfg['source']??''))
                && (!$object || $object===(string)($cfg[$type==='terms'?'tax':'post_type']??''));
            $listing_checks[]=[
                'from_document_id'=>(int)$edge['from_document_id'],
                'widget_id'=>(string)$edge['widget_id'],
                'widget_dom_id'=>(string)($edge['widget_dom_id']??''),
                'listing_id'=>$refId,'listing_valid'=>(bool)$valid,
                'listing_post_type'=>$post?(string)$post->post_type:'',
                'native_query_id'=>$qid,
                'query_exists'=>($query['status']??'')==='REGISTERED_QUERY',
                'native_query_type'=>$type,
                'native_query_title'=>(string)($query['name']??''),
                'source_type_matches_query'=>(bool)$matchesQuery,
                'compatible_sibling_listing_id'=>null,
                'repair_evidence'=>'NOT_VERIFIED',
                'automatic_repair_allowed'=>false,
                'frontend_verified'=>false
            ];
        }
        // A valid sibling with the same query AND explicit DOM identity is
        // stronger evidence than guessing from title/recency or source fields.
        foreach($listing_checks as &$candidate){
            if($candidate['listing_valid']&&$candidate['source_type_matches_query']){
                $candidate['repair_evidence']='ALREADY_VALID';continue;
            }
            $match=[];
            foreach($listing_checks as $sibling){
                if(!$sibling['listing_valid']||
                    !$sibling['source_type_matches_query']||
                    $sibling['from_document_id']!==$candidate['from_document_id']||
                    $sibling['native_query_id']!==$candidate['native_query_id']||
                    $sibling['native_query_id']<=0||
                    $sibling['widget_id']===$candidate['widget_id']||
                    $sibling['widget_dom_id']===''||
                    $sibling['widget_dom_id']!==$candidate['widget_dom_id'])continue;
                $match[$sibling['listing_id']]=$sibling['listing_id'];
            }
            if(count($match)===1){
                $candidate['compatible_sibling_listing_id']=(int)reset($match);
                $candidate['repair_evidence']='UNIQUE_SAME_DOCUMENT_QUERY_AND_DOM_ID';
            }else{
                $candidate['repair_evidence']=count($match)>1
                    ?'MULTIPLE_SIBLING_CANDIDATES':'NO_PROVEN_SIBLING';
            }
        }
        unset($candidate);
        $invalid_listings=array_values(array_filter($listing_checks,
            static fn($v)=>!$v['listing_valid']||!$v['source_type_matches_query']||
                !$v['query_exists']));
        ksort($selected,SORT_STRING);
        $owners=[];$assets=[];$registrations=0;$missing=0;$unknown_tokens=0;
        $scripts=function_exists('wp_scripts')?wp_scripts():null;
        $styles=function_exists('wp_styles')?wp_styles():null;
        $plugin_dir=wp_normalize_path(WP_PLUGIN_DIR.'/');
        $content_dir=wp_normalize_path(WP_CONTENT_DIR.'/');
        foreach(array_slice(array_keys($selected),0,120) as $tag){
            $callback=$registry[$tag]??null;
            if(!$callback||!is_callable($callback)){
                $confirmed=(bool)($selected[$tag]['explicit_shortcode_widget']??false);
                if($confirmed)$missing++;
                else $unknown_tokens++;
                $owners[$tag]=['registered'=>false,'callback_callable'=>false,
                    'state'=>$confirmed?'SHORTCODE_CALLBACK_UNAVAILABLE':
                        'AMBIGUOUS_UNREGISTERED_TOKEN_MAY_BE_PLACEHOLDER',
                    'assets'=>'UNKNOWN'];
                continue;
            }
            $registrations++;
            $file='';$start=0;$end=0;$name='';
            try{
                $ref=is_array($callback)
                    ?new ReflectionMethod($callback[0],$callback[1])
                    :new ReflectionFunction($callback);
                $file=(string)$ref->getFileName();$start=(int)$ref->getStartLine();
                $end=(int)$ref->getEndLine();$name=(string)$ref->getName();
            }catch(Throwable $e){$name='UNREFLECTABLE_CALLBACK';}
            $normal=wp_normalize_path($file);
            $safe_file=($normal!==''&&str_starts_with($normal,$content_dir));
            $relative=$safe_file?substr($normal,strlen($content_dir)):'wordpress_core_or_external';
            $owner=['registered'=>true,'callback_callable'=>true,
                'callback_file'=>$relative,'callback_method'=>$name,
                'callback_line'=>$start,'source_sha256'=>null,
                'asset_discovery'=>'STATIC_CANDIDATES_NOT_EXECUTION_PROOF',
                'assets'=>[],'runtime_render'=>'NOT_EVALUATED',
                'visibility'=>'NATIVE_CONDITIONS_AND_CONTEXT_REQUIRED'];
            if(!$safe_file||!is_file($normal)||filesize($normal)>1250000){
                $owner['asset_discovery']='SOURCE_FILE_UNAVAILABLE_OR_OUT_OF_SCOPE';
                $owners[$tag]=$owner;continue;
            }
            $php=file_get_contents($normal);
            if(!is_string($php)){$owners[$tag]=$owner;continue;}
            $owner['source_sha256']=hash('sha256',$php);
            $candidate=[];
            foreach(['js'=>'script','css'=>'style'] as $type=>$wp_kind){
                $regex='/\\bwp_(?:enqueue|register)_'.$wp_kind.'\\s*\\(\\s*([\\x27\\x22])([a-zA-Z0-9_.-]{2,99})\\1/is';
                if(preg_match_all($regex,$php,$found,PREG_SET_ORDER)){
                    foreach(array_slice($found,0,35) as $v){
                        $handle=$v[2];
                        $candidate[$type.':'.$handle]=['type'=>$type,'handle'=>$handle];
                    }
                }
            }
            // Dependency may be in sibling JS files, not the Shortcode method.
            $root='';
            if(str_starts_with($normal,$plugin_dir)){
                $rest=substr($normal,strlen($plugin_dir));
                $root=$plugin_dir.explode('/',$rest,2)[0].'/';
            }
            $linked_files=[];
            if($root&&preg_match_all('~[\\x27\\x22]((?:assets|modules|legacy-mu-tree)/[a-zA-Z0-9_./-]{1,200}\\.(?:js|css))[\\x27\\x22]~',
                    $php,$paths)){
                foreach(array_unique($paths[1]) as $path){
                    $candidate_path=$root.$path;
                    if(is_file($candidate_path)&&filesize($candidate_path)<500000){
                        $asset_text=file_get_contents($candidate_path);
                        $events=[];
                        if(is_string($asset_text)&&str_ends_with($path,'.js') &&
                            preg_match_all('/(?:addEventListener|CustomEvent)\\s*\\(\\s*([\\x27\\x22])([a-zA-Z0-9:._-]{2,100})\\1/',
                                $asset_text,$event_matches))
                            $events=array_values(array_unique(array_slice($event_matches[2],0,30)));
                        $linked_files[$path]=['path'=>substr($candidate_path,strlen($content_dir)),
                            'sha256'=>hash_file('sha256',$candidate_path),
                            'bytes'=>filesize($candidate_path),
                            'javascript_dom_event_names'=>$events,
                            'event_execution_proven'=>false];
                    }
                    if(count($linked_files)>=18)break;
                }
            }
            foreach($candidate as $value){
                $handle=$value['handle'];
                $is_js=$value['type']==='js';
                $registered=$is_js?($scripts->registered[$handle]??null)
                    :($styles->registered[$handle]??null);
                $queued=$is_js?in_array($handle,(array)($scripts->queue??[]),true)
                    :in_array($handle,(array)($styles->queue??[]),true);
                // PHP source-level dependency order is a candidate even when
                // WordPress has not fired wp_enqueue_scripts in CLI context.
                $declared=[];$inlineRegistration=false;
                if($is_js){
                    $callPattern='/\bwp_(?:enqueue|register)_script\s*\(\s*([\x27\x22])'.preg_quote($handle,'/').'\1\s*,(.{0,1300}?)(?:\)\s*;)/s';
                    if(preg_match($callPattern,$php,$call)){
                        if(preg_match('/\[\s*((?:[\x27\x22][a-zA-Z0-9_.-]{2,100}[\x27\x22]\s*,?\s*){1,20})\]/',$call[2],$deps) &&
                            preg_match_all('/[\x27\x22]([a-zA-Z0-9_.-]{2,100})[\x27\x22]/',$deps[1],$mm))
                            $declared=array_values(array_unique($mm[1]));
                    }
                    $inlineRegistration=(bool)preg_match('/\bwp_(?:add_inline_script|localize_script)\s*\(\s*([\x27\x22])'.preg_quote($handle,'/').'\1/',$php);
                }
                $owner['assets'][]=[
                    'handle'=>$handle,'type'=>$value['type'],
                    'source_declared_dependency_handles'=>$declared,
                    'source_inlined_or_localized_js'=>$inlineRegistration,
                    'static_dependency_evidence_only'=>true,
                    'registered_at_cli_snapshot'=>$registered!==null,
                    'queued_at_cli_snapshot'=>$queued,
                    'runtime_status'=>'BROWSER_REQUEST_AND_EXECUTION_READBACK_REQUIRED',
                    'declared_dependencies'=>is_object($registered)
                        ?array_slice((array)$registered->deps,0,20):[],
                    'observed_url'=>is_object($registered)
                        ?(string)$registered->src:'',
                    'inline_code_registered'=>is_object($registered)&&$is_js&&
                        !empty($registered->extra['after']),
                    'localized_data'=>'RUNTIME_READBACK_REQUIRED'
                ];
            }
            $owner['linked_files']=$linked_files;
            $inline_events=[];
            if(preg_match_all('~<script\b[^>]*>(.*?)</script>~si',$php,$inline_parts)){
                foreach(array_slice($inline_parts[1],0,12) as $body){
                    if(preg_match_all('/(?:addEventListener|CustomEvent)\s*\(\s*([\x27\x22])([a-zA-Z0-9:._-]{2,100})\1/',
                        $body,$event_names))
                        $inline_events=array_merge($inline_events,$event_names[2]);
                }
            }
            $owner['raw_inline_script_tag_present']=(bool)$inline_events;
            $owner['source_inline_dom_events']=array_values(array_unique(array_slice($inline_events,0,35)));
            $owner['has_wp_enqueue_scripts_hook']=strpos($php,'wp_enqueue_scripts')!==false;
            $owner['has_ajax_or_rest_symbols']=strpos($php,'wp_ajax_')!==false ||
                strpos($php,'register_rest_route')!==false;
            $owner['has_inline_script_symbols']=strpos($php,'wp_add_inline_script')!==false
                ||strpos($php,'wp_localize_script')!==false;
            $owners[$tag]=$owner;
        }
        return [
            'contract'=>'mad4b.field-fusion.shortcode-assets.v1',
            'requested_root_documents'=>array_values($docIds),
            'inspected_documents'=>array_map('intval',array_keys($visited)),
            'nested_document_edges'=>$nested_edges,
            'jetengine_query_dependencies'=>$query_dependencies,
            'native_query_registry'=>$native_queries,
            'listing_reference_validation'=>[
                'checked_count'=>count($listing_checks),
                'invalid_count'=>count($invalid_listings),
                'checks'=>$listing_checks,
                'block_reconciliation_if_invalid'=>count($invalid_listings)>0,
                'cannot_autocorrect_without_authoritative_evidence'=>true,
                'query_execution_proven'=>false,
                'mutation_performed'=>false
            ],
            'registered_shortcodes_in_global_registry'=>count($registry),
            'discovered_shortcode_tags'=>$selected,'occurrences'=>$hits,
            'callback_owners'=>$owners,'registered_used_tag_count'=>$registrations,
            'unregistered_or_uncallable_used_tag_count'=>$missing,
            'ambiguous_unregistered_token_count'=>$unknown_tokens,
            'css_js_candidates_are_static_only'=>true,
            'cannot_infer_enqueue_from_callback_registration'=>true,
            'deferred_or_delayed_js_may_break_interactions'=>true,
            'required_render_readback'=>[
                'shortcode_markup_or_approved_hidden_state','real_asset_request_2xx_js_mime',
                'dependency_order_and_defer_delay','inline_and_localization_payload',
                'dom_event_binding_and_delegation','ajax_rest_nonce_and_auth_context',
                'post_ajax_widget_rehydration','responsive_and_wpml_variants'
            ],
            'coverage'=>[
                'truncated'=>$truncated,'shortcode_occurences'=>count($hits),
                'requested_roots'=>count($root_ids),
                'visited_nested_documents'=>count($visited)-count(array_intersect(array_keys($visited),$root_ids)),
                'total_inspected_documents'=>count($visited),
                'nested_reference_edges'=>count($nested_edges),
                'query_references'=>count($query_dependencies),
                'max_depth'=>$max_depth,
                'query_results_evaluated'=>false,
                'discarded_ambiguous_patterns'=>$discarded],
            'mutation_performed'=>false
        ];
    }

    /** Separate observed differences from authority to apply them. */
    public static function semantic_compare(array $source,array $target) {
        $contract='mad4b.field-fusion.semantic-diff.v1';
        if(($source['contract']??'')!=='mad4b.field-fusion.semantic-dependency-graph.v1' ||
           ($target['contract']??'')!=='mad4b.field-fusion.semantic-dependency-graph.v1')
            return new WP_Error('fusion_semantic_contract_invalid','Unsupported snapshot contract');
        if(($source['post_type']??'')!==($target['post_type']??''))
            return new WP_Error('fusion_semantic_type_mismatch','Different content types');
        $a=(array)($source['schema']['fields']??[]);
        $b=(array)($target['schema']['fields']??[]);
        $missing=array_values(array_diff(array_keys($a),array_keys($b)));
        $target_only=array_values(array_diff(array_keys($b),array_keys($a)));
        $decl=[];
        foreach($a as $k=>$spec){
            if(!isset($b[$k]))continue;
            if(($spec['declared_type']??'')!==($b[$k]['declared_type']??''))
                $decl[]=['field'=>$k,'source_type'=>$spec['declared_type'],'target_type'=>$b[$k]['declared_type']];
        }
        $boxids=static function($snapshot) {
            $out=[];
            foreach((array)($snapshot['schema']['jetengine_definitions']??[]) as $box)
                $out[(string)($box['id']??'')]=(string)($box['definition_sha256']??'');
            return $out;
        };
        $sa=$boxids($source);$sb=$boxids($target);
        $box_missing=array_values(array_diff(array_keys($sa),array_keys($sb)));
        $relevant_missing=[];$other_scope_missing=[];
        foreach((array)($source['schema']['jetengine_definitions']??[]) as $box){
            $id=(string)($box['id']??'');
            if(!in_array($id,$box_missing,true))continue;
            if(!empty($box['relevant_to_consumers']))$relevant_missing[]=$id;
            else $other_scope_missing[]=$id;
        }
        $box_changed=[];
        foreach($sa as $k=>$hash)if(isset($sb[$k])&&$hash!==$sb[$k])$box_changed[]=$k;
        $source_tags=(array)($source['shortcode_dependencies']['discovered_shortcode_tags']??[]);
        $target_owner=(array)($target['shortcode_dependencies']['callback_owners']??[]);
        $shortcode_coverage=array_key_exists('shortcode_dependencies',$target);
        $missing_callbacks=[];
        if($shortcode_coverage){
            foreach($source_tags as $tag=>$ref){
                if(empty($ref['registered']) && empty($ref['explicit_shortcode_widget']))
                    continue; // Ambiguous tokens may be CRM placeholders.
                if(!($target_owner[$tag]['callback_callable']??false))
                    $missing_callbacks[]=$tag;
            }
        }
        $diff=[
            'contract'=>$contract,'source_post_type'=>$source['post_type'],
            'source_snapshot_sha256'=>$source['snapshot_sha256']??'',
            'target_snapshot_sha256'=>$target['snapshot_sha256']??'',
            'missing_schema_fields'=>$missing,'target_only_fields'=>$target_only,
            'field_type_mismatches'=>$decl,
            'type_mismatch_requires_actual_value_and_serializer_readback'=>true,
            'missing_jetengine_definitions'=>$box_missing,
            'missing_relevant_jetengine_definitions'=>$relevant_missing,
            'missing_other_scope_jetengine_definitions'=>$other_scope_missing,
            'changed_jetengine_definitions'=>$box_changed,
            'wpml_family_comparison'=>[
                'source_required_languages'=>(array)($source['wpml_family_coverage']['required_languages']??[]),
                'source_family_structural_pass'=>(bool)($source['wpml_family_coverage']['structural_readback_pass']??false),
                'source_unmet_locale_count'=>(int)($source['wpml_family_coverage']['summary']['unmet_language_count']??-1),
                'target_required_languages'=>(array)($target['wpml_family_coverage']['required_languages']??[]),
                'target_family_structural_pass'=>(bool)($target['wpml_family_coverage']['structural_readback_pass']??false),
                'target_family_readback_present'=>isset($target['wpml_family_coverage']),
                'cross_company_locale_policy_must_not_be_assumed_equal'=>true,
                'full_reconciliation_pass_allowed'=>false
            ],
            'wpml_nested_manifest_comparison'=>[
                'source_family_count'=>(int)($source['wpml_dependency_manifest']['family_count']??0),
                'source_unmet_locale_instances'=>(int)($source['wpml_dependency_manifest']['summary']['unmet_locale_instances']??-1),
                'source_truncated'=>(bool)($source['wpml_dependency_manifest']['summary']['scope_truncated']??true),
                'target_family_count'=>(int)($target['wpml_dependency_manifest']['family_count']??0),
                'target_unmet_locale_instances'=>(int)($target['wpml_dependency_manifest']['summary']['unmet_locale_instances']??-1),
                'target_snapshot_has_nested_manifest'=>isset($target['wpml_dependency_manifest']),
                'full_reconciliation_pass_allowed'=>false
            ],
            'shortcode_dependency_comparison'=>[
                'target_shortcode_registry_observed'=>$shortcode_coverage,
                'missing_used_shortcode_callbacks'=>$missing_callbacks,
                'shortcode_js_runtime_verified'=>false,
                'shortcode_css_runtime_verified'=>false,
                'strict_pass_requires_html_js_event_and_network_readback'=>true
            ],
            'source_jetengine_boxes'=>(int)($source['schema']['jetengine_option_boxes_total']??0),
            'target_jetengine_boxes'=>(int)($target['schema']['jetengine_option_boxes_total']??0),
            'next_actions'=>['RESOLVE_FIELD_OWNERSHIP_AND_COMPANY_OVERRIDES',
                'REMAP_CONSUMER_REFERENCES','VERIFY_VISIBILITY_AND_QUERY_RESULTS',
                'REGENERATE_OWNED_ELEMENTOR_CSS','BROWSER_RENDER_STYLE_INTERACTION_READBACK',
                'WPML_LANGUAGE_AND_TERM_READBACK',
                'SHORTCODE_CALLBACK_AND_JS_DEPENDENCY_READBACK',
                'AJAX_REHYDRATION_AND_EVENT_INTERACTION_READBACK',
                'SELECTIVE_CACHE_INVALIDATION'],
            'policy'=>['source_default_inherited_only_if_target_unset'=>true,
                'explicit_target_override_preserved'=>true,
                'never_copy_generated_css'=>true,
                'never_drop_target_only_fields'=>true],
            'comparison_coverage'=>[
                'source_truncated'=>(bool)($source['coverage']['truncated']??true),
                'target_truncated'=>(bool)($target['coverage']['truncated']??true),
                'target_schema_only'=>(bool)($target['coverage']['schema_only']??false),
                'missing_schema_fields_do_not_prove_missing_values'=>true
            ],
            'diff_observed'=>true,'safe_to_apply_automatically'=>false,
            'render_verified'=>false,'mutation_performed'=>false
        ];
        $diff['receipt_sha256']=hash('sha256',wp_json_encode($diff,JSON_UNESCAPED_SLASHES));
        return $diff;
    }

}
MAD4B_Hub_Field_Fusion_V1::boot();
