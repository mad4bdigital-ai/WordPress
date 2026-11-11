<?php
/** Native read-only optional tour relations, shared by presentation and commerce. */
defined('ABSPATH')||exit;
if(!function_exists('shared_product_allowed_optional_tour_ids')){
function shared_product_allowed_optional_tour_ids($parent_rate_id) {
    $context=shared_product_tour_context($parent_rate_id);
    if(is_wp_error($context))return $context;
    return shared_product_optional_tour_ids_for_tour((int)$context['tour_id']);
}
}
/** Same native Extra allowlist for Rate-backed and Direct-backed parent previews. */
if(!function_exists('shared_product_optional_tour_ids_for_tour')){
function shared_product_optional_tour_ids_for_tour($parent) {
    $parent=absint($parent);
    if(get_post_type($parent)!=='tours-and-activities'||get_post_status($parent)!=='publish')return new WP_Error('invalid_optional_parent_tour');
    if((string)get_post_meta($parent,'tour_extras_status',true)!=='1')return [];
    $raw=get_post_meta($parent,'tour_optional_extras',true);
    if($raw===''||$raw===null||$raw===[])return [];
    if(is_string($raw))$raw=maybe_unserialize($raw);
    if(is_string($raw))$raw=array_filter(array_map('trim',explode(',',$raw)));
    if(!is_array($raw)||count($raw)>100)return new WP_Error('invalid_optional_tours_meta');
    $ids=[];
    foreach($raw as $value){
      if(!is_scalar($value)||!ctype_digit((string)$value))return new WP_Error('invalid_optional_tour_id');
      $id=absint($value);
      if($id===$parent || get_post_type($id)!=='tours-and-activities'||get_post_status($id)!=='publish')return new WP_Error('invalid_optional_tour_reference');
      $mode=get_post_meta($id,'tour_mode',true);
      if(!in_array($mode,['activity','extra_trip'],true))return new WP_Error('invalid_optional_tour_mode');
      if((string)get_post_meta($id,'tour_public_status',true)==='0')return new WP_Error('optional_tour_not_public');
      $ids[$id]=$id;
    }
    return array_values($ids);
}

}
