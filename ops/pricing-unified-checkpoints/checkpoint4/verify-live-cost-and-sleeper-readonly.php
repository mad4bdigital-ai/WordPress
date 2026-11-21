<?php
/** WP-CLI read-only acceptance for CP4 train routing and cost receipts. */
if(!defined('WP_CLI')||!WP_CLI)return;
if(!current_user_can('commerce_manage_pricing'))WP_CLI::error('NO_PRICING_AUTHORITY');
$gate='MAD4B_Package_Cost_Receipt_Gate_V1';
$admit='MAD4B_Package_Supplier_Admission_V1';
if(!class_exists($gate)||!class_exists($admit))WP_CLI::error('GATE_MISSING');
global $wpdb;
$stationIds=$wpdb->get_col($wpdb->prepare(
 "SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
 WHERE p.post_type='stations' AND p.post_status='publish'
 AND pm.meta_key=%s AND pm.meta_value=%s",
 'station_code','UPPER-EGYPT-BASHTEEL'
));
$station=(int)get_post_meta(54552,'trans_from_station_id',true);
if (!$stationIds || !in_array($station,array_map('intval',$stationIds),true) ||
    (int)get_post_meta(54553,'trans_to_station_id',true)!==$station)
    WP_CLI::error('STATION_CANONICAL_TRAIN_LINK_INVALID');
$out=[];
foreach([54552=>['86','19:45','09:25','from'],54553=>['87','17:15','06:25','to']] as $id=>$expected){
 $number=$expected[0];$depart=$expected[1];$arrival=$expected[2];$side=$expected[3];
 $route=['from'=>get_post_meta($id,'trans_from_station_id',true),
 'to'=>get_post_meta($id,'trans_to_station_id',true),
 'departure'=>get_post_meta($id,'trans_departure_time',true),
 'arrival'=>get_post_meta($id,'trans_arrival_time',true),
 'operator'=>get_post_meta($id,'trans_train_operator',true),
 'number'=>get_post_meta($id,'trans_train_number',true),
 'stops'=>get_post_meta($id,'trans_train_stops',true)];
 $stopVals=array_values((array)$route['stops']);
 $valid=get_post_type($id)==='transportations'&&$route['number']===$number &&
 $route['departure']===$depart&&$route['arrival']===$arrival&&$route['operator']==='Abela Trains'&&
 count($stopVals)===5&&
 (int)$route[$side]===$station&&
 (int)$stopVals[$side==='from'?0:4]['station_id']===$station&&
 get_post_meta($id,'trans_use_in_tour_rate_generation',true)==='0';
 $out[]=['train'=>$number,'pass'=>$valid,'boarding_station'=>$side==='from'?$route['from']:$route['to'],'stops'=>count($stopVals)];
 if(!$valid)WP_CLI::error('TRAIN_ROUTE_VERIFICATION_FAILED_'.$number);
}
$ids=[57562,60437,60439,60441,60443,60623,60627,60629];$total=0;$receipts=0;
foreach($ids as $id){
 $p=get_post_meta($id,'_mad4b_package_stay_verified_room_program_links_v1',true);
 foreach((array)($p['bindings']??[]) as $stage){$total++;if(is_array($stage['supplier_cost_receipt']??null))$receipts++;}
}
$res=$admit::inspect(60623,'2026-12-12','USD',2,0,0);
if(is_wp_error($res))WP_CLI::error('ADMISSION_FAILED_'.$res->get_error_code());
$stages=$res['components']['accommodation']['stages']??[];
$dates=array_map(fn($s)=>[$s['service_date'],$s['last_service_date']],$stages);
$expectedDates=[['2026-12-12','2026-12-12'],['2026-12-13','2026-12-13'],['2026-12-14','2026-12-16'],['2026-12-17','2026-12-17'],['2026-12-18','2026-12-18']];
if($dates!==$expectedDates || ($res['rate_generation_ready']??true)!==false ||
 ($res['checkout_ready']??true)!==false)WP_CLI::error('COST_STAGE_DIMENSION_VERIFICATION_FAILED');
echo 'CP4_READONLY_ACCEPTANCE '.wp_json_encode(['site'=>home_url(),
 'upper_egypt_station_id'=>$station,'train_evidence'=>$out,
 'package_count'=>count($ids),'stage_count'=>$total,'receipts_present'=>$receipts,
 'stage_dates'=>$dates,'rate_generation_ready'=>false,'checkout_ready'=>false]).PHP_EOL;
WP_CLI::success('CP4_READONLY_ACCEPTANCE_PASS');
