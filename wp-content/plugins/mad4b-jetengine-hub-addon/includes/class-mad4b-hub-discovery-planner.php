<?php
/**
 * MAD4B: read-only provider capability discovery, selective Query Plan,
 * deny-wins policy inheritance, and deterministic mapping advice.
 *
 * NO content distribution, source requests, writes, LLM execution, form
 * submissions or price activation occur in this module.
 */
defined('ABSPATH') || exit;

final class MAD4B_Hub_Discovery_Planner {
    const CONTRACT = 'mad4b.hub.capability-selection.v1';
    const NAMESPACE_NAME = 'mad4b/v1';
    const MAX_IDS = 100;
    const MAX_FIELDS = 40;
    const MAX_PROVIDERS = 24;
    const LANGS = array('en','ar','fr','de','es','it');
    const DENIED_ACTIONS = array(
        'publish','write','delete','checkout','active_pricing',
        'export_submissions','copy_company_options','cross_tenant_media',
        'reconfigure_vendor','execute_remote','register_endpoint'
    );

    private static function err($code) {
        return new WP_Error('mad4b_discovery_'.$code,$code,array('status'=>422,'contract'=>self::CONTRACT));
    }
    private static function clean($s,$max=90) {
        return substr(sanitize_text_field((string)$s),0,$max);
    }
    private static function sensitive($key) {
        return (bool)preg_match('/(^|[_-])(password|secret|token|api[_-]?key|private|customer|email|phone|mobile|billing|payment|card|oauth|webhook)([_-]|$)/i',(string)$key);
    }
    private static function definition_fields($raw) {
        $fields=maybe_unserialize($raw);
        if(!is_array($fields))return array();
        $out=array();
        foreach($fields as $field) {
            if(!is_array($field))continue;
            $key=(string)($field['name']??'');
            $type=(string)($field['type']??'');
            if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,89}$/D',$key) ||
                self::sensitive($key))continue;
            $out[$key]=array('key'=>$key,'type'=>self::clean($type,50),
                'label'=>self::clean($field['title']??$key));
            if(count($out)>=self::MAX_FIELDS*8)break;
        }
        return array_values($out);
    }
    private static function plugin($slug) {
        $active=(array)get_option('active_plugins',array());
        foreach($active as $entry) {
            if($entry===$slug || strpos($entry,$slug.'/')===0)return true;
        }
        return false;
    }
    private static function plugins() {
        $map=array(
            'jet-engine'=>array('JetEngine','jet-engine'),
            'jet-smart-filters'=>array('JetSmartFilters','jet-smart-filters'),
            'jet-woo-builder'=>array('JetWooBuilder','jet-woo-builder'),
            'jet-form-builder'=>array('JetFormBuilder','jet-form-builder'),
            'elementor'=>array('Elementor','elementor'),
            'woocommerce'=>array('WooCommerce','woocommerce'),
            'fluent-forms'=>array('Fluent Forms','fluentform'),
            'wpml'=>array('WPML','sitepress-multilingual-cms'),
        );
        $all=array();
        if(!function_exists('get_plugins'))require_once ABSPATH.'wp-admin/includes/plugin.php';
        $details=get_plugins();
        foreach($map as $id=>$item){
            $version=null;
            foreach($details as $path=>$row){
                if(strpos($path,$item[1].'/')===0){
                    $version=self::clean($row['Version']??'',24);
                    break;
                }
            }
            $all[$id]=array('id'=>$id,'label'=>$item[0],
                'active'=>self::plugin($item[1]),
                'installed_version'=>$version,
                'verified'=>'installed_plugin_registry');
        }
        // Provider discovery extension point. Third-party declarations are
        // UNVERIFIED, cannot grant permissions, and never inject callback code.
        $third=apply_filters('mad4b_hub_discovery_provider_descriptors',array());
        if(is_array($third))foreach(array_slice($third,0,self::MAX_PROVIDERS) as $provider){
            if(!is_array($provider))continue;
            $id=(string)($provider['id']??'');
            if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$id)||isset($all[$id]))continue;
            $all[$id]=array('id'=>$id,'label'=>self::clean($provider['label']??$id),
                'active'=>false,'verified'=>'adapter_review_required',
                'installed_version'=>null);
        }
        return array_values($all);
    }
    private static function registry() {
        global $wpdb;
        $table=$wpdb->prefix.'jet_post_types';
        $exist=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
        if($exist!==$table)return array('queries'=>array(),'options_pages'=>array(),'relations'=>array(),'glossaries'=>array());
        $records=$wpdb->get_results("SELECT id,slug,status,labels,args,meta_fields FROM {$table}
             WHERE status IN ('query','page','relation','glossary')
             ORDER BY id ASC LIMIT 1000",ARRAY_A);
        $out=array('queries'=>array(),'options_pages'=>array(),
            'relations'=>array(),'glossaries'=>array());
        foreach((array)$records as $rec){
            $status=(string)$rec['status'];
            $label=maybe_unserialize($rec['labels']);
            $label=is_array($label)?self::clean($label['name']??$rec['slug']):self::clean($rec['slug']);
            $entry=array('id'=>(int)$rec['id'],'key'=>self::clean($rec['slug']),
                'label'=>$label);
            if($status==='query'){
                $args=maybe_unserialize($rec['args']);$args=is_array($args)?$args:array();
                $entry['type']=self::clean($args['query_type']??'',45);
                $entry['query_id']=self::clean($args['query_id']??'',90);
                $entry['public_api_enabled']=!empty($args['api_endpoint']);
                $out['queries'][]=$entry;
            } elseif($status==='page'){
                $fields=self::definition_fields($rec['meta_fields']);
                $entry['fields']=$fields;
                $entry['field_count']=count($fields);
                $entry['values_shared']=false;
                $out['options_pages'][]=$entry;
            } elseif($status==='relation')$out['relations'][]=$entry;
            elseif($status==='glossary')$out['glossaries'][]=$entry;
        }
        return $out;
    }
    private static function filter_definitions() {
        $posts=get_posts(array('post_type'=>'jet-smart-filters',
            'post_status'=>array('publish','private','draft'),
            'posts_per_page'=>250,'orderby'=>'ID','order'=>'ASC',
            'suppress_filters'=>true));
        $out=array();
        foreach($posts as $p) {
            $out[]=array('id'=>(int)$p->ID,'label'=>self::clean($p->post_title),
                'status'=>$p->post_status,
                'type'=>self::clean(get_post_meta($p->ID,'_filter_type',true),40),
                'source'=>self::clean(get_post_meta($p->ID,'_data_source',true),40),
                'query_var'=>self::clean(get_post_meta($p->ID,'_query_var',true),120));
        }
        return $out;
    }
    private static function templates() {
        $posts=get_posts(array('post_type'=>array('elementor_library','jet-engine','jet-woo-builder'),
            'post_status'=>array('publish','private','draft'),
            'posts_per_page'=>850,'orderby'=>'ID','order'=>'ASC',
            'suppress_filters'=>true));
        $out=array();
        foreach($posts as $p){
            $type=$p->post_type==='elementor_library'?
                self::clean(get_post_meta($p->ID,'_elementor_template_type',true),45):$p->post_type;
            $out[]=array('id'=>(int)$p->ID,'label'=>self::clean($p->post_title),
                'post_type'=>$p->post_type,'template_type'=>$type,'status'=>$p->post_status);
        }
        return $out;
    }
    private static function fluent_forms() {
        if(!self::plugin('fluentform'))return array();
        global $wpdb;
        $table=$wpdb->prefix.'fluentform_forms';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return array();
        $items=$wpdb->get_results("SELECT id,title,status FROM {$table} ORDER BY id ASC LIMIT 150",ARRAY_A);
        $out=array();
        foreach((array)$items as $x) $out[]=array('id'=>(int)$x['id'],
            'label'=>self::clean($x['title']),'status'=>self::clean($x['status'],25),
            'submissions_access'=>'denied');
        return $out;
    }
    public static function capabilities() {
        $plugins=self::plugins();
        $registry=self::registry();
        $pt=array();$tax=array();
        foreach(get_post_types(array(),'objects') as $key=>$obj){
            if(count($pt)>=150)break;
            $pt[]=array('key'=>$key,'label'=>self::clean($obj->label),
                'public'=>(bool)$obj->public,'rest'=>(bool)$obj->show_in_rest);
        }
        foreach(get_taxonomies(array(),'objects') as $key=>$obj){
            if(count($tax)>=150)break;
            $tax[]=array('key'=>$key,'label'=>self::clean($obj->label),
                'hierarchical'=>(bool)$obj->hierarchical,
                'object_types'=>array_slice((array)$obj->object_type,0,25));
        }
        $filters=self::filter_definitions();$templates=self::templates();
        $forms=self::fluent_forms();
        $modules=(array)get_option('jet_engine_modules',array());
        $enabled=array_values(array_filter($modules,'is_string'));
        $result=array('contract'=>self::CONTRACT,'mode'=>'read_only',
            'active_source_distribution'=>false,
            'provider_descriptors'=>$plugins,
            'jet_engine'=>array('enabled_modules'=>$enabled,
                'queries'=>$registry['queries'],'options_pages'=>$registry['options_pages'],
                'relations'=>$registry['relations'],'glossaries'=>$registry['glossaries'],
                'post_types'=>$pt,'taxonomies'=>$tax),
            'jet_smart_filters'=>array('filters'=>$filters),
            'templates'=>$templates,'fluent_forms'=>$forms,
            'limitations'=>array(
                'REST API Query pages_count returns 1 in installed provider',
                'Merged Query cannot arbitrarily union REST, posts and forms',
                'No existing JetEngine REST Query was observed on first audit',
                'Provider discovery does not confer runtime grants',
                'Option values and form submissions are intentionally excluded'
            ));
        $result['counts']=array('queries'=>count($registry['queries']),
            'options_pages'=>count($registry['options_pages']),
            'templates'=>count($templates),'filters'=>count($filters),
            'fluent_forms'=>count($forms),'relations'=>count($registry['relations']));
        $result['evidence_sha256']=hash('sha256',wp_json_encode(array(
            $result['counts'],$enabled,array_column($registry['queries'],'id'),
            array_column($templates,'id'),array_column($filters,'id'))));
        return $result;
    }

    private static function ids($input,$label) {
        if(!is_array($input)||count($input)>self::MAX_IDS)return self::err($label.'_invalid');
        $out=array();
        foreach($input as $id){
            if(!(is_int($id)||(is_string($id)&&ctype_digit($id))) ||
                (int)$id<1 || (string)(int)$id!==(string)$id ||
                isset($out[(int)$id]))return self::err($label.'_invalid');
            $out[(int)$id]=(int)$id;
        }
        return array_values($out);
    }
    private static function strings($input,$label,$choices=null) {
        if(!is_array($input)||count($input)>self::MAX_FIELDS)return self::err($label.'_invalid');
        $out=array();
        foreach($input as $x){
            if(!is_string($x)||!preg_match('/^[a-z][a-z0-9_-]{0,89}$/D',$x) ||
                self::sensitive($x) || isset($out[$x]) ||
                ($choices!==null && !in_array($x,$choices,true)))return self::err($label.'_invalid');
            $out[$x]=true;
        }
        return array_keys($out);
    }
    private static function existing($list,$selected,$label,$type=null) {
        $by=array();
        foreach($list as $row)$by[(int)$row['id']]=$row;
        foreach($selected as $id){
            if(!isset($by[$id]))return self::err($label.'_not_found');
            $row=$by[$id];
            if(isset($row['status']) && $row['status']!=='publish' &&
               $row['status']!=='published')return self::err($label.'_not_published');
            if($type && !in_array(($row['post_type']??''),$type,true))return self::err($label.'_provider_mismatch');
        }
        return true;
    }
    public static function inherit($layers) {
        $levels=array('platform','source_company','consumer_company','provider',
            'content_type','market','selection');
        // Older six-layer manifests remain valid but have no market approval.
        if(is_array($layers) && !array_key_exists('market',$layers))
            $layers['market']=array('deny'=>array());
        if(!is_array($layers)||count($layers)!==count($levels)||
            array_diff(array_keys($layers),$levels)||array_diff($levels,array_keys($layers)))
            return self::err('policy_layers_invalid');
        $denied=array_fill_keys(self::DENIED_ACTIONS,'platform');
        $explicitDenials=array();
        $allowRead=false;$explanations=array();
        foreach($levels as $level){
            $p=$layers[$level];
            if(!is_array($p)||array_diff(array_keys($p),array('deny','allow_read','market_limits')))
                return self::err('policy_level_invalid');
            if(isset($p['market_limits'])&&!is_array($p['market_limits']))
                return self::err('market_limits_invalid');
            $keys=self::strings($p['deny']??array(),'deny');
            if(is_wp_error($keys))return $keys;
            foreach($keys as $key) {
                if($key!=='read_catalog' && !in_array($key,self::DENIED_ACTIONS,true))
                    return self::err('policy_action_unknown');
                if(!isset($explicitDenials[$key])){
                    $denied[$key]=$level;
                    $explicitDenials[$key]=true;
                }
            }
            if(isset($p['allow_read'])&&!is_bool($p['allow_read']))
                return self::err('read_flag_invalid');
            $explanations[$level]=array('denials_added'=>$keys,
                'read_claimed'=>(bool)($p['allow_read']??false));
            if($level==='source_company' && ($p['allow_read']??false)===true)$allowRead=true;
        }
        return array('deny'=>$denied,'layers'=>$explanations,
            'source_read_claimed'=>$allowRead,
            'source_read_proven'=>false,
            'effective_read_authority'=>'none_without_signed_server_proof',
            'runtime_approved'=>false,'rule'=>'DENY_WINS_AND_NO_CLIENT_GRANTED_AUTHORITY');
    }
    public static function validate($manifest) {
        if(!is_array($manifest)||($manifest['contract']??'')!==self::CONTRACT)
            return self::err('manifest_contract');
        $source=$manifest['source_company']??'';
        $sourceUuid=$manifest['source_site_uuid']??'';
        $destUuid=$manifest['consumer_site_uuid']??'';
        if(!is_string($source)||!preg_match('/^[a-z0-9][a-z0-9-]{2,78}$/D',$source) ||
            !is_string($sourceUuid)||!is_string($destUuid)||
            !preg_match('/^[a-f0-9-]{16,64}$/Di',$sourceUuid)||
            !preg_match('/^[a-f0-9-]{16,64}$/Di',$destUuid)||
            hash_equals(strtolower($sourceUuid),strtolower($destUuid)))
            return self::err('company_identity');
        if(($manifest['mode']??null)!=='read_only_dry_run')
            return self::err('write_operation_forbidden');
        $provider=(string)($manifest['provider']??'');
        $caps=self::capabilities();
        $providers=array_column($caps['provider_descriptors'],null,'id');
        if(!isset($providers[$provider]) || $providers[$provider]['active']!==true)
            return self::err('provider_not_active');
        $selection=$manifest['selection']??null;
        if(!is_array($selection))return self::err('selection_missing');
        $idkeys=array('source_record_ids','canonical_category_ids','query_ids',
            'listing_ids','filter_ids','form_ids');
        $selected=array();
        foreach($idkeys as $key){
            $out=self::ids($selection[$key]??array(),$key);
            if(is_wp_error($out))return $out;
            $selected[$key]=$out;
        }
        // Option-only selection is valid and still never copies option values.
        if(!array_filter($selected) && empty($selection['option_fields']))
            return self::err('empty_selection');
        foreach(array('languages'=>self::LANGS,'fields'=>null) as $key=>$allow){
            $x=self::strings($selection[$key]??array(),$key,$allow);
            if(is_wp_error($x))return $x;
            $selected[$key]=$x;
        }
        if(!$selected['languages'])return self::err('languages_required');
        $checks=array(
            array($caps['jet_engine']['queries'],$selected['query_ids'],'query'),
            array($caps['templates'],$selected['listing_ids'],'listing'),
            array($caps['jet_smart_filters']['filters'],$selected['filter_ids'],'filter'),
            array($caps['fluent_forms'],$selected['form_ids'],'form'),
        );
        foreach($checks as $check){
            $result=self::existing($check[0],$check[1],$check[2]);
            if(is_wp_error($result))return $result;
        }
        // Selective Options Page *definition* binding, not its saved values.
        $selectedOptions=$selection['option_fields']??array();
        if(!is_array($selectedOptions)||count($selectedOptions)>15)return self::err('options_selection_invalid');
        $pages=array_column($caps['jet_engine']['options_pages'],null,'key');
        $checkedOptions=array();
        foreach($selectedOptions as $slug=>$keys){
            if(!is_string($slug)||!isset($pages[$slug]))return self::err('options_page_unknown');
            $fields=self::strings($keys,'option_fields');
            if(is_wp_error($fields))return $fields;
            $known=array_column($pages[$slug]['fields'],'key');
            if(array_diff($fields,$known))return self::err('options_field_unknown');
            $checkedOptions[$slug]=$fields;
        }
        $selected['option_fields']=$checkedOptions;
        // Canonical category IDs are LOCAL IDs, never portable across sites.
        // Read exact DB rows; get_term() can rewrite AR IDs under WPML.
        $taxonomy=(string)($selection['canonical_taxonomy']??'tour-types');
        if(!preg_match('/^[a-z][a-z0-9_-]{0,79}$/D',$taxonomy) ||
            !taxonomy_exists($taxonomy))return self::err('canonical_taxonomy_unknown');
        $selected['canonical_taxonomy']=$taxonomy;
        $categoryProofs=array();
        if($selected['canonical_category_ids']){
            global $wpdb;
            if(!defined('ICL_SITEPRESS_VERSION'))return self::err('wpml_required');
            foreach($selected['canonical_category_ids'] as $id){
                $row=$wpdb->get_row($wpdb->prepare(
                    "SELECT t.term_id,tt.parent
                     FROM {$wpdb->terms} t
                     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
                     WHERE t.term_id=%d AND tt.taxonomy=%s LIMIT 1",$id,$taxonomy),ARRAY_A);
                if(!$row)return self::err('canonical_term_unknown');
                $language=$wpdb->get_row($wpdb->prepare(
                    "SELECT language_code,trid
                     FROM {$wpdb->prefix}icl_translations
                     WHERE element_type=%s AND element_id=%d LIMIT 1",
                     'tax_'.$taxonomy,$id),ARRAY_A);
                if(!$language || !in_array($language['language_code'],$selected['languages'],true) ||
                    (int)$language['trid']<1)return self::err('canonical_term_language_unverified');
                $categoryProofs[]=array('canonical_term_id'=>$id,
                    'language'=>$language['language_code'],
                    'target_wpml_group'=>(int)$language['trid'],
                    'parent_id'=>(int)$row['parent']);
            }
        }
        $selected['verified_local_categories']=$categoryProofs;
        $policy=self::inherit($manifest['policy_layers']??null);
        if(is_wp_error($policy))return $policy;
        if(!class_exists('MAD4B_Hub_Market_Policy'))
            return self::err('market_policy_module_unavailable');
        // "markets" are server-registered scopes; absence is an explicit
        // UNSCOPED_DENY, not an implicit all-country import.
        $marketPlan=MAD4B_Hub_Market_Policy::evaluate(
            $manifest['markets']??null,$selected,
            $manifest['policy_layers'],$source);
        if(is_wp_error($marketPlan))return $marketPlan;
        $queryKinds=array();
        foreach($caps['jet_engine']['queries'] as $item){
            if(in_array($item['id'],$selected['query_ids'],true))$queryKinds[$item['id']]=$item['type'];
        }
        // No REST feed activation; native filters never auto-converted to REST.
        $pending=array('mutual_signed_site_grants','source_object_ownership',
            'source_field_and_locale_proofs','exact_query_listing_filter_binding',
            'provider_query_semantics','pagination_and_cache_partition',
            'source_media_permissions','explicit_owner_approval');
        if($selected['form_ids'])$pending[]='forms_schema_and_submission_privacy_review';
        if($selectedOptions)$pending[]='option_schema_only_no_value_copy';
        if($selected['source_record_ids'])$pending[]='source_record_ids_are_not_target_wordpress_ids';
        $pending=array_merge($pending,$marketPlan['pending']);
        if(isset($policy['deny']['read_catalog']))
            $pending[]='read_catalog_denied_by_inherited_policy';
        return array('contract'=>self::CONTRACT,'state'=>'DRY_RUN_NEEDS_EVIDENCE',
            'source_company'=>$source,'provider'=>$provider,
            'selected'=>$selected,'query_types'=>$queryKinds,
            'policy'=>$policy,'market_plan'=>$marketPlan,
            'market_scoped'=>($marketPlan['status']==='MARKET_SCOPED_DRY_RUN'),
            'pending_proofs'=>array_values(array_unique($pending)),
            'runtime_approved'=>false,'mutation_performed'=>false,
            'execution_engine'=>'native_provider_pending_proof',
            'query_count_contract'=>'unknown_until_source_verification',
            'pagination_contract'=>'not_certified_for_rest_query');
    }
    public static function advise($input) {
        if(!is_array($input)||($input['contract']??'')!==self::CONTRACT)return self::err('advice_contract');
        $slug=$input['target_option_page']??null;
        $caps=self::capabilities();
        $pages=array_column($caps['jet_engine']['options_pages'],null,'key');
        if(!is_string($slug)||!isset($pages[$slug]))return self::err('advice_page');
        $fields=$input['source_field_definitions']??null;
        if(!is_array($fields)||count($fields)>self::MAX_FIELDS)return self::err('advice_fields');
        $target=$pages[$slug]['fields'];$proposals=array();$seen=array();
        foreach($fields as $raw){
            if(!is_array($raw))return self::err('advice_field_malformed');
            $key=(string)($raw['key']??'');$type=(string)($raw['type']??'');
            if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,89}$/D',$key) ||
                !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/D',$type)||
                self::sensitive($key)||isset($seen[$key]))return self::err('advice_field_forbidden');
            $seen[$key]=true;$top=null;
            foreach($target as $t){
                if($type!==$t['type']||self::sensitive($t['key']))continue;
                $score=($key===$t['key'])?0.99:
                    ((str_replace(array('_','-'),'',$key)===
                       str_replace(array('_','-'),'',$t['key']))?0.84:0);
                if($score && ($top===null || $score>$top['confidence'])){
                    $top=array('source_field'=>$key,'target_field'=>$t['key'],
                       'field_type'=>$type,'confidence'=>$score,
                       'evidence'=>array('source_declared_type'=>$type,
                           'local_options_page'=>$slug,
                           'local_definition_type'=>$t['type'],
                           'match_method'=>$score===0.99?'exact_key_and_type':'normalized_key_and_type'),
                       'requires_human_review'=>true,'auto_apply'=>false);
                }
            }
            if($top)$proposals[]=$top;
        }
        return array('contract'=>self::CONTRACT,'strategy'=>'deterministic_evidence_only',
            'suggestions'=>array_slice($proposals,0,20),'activation_performed'=>false,
            'model_called'=>false,'source_field_claims_verified'=>false,
            'owner_approval_required'=>true,'no_values_read'=>true);
    }
    /**
     * Read only the binding graph of an EXISTING Elementor template.
     * Never return Elementor settings/data, tokens, dynamic field values,
     * arbitrary shortcode content or source-company options.
     */
    public static function inspect_template($id) {
        if(!(is_int($id)||(is_string($id)&&ctype_digit($id))) ||
           (int)$id<1)return self::err('template_id_invalid');
        $id=(int)$id;
        $post=get_post($id);
        if(!$post || $post->post_type!=='elementor_library' ||
           $post->post_status!=='publish')return self::err('template_unavailable');
        $raw=(string)get_post_meta($id,'_elementor_data',true);
        if(strlen($raw)<2||strlen($raw)>2500000)return self::err('template_size_invalid');
        $tree=json_decode($raw,true);
        if(!is_array($tree))return self::err('template_json_invalid');
        $nodes=array();$visited=0;$overLimit=false;
        $visit=function($items,$depth)use(&$visit,&$nodes,&$visited,&$overLimit){
            if($depth>24){$overLimit=true;return;}
            foreach((array)$items as $node){
                if(++$visited>2000){$overLimit=true;return;}
                if(!is_array($node))continue;
                $widget=(string)($node['widgetType']??'');
                if($widget!==''){
                    $record=array('widget_id'=>self::clean($node['id']??'',12),
                        'widget_type'=>self::clean($widget,70),'references'=>array());
                    $settings=(array)($node['settings']??array());
                    foreach(array('filter_id','query_id','custom_query_id',
                        'listing_id','template_id') as $key){
                        if(!isset($settings[$key]))continue;
                        $values=is_array($settings[$key])?
                            $settings[$key]:array($settings[$key]);
                        $out=array();
                        foreach(array_slice($values,0,30) as $value){
                            if(!is_string($value)&&!is_int($value))continue;
                            $v=(string)$value;
                            if(preg_match('/^[a-zA-Z0-9_-]{1,90}$/D',$v))$out[]=$v;
                        }
                        if($out)$record['references'][$key]=array_values(array_unique($out));
                    }
                    if($record['references'])$nodes[]=$record;
                }
                $visit($node['elements']??array(),$depth+1);
                if($overLimit)return;
            }
        };
        $visit($tree,0);
        if($overLimit)return self::err('template_graph_bounds_exceeded');
        $caps=self::capabilities();
        $queriesById=array_column($caps['jet_engine']['queries'],null,'id');
        $queriesByKey=array();
        foreach($caps['jet_engine']['queries'] as $q){
            if($q['query_id']!=='')$queriesByKey[$q['query_id']]=$q;
        }
        $filterById=array_column($caps['jet_smart_filters']['filters'],null,'id');
        $resolved=array();
        foreach($nodes as $node){
            foreach($node['references'] as $kind=>$values){
                foreach($values as $v){
                    $r=array('widget_id'=>$node['widget_id'],
                        'widget_type'=>$node['widget_type'],'binding_kind'=>$kind,
                        'native_reference'=>$v,'resolution'=>'review_required',
                        'auto_apply'=>false);
                    if(in_array($kind,array('query_id','custom_query_id'),true)){
                        $q=ctype_digit($v)?($queriesById[(int)$v]??null):($queriesByKey[$v]??null);
                        if($q){
                            $r['resolved_query_id']=$q['id'];
                            $r['resolved_query_type']=$q['type'];
                            $r['resolution']='existing_native_query';
                        }
                    }
                    if($kind==='filter_id' && ctype_digit($v)&&isset($filterById[(int)$v])){
                        $r['resolved_filter_id']=(int)$v;
                        $r['resolution']='existing_jet_smart_filter';
                    }
                    $resolved[]=$r;
                }
            }
        }
        return array('contract'=>self::CONTRACT,'template_id'=>$id,
            'template_source'=>'existing_elementor',
            'template_sha256'=>hash('sha256',$raw),
            'bindings'=>$resolved,'widget_binding_nodes'=>count($nodes),
            'read_only'=>true,'secret_values_exported'=>false,
            'activation_performed'=>false,
            'recommendation'=>'preserve_native_query_filter_listing_binding');
    }
    public static function route_inspect($request){
        $payload=$request->get_json_params();
        return self::inspect_template(is_array($payload)?($payload['template_id']??null):null);
    }
    public static function permission(){return current_user_can('manage_options');}
    public static function route_capabilities(){return self::capabilities();}
    public static function route_validate($request){return self::validate($request->get_json_params());}
    public static function route_advise($request){return self::advise($request->get_json_params());}
    public static function routes(){
        foreach(array(
            '/hub-capabilities'=>array('GET','route_capabilities'),
            '/hub-selection/validate'=>array('POST','route_validate'),
            '/hub-selection/advise'=>array('POST','route_advise'),
            '/hub-template/inspect'=>array('POST','route_inspect')
        ) as $path=>$info){
            register_rest_route(self::NAMESPACE_NAME,$path,array(
                'methods'=>$info[0],'callback'=>array(__CLASS__,$info[1]),
                'permission_callback'=>array(__CLASS__,'permission')));
        }
    }
    public static function boot(){
        add_action('rest_api_init',array(__CLASS__,'routes'),35);
    }
}
