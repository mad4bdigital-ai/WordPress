<?php
/**
 * Native JetSmartFilters Consumer & Scope Registry.
 * Discovers mounted filter IDs, provider query slugs and JetEngine post types
 * from existing published Elementor and JetEngine settings, not from brand
 * names or a hard-coded list of filter IDs. Does not change saved widgets.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;

function shared_presentation_native_consumers_v1(): array {
    static $building=false;
    if($building)return [];
    $cached=get_transient('shared_presentation_native_consumers_v1');
    if(is_array($cached)&&($cached['version']??null)===1)return $cached['filters'];

    $building=true;
    global $wpdb;
    $rows=$wpdb->get_col($wpdb->prepare(
       "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm
        ON pm.post_id=p.ID AND pm.meta_key='_elementor_data'
        WHERE p.post_status='publish' AND p.post_type IN ('elementor_library','page')
        AND pm.meta_value LIKE %s ORDER BY p.ID ASC LIMIT 1001",
        '%'.$wpdb->esc_like('jet-smart-filters-').'%'
    ));
    if(!is_array($rows)||count($rows)>1000){$building=false;return [];}
    $providers=[];
    if(class_exists('\Jet_Engine\Query_Builder\Manager')){
        $manager=\Jet_Engine\Query_Builder\Manager::instance();
        foreach((array)($manager->queries??[]) as $obj) {
            if(($obj->query_type??'')!=='posts')continue;
            $slug=(string)($obj->query_id??'');
            $types=array_values(array_filter((array)($obj->query['post_type']??[]),'is_string'));
            if($slug&&count($types)===1)$providers[$slug]=$types[0];
        }
    }
    // WordPress native blog archive Query #76 has a separate signed provider
    // but is not currently exposed under a persisted Query Builder slug.
    if(function_exists('shared_presentation_provider_v2')){
        foreach(['tours.archive','blog.archive','blog.category.facet'] as $slot){
            $p=shared_presentation_provider_v2($slot);
            if($p && preg_match('~^jet-engine/([a-z0-9_-]+)$~',$p['provider'],$m)){
                $type=(string)($p['entity_type']??'');
                if($type)$providers[$m[1]]=$type;
            }
        }
    }
    $bindings=[];
    $visit=function($nodes,$templateId)use(&$visit,&$bindings,$providers){
      foreach((array)$nodes as $node){
        if(!is_array($node))continue;
        $widget=(string)($node['widgetType']??'');
        $s=$node['settings']??[];
        if(str_starts_with($widget,'jet-smart-filters-')&&is_array($s)
          &&($s['content_provider']??null)==='jet-engine'){
            $slug=(string)($s['query_id']??'');
            foreach((array)($s['filter_id']??$s['selected_filter']??[]) as $sourceId){
              if(!is_scalar($sourceId)||!ctype_digit((string)$sourceId))continue;
              $id=(int)$sourceId;
              if(!$id||get_post_type($id)!=='jet-smart-filters'
                ||get_post_status($id)!=='publish')continue;
              $tax=(string)get_post_meta($id,'_source_taxonomy',true);
              if(!taxonomy_exists($tax)||get_post_meta($id,'_data_source',true)!=='taxonomies')continue;
              $type=(string)($providers[$slug]??'');
              // Owner cannot be guessed from similarly named query variables.
              // Unresolved widgets are recorded but not granted policy rights.
              if($type&& !in_array($type,(array)get_taxonomy($tax)->object_type,true))continue;
              $bindings[$id]['taxonomy']=$tax;
              $bindings[$id]['source']='taxonomies';
              $bindings[$id]['filter_type']=(string)get_post_meta($id,'_filter_type',true);
              $bindings[$id]['providers'][$slug]=['post_type'=>$type];
              $bindings[$id]['template_ids'][$templateId]=true;
            }
        }
        if(isset($node['elements']))$visit($node['elements'],$templateId);
      }
    };
    foreach($rows as $id){
        $nodes=json_decode((string)get_post_meta((int)$id,'_elementor_data',true),true);
        if(is_array($nodes))$visit($nodes,(int)$id);
    }
    foreach($bindings as $id=>&$b){
        $b['template_ids']=array_map('intval',array_keys($b['template_ids']));
        $b['providers']=array_filter($b['providers'],static fn($v)=>is_array($v));
    }
    unset($b);
    ksort($bindings,SORT_NUMERIC);
    set_transient('shared_presentation_native_consumers_v1',['version'=>1,'filters'=>$bindings],5*MINUTE_IN_SECONDS);
    $building=false;
    return $bindings;
}

function shared_presentation_native_facet_binding_v1(int $filterId): ?array {
    $found=shared_presentation_native_consumers_v1();
    if(isset($found[$filterId]))return $found[$filterId];
    // Persisted WPML filter translations inherit ONLY the exact source
    // provider bindings of their native canonical filter, never all filters.
    $trid=apply_filters('wpml_element_trid',null,$filterId,'post_jet-smart-filters');
    if(!$trid)return null;
    foreach((array)apply_filters('wpml_get_element_translations',null,$trid,'post_jet-smart-filters') as $row){
        $other=(int)($row->element_id??0);
        if($other && isset($found[$other])){
           $binding=$found[$other];
           if(get_post_status($filterId)==='publish'
             &&get_post_meta($filterId,'_source_taxonomy',true)===$binding['taxonomy']
             &&get_post_meta($filterId,'_data_source',true)==='taxonomies')return $binding;
        }
    }
    return null;
}
function shared_presentation_native_consumer_invalidate_v1($id=0):void {
    if($id && !in_array(get_post_type((int)$id),['elementor_library','page','jet-smart-filters'],true))return;
    delete_transient('shared_presentation_native_consumers_v1');
}
add_action('save_post','shared_presentation_native_consumer_invalidate_v1',30,1);
add_action('deleted_post','shared_presentation_native_consumer_invalidate_v1',30,1);
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook){
    add_action($hook,static function($mid,$postId,$key) {
        if(in_array($key,['_elementor_data','_source_taxonomy','_data_source','_filter_type','_query_var'],true))
            shared_presentation_native_consumer_invalidate_v1((int)$postId);
    },35,3);
}
