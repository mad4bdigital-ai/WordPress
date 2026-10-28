<?php
/**
 * REV91 — presentation-only accommodation eligibility.
 * Eligibility for room controls must be sourced, never inferred from rate
 * availability, labels, generic package levels or the length of a trip.
 */
defined('ABSPATH') || exit;
if(!function_exists('shared_form6_accommodation_policy_v1')){
 function shared_form6_accommodation_policy_v1(int $tour): array {
  $types=wp_get_post_terms($tour,'tour-types',['fields'=>'slugs']);
  $types=is_wp_error($types)?[]:(array)$types;
  $packageContext=(bool)array_intersect(['packages','combined-tours'],$types);
  $result=['tour_id'=>$tour,'is_package_or_combined'=>$packageContext,
   'default_room_occupancy'=>false,'rates_with_stay'=>[],
   'source'=>'none','scope'=>'presentation_only'];
  if(get_post_type($tour)!=='tours-and-activities'||
     get_post_status($tour)!=='publish')return $result;
  $siblings=class_exists('ETG_Tour_Rate_Display')
    ?ETG_Tour_Rate_Display::language_ids($tour):[$tour];
  global $wpdb;
  $linked=$wpdb->get_col($wpdb->prepare(
   "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
    WHERE meta_key='related_tour_id' AND meta_value IN (".
    implode(',',array_fill(0,count($siblings),'%s')).")",
   ...array_map('strval',$siblings)));
  foreach(array_unique(array_map('absint',$linked)) as $rate){
   if(get_post_type($rate)!=='tour-rates'||
     get_post_status($rate)!=='publish')continue;
   $stages=get_post_meta($rate,'_booking_experience_stay_stages_v1',true);
   if(!is_array($stages))continue;
   foreach($stages as $stage){
    if(!is_array($stage)||intval($stage['nights']??0)<1)continue;
    $properties=array_map('absint',(array)($stage['properties']??[]));
    foreach($properties as $property){
     if($property&&get_post_type($property)==='properties'&&
        get_post_status($property)==='publish'){
      // Explicitly linked Rate accommodation is an exception for
      // non-package Tours too; its absence never implies accommodation.
      $result['rates_with_stay'][(string)$rate]=true;
      break 2;
     }
    }
   }
  }
  $result['source']=count($result['rates_with_stay'])
    ?'explicit_rate_stays':'none';
  return apply_filters('shared_form6_accommodation_policy_v1',$result,$tour);
 }
}
