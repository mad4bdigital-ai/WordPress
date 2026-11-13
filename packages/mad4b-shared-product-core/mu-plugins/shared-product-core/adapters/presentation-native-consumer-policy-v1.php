<?php
/**
 * Native JetSmartFilters Consumer & Scope Registry.
 * Discovers mounted filter IDs, provider query slugs and JetEngine post types
 * from existing published Elementor and JetEngine settings, not from brand
 * names or a hard-coded list of filter IDs. Does not change saved widgets.
 */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)
    && (!defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') || !MAD4B_PRESENTATION_RUNTIME_ENABLED)) return;

/**
 * Persistent site-local ID index for published Elementor JetSmartFilters consumers.
 *
 * The legacy _elementor_data LIKE scan is a one-time, bounded bootstrap only.
 * Each subsequent Elementor save/status change updates one ID, and each cache
 * miss consumes the small indexed set rather than scanning serialized JSON.
 *
 * The index contains post IDs, NOT filter rights. Binding ownership and
 * postmeta/provider checks remain live in shared_presentation_native_consumers_v1().
 */
function shared_presentation_native_consumer_index_v2(): ?array {
    $stored=get_option('shared_presentation_native_consumer_ids_v2',null);
    if(is_array($stored) && ($stored['version']??null)===2 &&
       isset($stored['ids']) && is_array($stored['ids'])){
        $ids=array_values(array_unique(array_filter(
            array_map('absint',$stored['ids']),static fn($id)=>$id>0
        )));
        sort($ids,SORT_NUMERIC);
        return $ids;
    }
    global $wpdb;
    // Cold bootstrap for existing templates, once per site, preserving the
    // exact source match and the 1001-document safety bound.
    $rows=$wpdb->get_col($wpdb->prepare(
       "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm
        ON pm.post_id=p.ID AND pm.meta_key='_elementor_data'
        WHERE p.post_status='publish' AND p.post_type IN ('elementor_library','page')
        AND pm.meta_value LIKE %s ORDER BY p.ID ASC LIMIT 1001",
        '%'.$wpdb->esc_like('jet-smart-filters-').'%'
    ));
    if(!is_array($rows)||$wpdb->last_error!==''||count($rows)>1000)return null;
    $ids=array_values(array_unique(array_map('absint',$rows)));
    sort($ids,SORT_NUMERIC);
    // Autoload off: never impose a site-wide per-request option load.
    update_option('shared_presentation_native_consumer_ids_v2',
        ['version'=>2,'ids'=>$ids],false);
    return $ids;
}

function shared_presentation_native_consumer_index_refresh_v2(int $postId,bool $deleted=false):void {
    if($postId<=0)return;
    $stored=get_option('shared_presentation_native_consumer_ids_v2',null);
    if(!is_array($stored)||($stored['version']??null)!==2 ||
       !isset($stored['ids'])||!is_array($stored['ids']))return;
    $ids=array_fill_keys(array_map('absint',$stored['ids']),true);
    unset($ids[0]);
    $eligible=!$deleted
        && in_array(get_post_type($postId),['elementor_library','page'],true)
        && get_post_status($postId)==='publish';
    if($eligible){
        $data=get_post_meta($postId,'_elementor_data',true);
        $eligible=is_string($data)&&str_contains($data,'jet-smart-filters-');
    }
    if($eligible)$ids[$postId]=true;
    else unset($ids[$postId]);
    $next=array_map('intval',array_keys($ids));
    sort($next,SORT_NUMERIC);
    $previous=array_values(array_unique(array_map('absint',$stored['ids'])));
    sort($previous,SORT_NUMERIC);
    if($next!==$previous)update_option('shared_presentation_native_consumer_ids_v2',
        ['version'=>2,'ids'=>$next],false);
}

// Save-time updates are intentionally separated from the runtime read path.
// All changes also invalidate the existing compiled binding transient.
add_action('save_post',static function($postId){
    shared_presentation_native_consumer_index_refresh_v2((int)$postId);
},40,1);
add_action('transition_post_status',static function($new,$old,$post){
    if(!is_object($post)||!in_array($post->post_type,['elementor_library','page'],true))return;
    if($new!==$old){
        shared_presentation_native_consumer_index_refresh_v2((int)$post->ID);
        shared_presentation_native_consumer_invalidate_v1((int)$post->ID);
    }
},40,3);
add_action('before_delete_post',static function($postId){
    shared_presentation_native_consumer_index_refresh_v2((int)$postId,true);
    shared_presentation_native_consumer_invalidate_v1((int)$postId);
},40,1);
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook){
    add_action($hook,static function($metaId,$postId,$key){
        if($key==='_elementor_data')
            shared_presentation_native_consumer_index_refresh_v2((int)$postId);
    },40,3);
}

