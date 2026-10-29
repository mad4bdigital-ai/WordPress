<?php
/** Lab-only denormalized index for native JetSmartFilters query var related_package_term_id. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_sync_rate_package_index($rate_id){
 $rate_id=absint($rate_id);
 if(get_post_type($rate_id)!=='tour-rates')return new WP_Error('invalid_rate');
 $tour_id=absint(get_post_meta($rate_id,'related_tour_id',true));
 if(get_post_type($tour_id)!=='tours-and-activities')return new WP_Error('invalid_tour_relation');
 $terms=wp_get_object_terms($tour_id,'package_levels',['fields'=>'ids']);
 if(is_wp_error($terms))return $terms;
 $terms=array_values(array_unique(array_map('absint',$terms)));
 $current=absint(get_post_meta($rate_id,'related_package_term_id',true));
 if(get_post_meta($rate_id,'_shared_package_index_source',true)==='tour_taxonomy'){
  if(count($terms)!==1){delete_post_meta($rate_id,'related_package_term_id');delete_post_meta($rate_id,'_shared_package_index_source');return ['rate_id'=>$rate_id,'status'=>'mirror_cleared_ambiguous'];}
  if($current!==$terms[0])update_post_meta($rate_id,'related_package_term_id',$terms[0]);
  return ['rate_id'=>$rate_id,'status'=>$current===$terms[0]?'mirror_current':'mirror_refreshed','package_term_id'=>$terms[0]];
 }
 if($current){return ['rate_id'=>$rate_id,'status'=>in_array($current,$terms,true)?'explicit_preserved':'explicit_review_required','package_term_id'=>$current];}
 if(count($terms)!==1)return ['rate_id'=>$rate_id,'status'=>'not_indexed_ambiguous_or_empty','term_count'=>count($terms)];
 update_post_meta($rate_id,'related_package_term_id',$terms[0]);
 update_post_meta($rate_id,'_shared_package_index_source','tour_taxonomy');
 return ['rate_id'=>$rate_id,'status'=>'indexed','package_term_id'=>$terms[0]];
}
function shared_product_sync_tour_package_indices($tour_id){
 $tour_id=absint($tour_id);if(get_post_type($tour_id)!=='tours-and-activities')return [];
 $ids=get_posts(['post_type'=>'tour-rates','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>(string)$tour_id,'suppress_filters'=>true]);
 return array_map('shared_product_sync_rate_package_index',$ids);
}
add_action('set_object_terms',static function($object_id,$terms,$tt_ids,$taxonomy){
 if($taxonomy==='package_levels'&&get_post_type($object_id)==='tours-and-activities')shared_product_sync_tour_package_indices($object_id);
},20,4);
add_action('save_post_tour-rates',static function($id,$post,$update){
 if($update&&!wp_is_post_revision($id))shared_product_sync_rate_package_index($id);
},40,3);
