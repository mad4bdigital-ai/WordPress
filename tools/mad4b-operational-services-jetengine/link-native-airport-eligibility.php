<?php
/* Minimal native relation eligibility records grounded in explicitly named
   Day-1 airport meet-and-assist in original Package itinerary. */
wp_set_current_user(1);
if(!current_user_can('manage_woocommerce'))throw new RuntimeException('booking_authority_not_present');
$ids=[57562,60437,60439,60441,60443,60623,60627,60629];
$catalog=MAD4B_Operational_Services_V1::byVariant('airport-meet-assist');
if(!$catalog||get_post_type($catalog)!=='operational-services')
  throw new RuntimeException('native_airport_catalog_missing');
if(!class_exists('MAD4B_Tour_Leader_Itinerary_Audit_V1',false))
  throw new RuntimeException('tourleader_day_source_unavailable');
$rel=null;
foreach(jet_engine()->relations->get_active_relations() as $relation){
 if($relation->get_args('parent_object')==='posts::tours-and-activities'&&
    $relation->get_args('child_object')==='posts::operational-services'){
   if($rel!==null)throw new RuntimeException('duplicate_operational_relation');
   $rel=$relation;
 }
}
if(!$rel||$rel->get_args('type')!=='many_to_many')
 throw new RuntimeException('native_jet_relation_missing');
$do=getenv('MAD4B_ASSIGN_ELIGIBILITY')==='YES';
$out=['site'=>home_url('/'),'relation_id'=>$rel->get_id(),'catalog_service_id'=>$catalog,
   'mode'=>$do?'execute':'preflight','tours'=>[]];
foreach($ids as $tour){
 if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')
    throw new RuntimeException('unpublished_package_in_set:'.$tour);
 $serviceRows=MAD4B_Tour_Leader_Itinerary_Audit_V1::serviceRows($tour);
 $matches=array_values(array_filter($serviceRows,static fn($r)=>
    (int)$r['id']===$catalog && (int)$r['day']===1 &&
    $r['role']==='tour_leader_explicit_text_review'));
 if(count($matches)!==1)
    throw new RuntimeException('original_itinerary_airport_explicit_evidence_missing:'.$tour);
 $existing=array_map('intval',$rel->get_children($tour,'ids'));
 if($do&&!in_array($catalog,$existing,true)){
   $result=$rel->update($tour,$catalog);
   if(!$result)throw new RuntimeException('jet_relation_update_failed:'.$tour);
 }
 $now=array_map('intval',$rel->get_children($tour,'ids'));
 if($do&&!in_array($catalog,$now,true))
   throw new RuntimeException('jet_relation_readback_missing:'.$tour);
 $out['tours'][]=['tour_id'=>$tour,'day1_original_text_evidenced'=>true,
    'already_eligible'=>in_array($catalog,$existing,true),
    'eligible_after'=>in_array($catalog,$now,true),
    'no_rate_or_order_price_mutation'=>true];
}
echo 'JET_SOURCE_GROUNDED_ELIGIBILITY='.wp_json_encode($out,JSON_UNESCAPED_UNICODE)."\n";