function shared_presentation_native_consumers_v1(): array {
    static $building=false;
    if($building)return [];
    $cached=get_transient('shared_presentation_native_consumers_v1');
    if(is_array($cached)&&($cached['version']??null)===3)return $cached['filters'];

    $building=true;
    global $wpdb;
    $rows=shared_presentation_native_consumer_index_v2();
    if(!is_array($rows)||count($rows)>1000){$building=false;return [];}
    // A stale or tampered ID index never authorizes unpublished templates.
    // One posts-primary-key lookup is indexed; no JSON LIKE and no N+1 status queries.
    if($rows){
        $ids=array_values(array_filter(array_map('absint',$rows)));
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders)
             AND post_status='publish'
             AND post_type IN ('elementor_library','page') ORDER BY ID ASC",
            ...$ids
        ));
        if(!is_array($rows)||$wpdb->last_error!==''){$building=false;return [];}
    }
    $providers=[];
    if(class_exists('\Jet_Engine\Query_Builder\Manager')){
        $manager=\Jet_Engine\Query_Builder\Manager::instance();
        foreach((array)($manager->queries??[]) as $obj) {
            $slug=(string)($obj->query_id??'');
            $kind=(string)($obj->query_type??'');
            if(!$slug)continue;
            if($kind==='posts'){
                $types=array_values(array_filter((array)($obj->query['post_type']??[]),'is_string'));
                if(count($types)===1) $providers[$slug]=[
                    'post_type'=>$types[0],'query_type'=>'posts','taxonomies'=>[]
                ];
            }elseif($kind==='terms'){
                $tax=array_values(array_filter((array)($obj->query['taxonomy']??[]),
                    static fn($name)=>is_string($name)&&taxonomy_exists($name)));
                if($tax) $providers[$slug]=[
                    'post_type'=>'','query_type'=>'terms','taxonomies'=>$tax
                ];
            }
        }
    }
    // WordPress native blog archive Query #76 has a separate signed provider
    // but is not currently exposed under a persisted Query Builder slug.
    if(function_exists('shared_presentation_provider_v2')){
        foreach(['tours.archive','blog.archive','blog.category.facet'] as $slot){
            $p=shared_presentation_provider_v2($slot);
            if($p && preg_match('~^jet-engine/([a-z0-9_-]+)$~',$p['provider'],$m)){
                $type=(string)($p['entity_type']??'');
                if($type)$providers[$m[1]]=[
                    'post_type'=>$type,'query_type'=>'posts','taxonomies'=>[]
                ];
            }
        }
    }
    // A native Listing Grid may be the JetSmartFilters provider without a
    // saved JetEngine Query Builder row. Its Elementor widget ID is the query
    // target, and its *actual saved native CPT* proves ownership.
    $listingProviders=function($nodes)use(&$listingProviders,$providers):array {
        $found=[];$conflicts=[];
        foreach((array)$nodes as $node){
            if(!is_array($node))continue;
            if(($node['widgetType']??'')==='jet-listing-grid') {
                $settings=(array)($node['settings']??[]);
                $slug=(string)($settings['_element_id']??'');
                $type='';
                $custom=(string)($settings['custom_query']??'');
                if($custom==='yes'){
                    $qid=(string)($settings['custom_query_id']??'');
                    $queryProvider=$providers[$qid]??null;
                    if(is_array($queryProvider)&&
                        ($queryProvider['query_type']??'')==='posts')
                        $type=(string)($queryProvider['post_type']??'');
                }elseif(($settings['use_custom_post_types']??'')==='yes'){
                    $types=array_values(array_unique(array_filter(
                        (array)($settings['custom_post_types']??[]),
                        static fn($t)=>is_string($t)&&post_type_exists($t)
                    )));
                    if(count($types)===1)$type=$types[0];
                }
                if($slug!=='' && $type!=='' && post_type_exists($type)){
                    if(isset($found[$slug]) && ($found[$slug]['post_type']??'')!==$type){
                        unset($found[$slug]);$conflicts[$slug]=true;
                    }elseif(!isset($conflicts[$slug])){
                        $found[$slug]=['post_type'=>$type,'query_type'=>'posts',
                            'taxonomies'=>[],'origin'=>'elementor_native_listing'];
                    }
                }
            }
            if(isset($node['elements'])&&is_array($node['elements'])){
                $sub=$listingProviders($node['elements']);
                foreach($sub as $slug=>$descriptor) {
                    if(isset($found[$slug]) &&
                       ($found[$slug]['post_type']??'')!==($descriptor['post_type']??'')){
                        unset($found[$slug]);$conflicts[$slug]=true;
                    }elseif(!isset($conflicts[$slug])){
                        $found[$slug]=$descriptor;
                    }
                }
            }
        }
        return $found;
    };
    $bindings=[];
    $visit=function($nodes,$templateId,$localProviders)use(&$visit,&$bindings,$providers){
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
              $provider=$providers[$slug]??$localProviders[$slug]??[
                  'post_type'=>'','query_type'=>'unresolved','taxonomies'=>[]
              ];
              $type=(string)($provider['post_type']??'');
              // Term query metadata proves only its taxonomy, never a Tour ID.
              // Tour context is verified independently at render time.
              if(($provider['query_type']??'')==='terms'
                  && !in_array($tax,(array)($provider['taxonomies']??[]),true))continue;
              if($type&& !in_array($type,(array)get_taxonomy($tax)->object_type,true))continue;
              $bindings[$id]['taxonomy']=$tax;
              $bindings[$id]['source']='taxonomies';
              $bindings[$id]['filter_type']=(string)get_post_meta($id,'_filter_type',true);
              $prior=$bindings[$id]['providers'][$slug]??null;
              if(is_array($prior)
                  && ($prior['post_type']??'')!==($provider['post_type']??'')){
                  $provider=['post_type'=>'','query_type'=>'ambiguous',
                      'taxonomies'=>[],'origin'=>'conflicting_consumer_templates'];
              }
              $bindings[$id]['providers'][$slug]=$provider;
              $bindings[$id]['template_ids'][$templateId]=true;
            }
        }
        if(isset($node['elements']))$visit($node['elements'],$templateId,$localProviders);
      }
    };
    foreach($rows as $id){
        $nodes=json_decode((string)get_post_meta((int)$id,'_elementor_data',true),true);
        if(is_array($nodes))$visit($nodes,(int)$id,$listingProviders($nodes));
    }
    foreach($bindings as $id=>&$b){
        $b['template_ids']=array_map('intval',array_keys($b['template_ids']));
        $b['providers']=array_filter($b['providers'],static fn($v)=>is_array($v));
    }
    unset($b);
    ksort($bindings,SORT_NUMERIC);
    set_transient('shared_presentation_native_consumers_v1',['version'=>3,'filters'=>$bindings],5*MINUTE_IN_SECONDS);
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


// Query Builder definitions live in JetEngine's custom data table, NOT WP posts.
// The native editor emits this event only after a successful add/edit.
add_action('jet-engine/query-builder/after-query-update',static function(){
    shared_presentation_native_consumer_invalidate_v1();
    do_action('mad4b/public_html/safe_purge_all','native_query_builder_definition_updated');
},40,0);

// JetEngine's delete endpoint has no after-query-update event.
// Accept only a successful DELETE on its registered REST route.
add_filter('rest_post_dispatch',static function($response,$server,$request){
    if (!$request instanceof WP_REST_Request ||
        strtoupper((string)$request->get_method())!=='DELETE' ||
        !preg_match('~^/jet-engine/v[0-9]+/delete-query/[^/]+$~D',
            (string)$request->get_route()) ||
        !$response instanceof WP_REST_Response) return $response;
    $data=$response->get_data();
    if (!is_array($data) || ($data['success']??false)!==true)return $response;
    shared_presentation_native_consumer_invalidate_v1();
    do_action('mad4b/public_html/safe_purge_all','native_query_builder_definition_deleted');
    return $response;
},45,3);
