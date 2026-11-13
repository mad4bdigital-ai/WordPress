<?php
/**
 * Source-aware policy projection for native JetSmartFilters taxonomy options.
 * Native saved Elementor/JetEngine/JSF consumers remain authoritative;
 * published company services/posts supply eligible option IDs.
 */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)
    && (!defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') || !MAD4B_PRESENTATION_RUNTIME_ENABLED)) return;

/**
 * Shared Product Core: public Tour discovery constraints for native listings.
 * Mirrors the original published-facet definition, but retains WordPress's
 * native WP_Query so taxonomies, WPML and other source filters still run.
 * No rate/price/booking authorization is implied by discoverability.
 */
function shared_presentation_public_tour_query_args_v1(array $args): array {
    $args['post_type']='tours-and-activities';
    $args['post_status']='publish';
    $args['has_password']=false;
    $args['suppress_filters']=false;
    $args['meta_query']=[
        'relation'=>'AND',
        ['relation'=>'OR',
            ['key'=>'tour_public_status','compare'=>'NOT EXISTS'],
            ['key'=>'tour_public_status','value'=>['0','false','no','disabled'],'compare'=>'NOT IN'],
        ],
        ['relation'=>'OR',
            ['key'=>'tour_mode','compare'=>'NOT EXISTS'],
            ['key'=>'tour_mode','value'=>'extra_trip','compare'=>'!='],
        ],
    ];
    return $args;
}

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
    // The same filter ID can be used by Tours, Properties and Transportations.
    // Consult the active *native* Elementor consumer before the generic ID index.
    $binding=class_exists('MAD4B_Company_Term_Eligibility_V1',false)
        ? MAD4B_Company_Term_Eligibility_V1::consumer_scoped_binding($filterId)
        : shared_presentation_native_facet_binding_v1($filterId);
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
        // Native Tour-component filters are read-only: disabling a separate
        // service must not erase a linked Tour's taxonomy controls.
        // Outside a verified Tour, the original service policy still applies.
        $channel=class_exists('MAD4B_Company_Term_Eligibility_V1',false)
            ?MAD4B_Company_Term_Eligibility_V1::surface_channel():'standalone';
        if($owner && $channel==='tour_component'){
            if(!MAD4B_Company_Term_Eligibility_V1::query_service_allowed($owner,$channel))continue;
        }elseif($owner && (!function_exists('service_visible_in_current_page')
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
    $pairs=[];
    $type=$postId?get_post_type((int)$postId):'';
    if (is_string($type) && $type!=='' && post_type_exists($type)) {
        // Invalidate exactly the changed source CPT's native taxonomies.
        foreach ((array)get_object_taxonomies($type) as $taxonomy)
            if (taxonomy_exists($taxonomy))$pairs[$type.'|'.$taxonomy]=true;
    } else {
        // Generic definition change: discover both saved native consumers and
        // newly registered catalog taxonomies (no static source/term-ID list).
        foreach(shared_presentation_native_consumers_v1() as $binding){
            $tax=(string)($binding['taxonomy']??'');
            if(!$tax || !taxonomy_exists($tax))continue;
            foreach((array)($binding['providers']??[]) as $source){
                $pt=(string)($source['post_type']??'');
                if($pt && post_type_exists($pt))$pairs[$pt.'|'.$tax]=true;
            }
        }
        if(class_exists('MAD4B_Company_Term_Eligibility_V1',false))
            foreach(MAD4B_Company_Term_Eligibility_V1::catalog_sources() as $pt=>$owner)
                foreach((array)get_object_taxonomies($pt) as $tax)
                    if(taxonomy_exists($tax))$pairs[$pt.'|'.$tax]=true;
    }
    foreach(array_keys($pairs) as $pair){
        [$pt,$tax]=explode('|',$pair,2);
        delete_transient('shared_facet_post_terms_'.md5($pt.'|'.$tax));
    }
}
add_action('set_object_terms',static function($objectId){
    if(in_array(get_post_type((int)$objectId),['tours-and-activities','properties','transportations','post','help-center'],true)
       || (class_exists('MAD4B_Company_Term_Eligibility_V1',false)
           && isset(MAD4B_Company_Term_Eligibility_V1::catalog_sources()[get_post_type((int)$objectId)])))
        shared_presentation_native_filter_invalidate_v1((int)$objectId);
},30,1);
add_action('transition_post_status',static function($new,$old,$post){
    if($new!==$old && (in_array($post->post_type,['tours-and-activities','properties','transportations','post','help-center'],true)
       || (class_exists('MAD4B_Company_Term_Eligibility_V1',false)
           && isset(MAD4B_Company_Term_Eligibility_V1::catalog_sources()[$post->post_type]))))
        shared_presentation_native_filter_invalidate_v1((int)$post->ID);
},30,3);
// Meta add/delete are just as consequential as a value update for public
// Tour and extra_trip eligibility.
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)
    add_action($hook,static function($id,$postId,$key) {
        if(in_array($key,['tour_mode','tour_public_status'],true)
            && get_post_type((int)$postId)==='tours-and-activities')
            shared_presentation_native_filter_invalidate_v1((int)$postId);
    },30,3);
// At before_delete_post, the CPT still exists for scoped invalidation.
add_action('before_delete_post',static function($postId) {
    if(get_post_type((int)$postId))shared_presentation_native_filter_invalidate_v1((int)$postId);
},30,1);

// Shared term visibility contract consumed by native search/filter/navigation.
require_once __DIR__ . '/company-term-eligibility-v1.php';
