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
        $draft=[];$issues=[];
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
        return [
            'contract'=>self::CONTRACT,'company_id'=>$ownerA,'source_type'=>$typeA,
            'language'=>$langA,'record_ids'=>[$left,$right],
            'source_ids'=>[$sourceA,$sourceB],
            'field_policy_count'=>count($policies),'field_coverage'=>$coverage,
            'per_record_quality_score'=>$quality,
            'identity_conflict_fields'=>$blocks,
            'entity_resolution_state'=>$blocks?'identity_conflict_hold':'candidate_not_verified',
            'field_proposals'=>$rows,
            'canonical_draft'=>[
                'status'=>$blocks?'blocked_identity_conflict':'review_required',
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
}
MAD4B_Hub_Field_Fusion_V1::boot();
