<?php
/**
 * REV108 – native JetSmartFilters typed-source contracts.
 *
 * Read-only discovery of published Elementor consumers and native JSF source
 * settings. No option override, no price/tour-rate changes and no write
 * authority. Other domain engines must opt in with explicit typed contracts.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local' ||
    !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

function shared_presentation_typed_source_contract_v1(int $filterId): ?array {
    if ($filterId<=0 || get_post_type($filterId)!=='jet-smart-filters' ||
        get_post_status($filterId)!=='publish')return null;
    $type=(string)get_post_meta($filterId,'_filter_type',true);
    $source=(string)get_post_meta($filterId,'_data_source',true);
    $queryVar=(string)get_post_meta($filterId,'_query_var',true);
    $reference=null;
    $status='native_delegate_not_certified';
    $authority='jet_smart_filters';
    $queryType=null;

    if($source==='taxonomies'){
        $tax=(string)get_post_meta($filterId,'_source_taxonomy',true);
        if(!taxonomy_exists($tax))return null;
        $reference=['taxonomy'=>$tax];
        $status='native_taxonomy_scoped_by_rev107';
        $authority='wordpress_taxonomy_and_company_policy';
    }elseif($source==='query_builder'){
        $id=absint(get_post_meta($filterId,'_query_builder_query',true));
        $query=null;
        if($id && class_exists('\Jet_Engine\Query_Builder\Manager'))
            $query=\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id($id);
        $queryType=is_object($query)?(string)($query->query_type??''):'';
        $refValue=(string)get_post_meta($filterId,'_query_builder_value_prop',true);
        $refLabel=(string)get_post_meta($filterId,'_query_builder_label_prop',true);
        $reference=['query_id'=>$id,'query_type'=>$queryType,
            'value_property'=>$refValue,'label_property'=>$refLabel];
        $status=$query && $queryType==='terms' && $refValue!=='' && $refLabel!==''
            ?'native_terms_query_validated':'requires_source_binding_review';
        $authority='jetengine_query_builder';
    }elseif($source==='glossary'){
        $glossary=absint(get_post_meta($filterId,'_glossary_id',true));
        $reference=['glossary_id'=>$glossary];
        if($type==='range' && $queryVar==='start_price' &&
            function_exists('shared_presentation_catalog_contract_v1')){
            $p=shared_presentation_catalog_contract_v1();
            if(in_array($filterId,array_map('intval',(array)($p['price_filter_ids']??[])),true)){
                $status='native_shared_price_delegate';
                $authority='shared_lab_tour_price_resolve_wcml';
            }
        }
    }elseif($source==='manual_input'){
        $raw=get_post_meta($filterId,'_source_manual_input',true);
        $parsed=is_array($raw)?$raw:(is_string($raw)?json_decode($raw,true):null);
        $valid=is_array($parsed) && count($parsed)<=150;
        if($valid)foreach($parsed as $option){
            if(!is_array($option)||!isset($option['value'],$option['label']) ||
                !is_scalar($option['value'])||!is_scalar($option['label'])){
                $valid=false;break;
            }
        }
        $reference=['option_count'=>$valid?count($parsed):null];
        $status=$valid?'native_manual_options_readonly':'requires_source_binding_review';
        $authority='original_filter_or_domain_adapter';
    }elseif($type==='date-range'){
        $format=(string)get_post_meta($filterId,'_date_format',true);
        $reference=['date_format'=>$format];
        $status=$queryVar!=='' && str_starts_with($queryVar,'advanced_date__')
          ?'native_date_scope_requires_eligibility':'requires_source_binding_review';
        $authority='native_jet_smart_filters_date_and_domain_eligibility';
    }elseif($type==='search'){
        $status='native_search_delegate';
        $authority='native_query_provider';
    }elseif($type==='range'){
        $status='range_requires_explicit_price_or_source_contract';
        $authority='native_query_provider';
    }elseif($source==='cct' || $source==='jet_engine_cct'){
        $status='cct_requires_registered_collection_and_tenant_scope';
        $authority='jetengine_cct';
    }elseif($source===''){
        $status='unknown_source_fail_closed_for_policy';
    }
    return ['filter_id'=>$filterId,'filter_type'=>$type,'data_source'=>$source,
        'query_var'=>$queryVar,'reference'=>$reference,'state'=>$status,
        'owner'=>$authority,'policy_mutation'=>false];
}

function shared_presentation_native_typed_manifest_v1(): array {
    static $building=false;
    if($building)return [];
    $key='shared_presentation_typed_sources_rev108_v1';
    $cached=get_transient($key);
    if(is_array($cached))return $cached;
    $building=true;
    global $wpdb;
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT p.ID,pm.meta_value AS layout FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_elementor_data'
         WHERE p.post_status='publish' AND p.post_type IN('elementor_library','page')
          AND pm.meta_value LIKE %s ORDER BY p.ID LIMIT 1001",
        '%'.$wpdb->esc_like('jet-smart-filters-').'%'),ARRAY_A);
    if(count($rows)>1000){$building=false;return [];}
    $seen=[];
    $walk=function($nodes,$template) use (&$walk,&$seen){
        foreach((array)$nodes as $n){
            if(!is_array($n))continue;
            $w=(string)($n['widgetType']??'');$settings=$n['settings']??[];
            if(str_starts_with($w,'jet-smart-filters-')&&is_array($settings)){
                foreach((array)($settings['filter_id']??$settings['selected_filter']??[]) as $value){
                    if(!is_scalar($value)||!ctype_digit((string)$value))continue;
                    $id=(int)$value;
                    $contract=shared_presentation_typed_source_contract_v1($id);
                    if(!$contract)continue;
                    $seen[$id]['contract']=$contract;
                    $provider=(string)($settings['content_provider']??'');
                    $query=(string)($settings['query_id']??'');
                    if($provider==='jet-engine' && preg_match('/^[a-z0-9_-]{2,100}$/',$query))
                        $seen[$id]['provider_slugs'][$query]=true;
                    $seen[$id]['template_ids'][(int)$template]=true;
                }
            }
            if(isset($n['elements']))$walk($n['elements'],$template);
        }
    };
    foreach($rows as $r){
        $nodes=json_decode((string)$r['layout'],true);
        if(is_array($nodes))$walk($nodes,(int)$r['ID']);
    }
    $out=[];
    foreach($seen as $id=>$values){
        $out[$id]=$values['contract'];
        $out[$id]['provider_slugs']=array_keys($values['provider_slugs']??[]);
        $out[$id]['template_ids']=array_keys($values['template_ids']??[]);
    }
    ksort($out,SORT_NUMERIC);
    set_transient($key,$out,5*MINUTE_IN_SECONDS);
    $building=false;
    return $out;
}

function shared_presentation_typed_manifest_invalidate_v1(int $postId):void {
    if(in_array(get_post_type($postId),['elementor_library','page','jet-smart-filters'],true))
        delete_transient('shared_presentation_typed_sources_rev108_v1');
}
add_action('save_post','shared_presentation_typed_manifest_invalidate_v1',35,1);
add_action('deleted_post','shared_presentation_typed_manifest_invalidate_v1',35,1);
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)
    add_action($hook,static function($metaId,$postId,$key){
        if(in_array($key,['_elementor_data','_filter_type','_data_source','_query_var',
            '_query_builder_query','_query_builder_value_prop','_query_builder_label_prop',
            '_glossary_id','_source_manual_input','_date_format'],true))
            shared_presentation_typed_manifest_invalidate_v1((int)$postId);
    },35,3);
