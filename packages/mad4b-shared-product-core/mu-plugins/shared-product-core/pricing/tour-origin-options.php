<?php
/** Lab: Tour Origin choices strictly from the tour's assigned destination terms. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_tour_origin_choices($tour_id) {
    if (get_post_type($tour_id)!=='tours-and-activities') return [];
    $terms=wp_get_post_terms($tour_id,'destinations');
    if (is_wp_error($terms)) return [];
    $choices=[];
    foreach($terms as $term){
        if((int)$term->parent===0)continue; // continent is not an origin
        $choices[(int)$term->term_id]=$term->name;
    }
    return $choices;
}
add_filter('jet-engine/meta-fields/field-options',static function($options,$field){
    if(($field['name']??'')!=='tour_origin_location_term'||($field['options_source']??'')!=='shared_tour_destinations')return $options;
    $id=absint($_GET['post']??$_POST['post_ID']??0);
    $choices=shared_product_tour_origin_choices($id);
    return array_map(static fn($id,$name)=>['key'=>(string)$id,'value'=>$name],array_keys($choices),array_values($choices));
},60,2);
/** Invalid new selections cannot overwrite existing origin metadata. */
add_filter('update_post_metadata',static function($check,$object_id,$meta_key,$meta_value){
    if($meta_key!=='tour_origin_location_term'||get_post_type($object_id)!=='tours-and-activities')return $check;
    if($meta_value===''||$meta_value===null)return $check;
    $allowed=shared_product_tour_origin_choices($object_id);
    $value=function_exists('tk_tour_origin_sync_extract_id')?tk_tour_origin_sync_extract_id($meta_value):$meta_value;
    if(!is_scalar($value)||!ctype_digit((string)$value)||!isset($allowed[(int)$value]))return true; // Existing value preserved; invalid update short-circuited.
    return $check;
},10,4);

/** Also guard first insert, not only updates. Preserve the separate term-meta address sync. */
add_filter('add_post_metadata',static function($check,$object_id,$meta_key,$meta_value){
 if($meta_key!=='tour_origin_location_term'||get_post_type($object_id)!=='tours-and-activities'||$meta_value===''||$meta_value===null)return $check;
 $id=function_exists('tk_tour_origin_sync_extract_id')?tk_tour_origin_sync_extract_id($meta_value):$meta_value;
 return isset(shared_product_tour_origin_choices($object_id)[(int)$id])?$check:true;
},10,4);
