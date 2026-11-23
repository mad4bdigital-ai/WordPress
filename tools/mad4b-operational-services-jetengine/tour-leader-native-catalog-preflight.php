<?php
if (!class_exists('MAD4B_Tour_Leader_Itinerary_Audit_V1',false))
 throw new RuntimeException('tour_leader_audit_plugin_missing');
$c='MAD4B_Tour_Leader_Itinerary_Audit_V1';
$catalog='MAD4B_Operational_Services_V1';
$records=[];$valid=true;
foreach([57562,60437,60439,60441,60443,60623,60627,60629] as $tour){
 $days=$c::sourceRows($tour);
 $rows=$c::serviceRows($tour);
 $statuses=[];
 foreach($rows as $r){
  $id=(int)$r['id'];
  $flags=$r['proof'];
  $ok=$id>0&&get_post_type($id)===$catalog::CPT
      && $flags['catalog_service_post_id']===$id
      && $flags['included_in_tour_rate']===false
      && $flags['supplier_cost_verified']===false
      && $flags['no_double_billing']===true;
  $valid=$valid&&$ok;
  $statuses[$r['role']]=($statuses[$r['role']]??0)+1;
 }
 $records[]=['tour'=>$tour,'days'=>count($days),'candidate_services'=>count($rows),
   'roles'=>$statuses,'first_service'=>count($rows)?[
     'post_id'=>$rows[0]['id'],'name'=>$rows[0]['title'],
     'day'=>$rows[0]['day'],'role'=>$rows[0]['role']]:null];
}
$sample=$c::classify('Arrival at Cairo Airport',
    'Our tour manager waits for your arrival and provides meet and assist.');
$guide=$c::classify('Cairo day tour',
    'Your Egyptologist guide explains the Great Pyramids.');
$all=$c::auditRows();$category=[];
foreach($all as $r)$category[$r['status']]=($category[$r['status']]??0)+1;
echo 'OPERATIONAL_TOUR_LEADER_PREFLIGHT='.wp_json_encode([
 'site'=>home_url('/'),'days_reviewed'=>count($all),'by_status'=>$category,
 'original_tour_days'=>$records,'linked_catalog_ids_valid'=>$valid,
 'arrival_meet_assist_no_standby_fee'=>($sample['variant']==='airport_meet_assist' &&
     $sample['extra_standby_billable']===false),
 'guide_only_not_tourleader'=>$guide['status']==='not_indicated',
 'commercial_prices_unchanged'=>true],JSON_UNESCAPED_UNICODE)."\n";
