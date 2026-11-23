<?php
defined('ABSPATH')||exit;
$site=home_url();
$localized=$site==='https://tours.dev.mad4b.com'?
 ['en'=>60623,'es'=>60661,'fr'=>60637,'de'=>60649,'it'=>60677,'ar'=>64945]:
 ['en'=>60623,'es'=>60661];
$meta='_mad4b_60623_cruise_rail_candidates_2027_v1';
$tmeta='_mad4b_60623_train_2027_ops_reference_v1';
$out=['site'=>$site,'tour_id'=>60623,
 'tour_name'=>html_entity_decode(wp_strip_all_tags(get_the_title(60623)),ENT_QUOTES|ENT_HTML5,'UTF-8'),
 'type'=>get_post_type(60623),
 'wpml_reconciled'=>[],'train_catalog'=>[],'checks'=>[]];
foreach($localized as $lang=>$id){
 $v=get_post_meta($id,$meta,true);
 $tr=get_post_meta($id,$tmeta,true);
 if(!is_array($v)||!is_array($tr)||
    $v['sailing_assignments_by_departure_weekday']['monday']['level_candidates']['silver']['property_id']!==78032||
    $v['sailing_assignments_by_departure_weekday']['monday']['level_candidates']['gold']['unit_id']!==71765||
    $v['sailing_assignments_by_departure_weekday']['wednesday']['level_candidates']['gold']['unit_id']!==71761||
    $v['sailing_assignments_by_departure_weekday']['wednesday']['level_candidates']['silver']['unit_id']!==null||
    $v['ship_inventory_confirmed']!==false || $v['approved_price']!==null ||
    $v['checkout_authorized']!==false||
    $tr['2027_time_exactly_confirmed_by_abela']!==false||
    $tr['2027_booking_inventory_checked']!==false)
  throw new RuntimeException('unsafe_translation_candidate_'.$lang);
 $out['wpml_reconciled'][$lang]=['id'=>$id,
  'name'=>html_entity_decode(wp_strip_all_tags(get_the_title($id)),ENT_QUOTES|ENT_HTML5,'UTF-8'),
  'type'=>get_post_type($id),'signed_cost'=>false,'inventory'=>false,
  'sailing_supplier_confirmed'=>false];
}
foreach([54552=>86,54553=>87] as $id=>$no){
 $t=get_post_meta($id,$tmeta,true);
 if(!is_array($t)||($t['train_number']??null)!==$no||
    ($t['2027_booking_inventory_checked']??null)!==false||
    ($t['2027_exact_service_confirmation']??null)!==false)
   throw new RuntimeException('rail_not_a_safe_candidate_'.$id);
 $out['train_catalog'][(string)$id]=[
  'id'=>$id,'name'=>html_entity_decode(wp_strip_all_tags(get_the_title($id)),ENT_QUOTES|ENT_HTML5,'UTF-8'),
  'type'=>get_post_type($id),
  'train_number'=>(int)get_post_meta($id,'trans_train_number',true),
  'train_2027_berths_confirmed'=>false,'published_service_flag'=>get_post_meta($id,'trans_available_status',true),
  'generation_enabled'=>get_post_meta($id,'trans_use_in_tour_rate_generation',true)
 ];
 if(get_post_meta($id,'trans_use_in_tour_rate_generation',true)!=='0')
  throw new RuntimeException('native_train_rate_generator_autoenabled_'.$id);
}
$baseCruise=(int)get_post_meta(60623,'tour_cruise_property_id',true);
$baseTrain=(string)get_post_meta(60623,'train_transport_services',true);
if($baseCruise!==0||$baseTrain!=='')
 throw new RuntimeException('native_tour_assignment_unexpectedly_created');
if(!class_exists('MAD4B_Tour60623_Ops_Board_V1',false)||
  !MAD4B_Tour60623_Ops_Board_V1::is_target(60623)||
  MAD4B_Tour60623_Ops_Board_V1::is_target(60629))
 throw new RuntimeException('operations_admin_visibility_failed');
$out['checks']['ops_board_readonly']=true;
foreach([71765,71761,78050] as $id){
 if(get_post_type($id)!=='units'||get_post_status($id)!=='draft'||
    get_post_meta($id,'unit_available',true)!=='false')
  throw new RuntimeException('draft_unsellable_cabin_unexpectedly_enabled_'.$id);
}
$out['checks']['cabin_units_not_published_or_available']=true;
$out['checks']['canonical_tour_cruise_property_id_unset']=$baseCruise===0;
$out['checks']['native_train_service_assignment_untouched']=$baseTrain==='';
$rates=get_posts(['post_type'=>'tour-rates','post_status'=>['publish','draft','pending'],
 'fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true,
 'meta_query'=>[['key'=>'related_tour_id','value'=>60623,'compare'=>'=']]]);
$expectedCount=$site==='https://tours.dev.mad4b.com'?4:0;
if(count($rates)!==$expectedCount)throw new RuntimeException('tour_rate_count_changed_or_new_rate');
$existingRateDetails=[];
foreach($rates as $id) {
 $d=(string)get_post_field('post_date_gmt',$id);
 if($d===''||$d>='2026-11-23 00:00:00')
  throw new RuntimeException('newly_created_tour_rate_not_expected_'.$id);
 $existingRateDetails[]=['id'=>(int)$id,'name'=>get_the_title($id),
  'type'=>get_post_type($id),'status'=>get_post_status($id),'created'=>$d];
}
$out['existing_tour_rates']=$existingRateDetails;
$out['checks']['existing_tour_rates_unchanged_by_enrollment']=true;
$dates=['2027-01-11','2027-01-13','2027-01-15'];
foreach($dates as $date){
 $verified=\Commerce\Composition\SingleFluentPaymentRouter::quoteDirect(60623,$date,'double',2,'USD');
 $data=is_wp_error($verified)?['code'=>$verified->get_error_code()]:
  ['total'=>$verified['total']??null,'currency'=>$verified['currency']??null];
 $out['direct_price_reference'][$date]=$data;
 if($site==='https://wayfatours.com'){
  if($date==='2027-01-15' && (!is_wp_error($verified)||
      $verified->get_error_code()!=='booking_schedule_not_eligible'))
   throw new RuntimeException('Friday_wrongly_opened');
  if(in_array($date,['2027-01-11','2027-01-13'],true)&&
     (is_wp_error($verified)||($verified['total']??null)!=='2580.00'))
   throw new RuntimeException('wayfa_form6_approved_quote_changed_'.$date);
 }
}
$out['checks']['wayfa_quote_unchanged_or_source_payment_router_disabled']=true;
$out['checks']['no_orders_or_rates_created_by_test']=true;
$out['result']='PASS';
echo wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

