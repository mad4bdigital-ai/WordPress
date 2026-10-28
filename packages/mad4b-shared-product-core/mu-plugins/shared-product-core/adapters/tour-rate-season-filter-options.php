<?php
/** Lab-only: season options for filter 66030 derived from eligible rates of the current tour. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_rate_supported_season_options($tour_id) {
 $tour_id=absint($tour_id);
 if (get_post_type($tour_id)!=='tours-and-activities' || get_post_status($tour_id)!=='publish') return [];
 $rates=shared_product_eligible_tour_rate_ids($tour_id);
 $found=[];
 foreach ($rates as $id) {
  foreach (wp_get_post_terms($id,'season') as $term) {
   if ($term instanceof WP_Term) $found[$term->term_id]=$term->name;
  }
 }
 asort($found,SORT_NATURAL|SORT_FLAG_CASE);
 $result=[];foreach($found as $id=>$name)$result[(string)$id]=$name;
 return $result;
}
add_filter('jet-smart-filters/filters/filter-options',static function($options,$filter_id){
 if ((int)$filter_id!==66030)return $options;
 $current=get_post();
 $tour_id=($current && $current->post_type==='tours-and-activities')?(int)$current->ID:0;
 if (!$tour_id) {$queried=get_queried_object_id();if (get_post_type($queried)==='tours-and-activities')$tour_id=(int)$queried;}
 return [''=>'Select Season']+shared_product_rate_supported_season_options($tour_id);
},20,2);
