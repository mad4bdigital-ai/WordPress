<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$ids=[60629,60627,60623,60443,60441,60439,60437,57562];
$file=fopen('php://output','w');
fputcsv($file,['tour_id','tour_title','stage_key','nights','lodging_kind',
 'package_term_id','package_name','candidate_properties','candidate_room_units',
 'candidate_units_with_max_occupancy','approved_bookable_units',
 'price_status','supplier_cost_status','needed_evidence']);
foreach($ids as $tour){
 if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')continue;
 $value=get_post_meta($tour,'_mad4b_package_stay_verified_room_program_links_v1',true);
 if(!is_array($value)||!is_array($value['bindings']??null))continue;
 foreach($value['bindings'] as $stage){
  if(!is_array($stage))continue;
  $tiers=$stage['room_candidate_refs']??[];
  if(!is_array($tiers)||!$tiers)$tiers=['0'=>[]];
  foreach($tiers as $term=>$candidates){
   $candidateProps=0;$candidateUnits=0;$occupancy=0;$approved=0;
   foreach((array)$candidates as $candidate){
    if(!is_array($candidate))continue;
    $candidateProps++;
    $candidateUnits+=count((array)($candidate['unit_ids_en']??[]));
    $occupancy+=(int)($candidate['units_with_recorded_max_occupancy']??0);
    $approved+=count((array)($candidate['commercially_approved_unit_ids']??[]));
   }
   $termObj=(int)$term?get_term((int)$term,'package_levels'):null;
   $termName=$termObj instanceof WP_Term?$termObj->name:'No assigned level';
   $needs=['supplier_rate_contract','valid_for_arrival_date',
    'selected_room_or_cabin','verified_capacity','occupancy_and_meal_plan'];
   fputcsv($file,[
    $tour,html_entity_decode(get_the_title($tour),ENT_QUOTES,'UTF-8'),
    (string)($stage['stage_key']??''),(int)($stage['nights']??0),
    (string)($stage['lodging_kind']??''),(int)$term,$termName,
    $candidateProps,$candidateUnits,$occupancy,$approved,
    (string)($stage['price_status']??'unknown'),
    'NOT_CERTIFIED',implode(';',$needs),
   ]);
  }
 }
}
fclose($file);
