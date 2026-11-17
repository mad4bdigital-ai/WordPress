<?php
/** runtime-authorized: season options for filter 66030 derived from eligible rates of the current tour. */
if (!defined('ABSPATH') || (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || MAD4B_COMMERCE_RUNTIME_ENABLED!==true)) return;
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
 // A deep-linked, valid Season can yield zero Rates. Keep its option visible
 // so JetSmartFilters can show the actual selected value/Active Season chip
 // and the visitor can clear it. Never advertise an unmatched Rate.
 if(class_exists('MAD4B_Rate_Season_Calendar_Integrity_V1',false)){
  $selected=MAD4B_Rate_Season_Calendar_Integrity_V1::selected_season();
  if(!empty($selected['active'])&&empty($selected['invalid'])
     &&($selected['term']??null) instanceof WP_Term){
   $term=$selected['term'];
   $policy=MAD4B_Rate_Season_Calendar_Integrity_V1::policy_term_id($term);
   if($policy>0 && get_term_meta($policy,'season_enabled',true)==='1'
      &&has_term((int)$term->term_id,'season',$tour_id))
       $found[(int)$term->term_id]=$term->name;
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
