<?php
/**
 * Source-aware policy projection for native JetSmartFilters taxonomy options.
 * Native saved Elementor/JetEngine/JSF consumers remain authoritative;
 * published company services/posts supply eligible option IDs.
 */
defined('ABSPATH') || exit;
if(wp_get_environment_type()!=='local')return;

function shared_presentation_published_facet_terms_v1(string $postType,string $taxonomy): array {
    $key='shared_facet_post_terms_'.md5($postType.'|'.$taxonomy);
    $cached=get_transient($key);
    if(is_array($cached))return $cached;
    if(!taxonomy_exists($taxonomy) || !post_type_exists($postType))return [];
    global $wpdb;
    // Query all published source content, excluding passworded/hidden Tours,
    // and never use another tenant's post type as a backdoor.
    $sql="SELECT DISTINCT tt.term_id FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->term_relationships} rel ON rel.object_id=p.ID
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=rel.term_taxonomy_id
         WHERE tt.taxonomy=%s AND p.post_type=%s AND p.post_status='publish'
           AND p.post_password=''";
    if($postType==='tours-and-activities'){
        $sql.=" AND NOT EXISTS(SELECT 1 FROM {$wpdb->postmeta} m
                 WHERE m.post_id=p.ID AND m.meta_key='tour_mode'
                 AND m.meta_value='extra_trip')
            AND NOT EXISTS(SELECT 1 FROM {$wpdb->postmeta} m
                 WHERE m.post_id=p.ID AND m.meta_key='tour_public_status'
                 AND m.meta_value IN('0','false','no','disabled'))";
    }
    $ids=array_map('intval',(array)$wpdb->get_col($wpdb->prepare($sql,$taxonomy,$postType)));
    $set=[];
    foreach($ids as $id){
        if(!$id)continue;
        foreach(array_merge([$id],array_map('intval',get_ancestors($id,$taxonomy,'taxonomy'))) as $termId){
            $set[$termId]=true;
            $en=(int)apply_filters('wpml_object_id',$termId,$taxonomy,false,'en');
            if($en)$set[$en]=true;
        }
    }
    // The transient stores only small term ID lists, never personal data.
    set_transient($key,array_keys($set),5*MINUTE_IN_SECONDS);
    return array_keys($set);
}

function shared_presentation_native_filter_options_v1(array $options,int $filterId):array {
    $binding=shared_presentation_native_facet_binding_v1($filterId);
    if(!$binding||($binding['source']??'')!=='taxonomies')return $options;
    if(get_post_status($filterId)!=='publish'
       || get_post_meta($filterId,'_data_source',true)!=='taxonomies'
       || get_post_meta($filterId,'_source_taxonomy',true)!==$binding['taxonomy'])return $options;
    if(!in_array(get_post_meta($filterId,'_filter_type',true),['checkboxes','select','radio','color-image'],true))
        return $options;
    $taxonomy=$binding['taxonomy'];
    if($taxonomy==='destinations'){
        // One company policy for Tour, Home, Destination, Property, Transfer
        // and any other VERIFIED mounted native destination consumer.
        return shared_presentation_company_location_facet_v1($options,$filterId,true);
    }
    $types=[];
    $hasRecognizedSource=false;
    $hasSpecializedPricingSource=false;
    foreach($binding['providers'] as $source){
        $type=(string)($source['post_type']??'');
        if(!$type || !in_array($type,(array)get_taxonomy($taxonomy)->object_type,true))continue;
        $hasRecognizedSource=true;
        $owner=function_exists('service_channel_owner')?service_channel_owner($type):null;
        // Rate and Unit providers own their own date/inventory/offer
        // eligibility engines, including special Season/Package facets.
        if(in_array($type,['tour-rates','property-rates','transport-rates','units'],true)){
            $hasSpecializedPricingSource=true;
            continue;
        }
        if($owner && (!function_exists('service_visible_in_current_page')
            || !service_visible_in_current_page($type)))continue;
        $types[$type]=true;
    }
    // Unknown/unspecified source provider must never get an invented scope.
    // An owner that is definitely known but disallowed must not expose
    // its taxonomy as an apparent bookable standalone public service.
    // Unknown source query IDs continue to use original native behavior.
    if(!$types)return $hasSpecializedPricingSource?$options:($hasRecognizedSource?[]:$options);
    $allowed=[];
    foreach(array_keys($types) as $type)
        foreach(shared_presentation_published_facet_terms_v1($type,$taxonomy) as $term)
            $allowed[(int)$term]=true;
    if(!$allowed)return [];
    $result=[];
    foreach($options as $key=>$option){
        $term=$option instanceof WP_Term?$option:null;
        if(!$term && is_array($option) && isset($option['value']) && is_scalar($option['value'])){
            $raw=(string)$option['value'];
            $term=ctype_digit($raw)?get_term((int)$raw,$taxonomy):get_term_by('slug',$raw,$taxonomy);
        }
        if(!$term instanceof WP_Term || $term->taxonomy!==$taxonomy){
            // WPML filter plugins use some non-term/blank option entries; do
            // not alter their unsupported schema as if it were a term.
            $result[$key]=$option;
            continue;
        }
        $id=(int)$term->term_id;
        $en=(int)apply_filters('wpml_object_id',$id,$taxonomy,false,'en');
        if(isset($allowed[$id]) || ($en && isset($allowed[$en])))
            $result[$key]=$option;
    }
    return $result;
}
add_filter('jet-smart-filters/filters/filter-options',static function($options,$id){
    if(!is_array($options) || (is_admin()&&!wp_doing_ajax()))return $options;
    return shared_presentation_native_filter_options_v1($options,(int)$id);
},50,2);

function shared_presentation_native_filter_invalidate_v1($postId=0):void{
    // WordPress Transient API must own deletion and object-cache coherence.
    // Discover the actual native source/type pairs; do not scan/delete
    // arbitrary wp_options keys or invalidate unrelated tenant caches.
    $bindings=shared_presentation_native_consumers_v1();
    foreach($bindings as $b){
        $taxonomy=(string)($b['taxonomy']??'');
        if(!$taxonomy)continue;
        foreach((array)($b['providers']??[]) as $source){
            $type=(string)($source['post_type']??'');
            if(!$type)continue;
            delete_transient('shared_facet_post_terms_'.md5($type.'|'.$taxonomy));
        }
    }
}
add_action('set_object_terms',static function($objectId){
    if(in_array(get_post_type((int)$objectId),['tours-and-activities','properties','transportations','post','help-center'],true))
        shared_presentation_native_filter_invalidate_v1();
},30,1);
add_action('transition_post_status',static function($new,$old,$post){
    if($new!==$old && in_array($post->post_type,['tours-and-activities','properties','transportations','post','help-center'],true))
        shared_presentation_native_filter_invalidate_v1();
},30,3);
add_action('updated_post_meta',static function($id,$postId,$key){
    if(in_array($key,['tour_mode','tour_public_status'],true) && get_post_type((int)$postId)==='tours-and-activities')
        shared_presentation_native_filter_invalidate_v1();
},30,3);
