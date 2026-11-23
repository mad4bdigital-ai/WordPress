<?php
if (!class_exists('MAD4B_Booking_Operations_Source_Families_V1',false))
 throw new RuntimeException('native_booking_operations_source_missing');
wp_set_current_user(1);
$test='MAD4B_Booking_Operations_Source_Families_V1';
$out=['site'=>home_url('/'),'native_catalog_exists'=>post_type_exists('operational-services'),
  'catalog_9_post_count'=>(int)(wp_count_posts('operational-services')->publish??0)];
$catalog=MAD4B_Operational_Services_V1::source(
     MAD4B_Operational_Services_V1::byVariant('airport-meet-assist'));
$out['catalog_source_status']=is_wp_error($catalog)?$catalog->get_error_code():[
   'cost_approved'=>$catalog['cost_approved'],
   'supplier_mode'=>$catalog['supplier_mode'],
   'cost_state'=>$catalog['cost_state']];
if(str_contains(home_url('/'),'wayfatours.com')){
  $order=wc_get_order(69842);
  if(!$order)throw new RuntimeException('test_order_69842_missing');
  $rate=(int)$order->get_meta('_request_rate_id');
  $res=$test::compose($order,60629,$rate,0);
  $rows=array_values(array_filter($res['rows'],static fn($r)=>($r['family']??'')==='tour_leader'));
  $safe=count($rows)>0;
  foreach($rows as $r)
    $safe=$safe&&get_post_type((int)$r['id'])==='operational-services'&&
       ($r['proof']['no_double_billing']??null)===true&&
       ($r['proof']['included_in_tour_rate']??null)===false&&
       ($r['proof']['supplier_cost_verified']??null)===false;
  $out['order_source_qa']=[
    'order_id'=>$order->get_id(),'tour'=>$res['source']['contract'],
    'tour_leader_review_candidates'=>count($rows),
    'candidate_post_ids'=>array_column($rows,'id'),
    'source_family_count'=>$res['source']['service_row_count'],
    'verified_no_extra_billing'=>$safe];
}
echo 'WOO_TOUR_LEADER_CANDIDATE_QA='.wp_json_encode($out,JSON_UNESCAPED_UNICODE)."\n";
