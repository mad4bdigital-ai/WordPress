<?php
/**
 * REV112 — Duration is the Tour taxonomy, not a Rate's Program FK.
 * Native Tour/Rate post meta remains the commercial authority. This adapter
 * neither writes automatically nor copies duration terms into Rate postmeta.
 */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)
    || !defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) return;

function shared_product_tour_duration_source_v1(int $tourId):array {
    if(get_post_type($tourId)!=='tours-and-activities') {
        return ['status'=>'invalid_tour','source'=>'taxonomy:duration-options','ids'=>[]];
    }
    $terms=wp_get_post_terms($tourId,'duration-options');
    if(is_wp_error($terms)) {
        return ['status'=>'source_unavailable','source'=>'taxonomy:duration-options','ids'=>[]];
    }
    $items=[];
    foreach($terms as $term){
        if(!$term instanceof WP_Term)continue;
        // Do not translate Rate meta. The source is the actual Tour taxonomy;
        // WPML handles its own linked post/term translation.
        $items[(int)$term->term_id]=['id'=>(int)$term->term_id,'label'=>$term->name];
    }
    ksort($items);
    return [
        'source'=>'tour_taxonomy:duration-options',
        'status'=>!$items?'missing':(count($items)===1?'resolved':'ambiguous_multiple_terms'),
        'ids'=>array_keys($items),
        'terms'=>array_values($items),
        'rate_meta_copied'=>false
    ];
}
function shared_product_rate_duration_source_v1(int $rateId):array {
    if(get_post_type($rateId)!=='tour-rates')
        return ['status'=>'invalid_rate','ids'=>[]];
    $tour=(int)get_post_meta($rateId,'related_tour_id',true);
    $base=shared_product_tour_duration_source_v1($tour);
    return $base+['tour_id'=>$tour,'rate_id'=>$rateId];
}
function shared_product_rate_program_write_allowed_v1(int $rateId,$value):bool {
    if(get_post_type($rateId)!=='tour-rates')return true;
    if(!is_scalar($value)||is_bool($value))return false;
    if($value==='' || (string)$value==='0')return true;
    if(!ctype_digit((string)$value))return false;
    $program=absint($value);
    $tour=(int)get_post_meta($rateId,'related_tour_id',true);
    if(get_post_type($tour)!=='tours-and-activities')return false;
    $source=shared_product_tour_itinerary_contract_v1($tour);
    return ($source['authoritative_source']??'')==='taxonomy:programs'
        && shared_product_tour_program_allowed_v1($tour,$program);
}
/** Block reintroduction of legacy duration values into the program FK. */
add_filter('add_post_metadata',static function($check,$postId,$key,$value){
    if($check!==null||$key!=='related_program_term_id')return $check;
    return shared_product_rate_program_write_allowed_v1((int)$postId,$value)?$check:false;
},20,4);
add_filter('update_post_metadata',static function($check,$postId,$key,$value){
    if($check!==null||$key!=='related_program_term_id')return $check;
    return shared_product_rate_program_write_allowed_v1((int)$postId,$value)?$check:false;
},20,4);
