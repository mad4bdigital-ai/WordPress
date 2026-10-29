<?php
/** Lab-only context-aware options for the JetSmartFilters package select clone. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_rate_supported_package_options($tour_id){
 $tour_id=absint($tour_id);
 if(get_post_type($tour_id)!=='tours-and-activities')return [];
 $allowed=wp_get_object_terms($tour_id,'package_levels',['fields'=>'ids']);
 if(is_wp_error($allowed)||!$allowed)return [];
 $allowed=array_map('absint',$allowed);
 $rates=shared_product_eligible_tour_rate_ids($tour_id);
 $available=[];
 foreach($rates as $id){
  $tier=absint(get_post_meta($id,'related_package_term_id',true));
  if($tier && in_array($tier,$allowed,true))$available[$tier]=true;
 }
 $options=[];
 foreach(array_keys($available) as $tier){$term=get_term($tier,'package_levels');if($term&&!is_wp_error($term))$options[(string)$tier]=$term->name;}
 return $options;
}
add_filter('jet-smart-filters/filters/filter-options',static function($options,$filter_id){
 if((int)$filter_id!==66104)return $options;
 $current=get_post();
 $tour_id=($current && $current->post_type==='tours-and-activities')?(int)$current->ID:0;
 if(!$tour_id){$queried=get_queried_object_id();if(get_post_type($queried)==='tours-and-activities')$tour_id=$queried;}
 if(get_post_type($tour_id)!=='tours-and-activities')return [''=>'Select Package'];
 return [''=>'Select Package']+shared_product_rate_supported_package_options($tour_id);
},20,2);
