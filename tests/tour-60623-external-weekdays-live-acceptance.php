<?php
defined('ABSPATH')||exit;
$ids=['en'=>60623, 'es'=>60661];
if(home_url()==='https://tours.dev.mad4b.com')$ids+=['fr'=>60637,'de'=>60649,'it'=>60677,'ar'=>64945];
$old=(string)apply_filters('wpml_current_language','en');
$out=['site'=>home_url(),'tour_id'=>60623,'program_name'=>get_the_title(60623),
 'type'=>get_post_type(60623),'weekdays_source'=>'operator_research',
 'checked_languages'=>[],'sample_dates'=>[],'commercial_evidence_status'=>'requires_supplier_date_confirmation'];
try {
 foreach($ids as $lang=>$id){
  do_action('wpml_switch_language',$lang);
  $before=(string)apply_filters('wpml_current_language','');
  $decision=Shared_Product_Program_Schedule::schedule_decision($id);
  $after=(string)apply_filters('wpml_current_language','');
  $receipt=get_post_meta($id,'_mad4b_operating_weekdays_external_evidence_v1',true);
  if($decision['status']!=='eligible'||$decision['weekdays']!==[1,3]||
     $before!==$after||($receipt['bookable_inventory_confirmed']??null)!==false)
   throw new RuntimeException('Localized weekday or provenance mismatch '.$lang);
  $out['checked_languages'][$lang]=[
   'id'=>$id,
   'name'=>html_entity_decode(wp_strip_all_tags(get_the_title($id)),ENT_QUOTES|ENT_HTML5,'UTF-8'),
   'status'=>$decision['status'],'weekdays'=>$decision['weekdays'],
   'inventory_confirmed'=>false,'language_restored'=>true];
 }
 do_action('wpml_switch_language','en');
 foreach(['2027-01-11','2027-01-13','2027-01-15','2027-01-18','2027-01-20'] as $date){
  $ctx=MAD4B_Signed_Schedule_Bridge_V1::with_site_lead_policy(60623,
    static fn()=>Shared_Product_Scheduling_Eligibility::decide(60623,$date,'direct',null,'request','general',[],[],[]));
  $verify=MAD4B_Signed_Schedule_Bridge_V1::verify(60623,$date,'direct',0);
  $code=is_wp_error($verify)?$verify->get_error_code():($verify['status']??null);
  $out['sample_dates'][]=[
   'date'=>$date,'weekday'=>date('l',strtotime($date)),
   'operating_status'=>is_wp_error($ctx)?null:($ctx['program_schedule']['status']??null),
   'operating_reason'=>is_wp_error($ctx)?$ctx->get_error_code():($ctx['program_schedule']['reason_code']??null),
   'signed_result'=>$code,
   'supplier_inventory_checked'=>is_wp_error($verify)?false:($verify['inventory_checked']??null)
  ];
  if($date==='2027-01-15' && !is_wp_error($ctx)&&($ctx['program_schedule']['status']??'')==='eligible')
    throw new RuntimeException('Friday incorrectly marked as a Tour start');
 }
}finally {do_action('wpml_switch_language',$old);}
$out['result']='PASS';
echo wp_json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;

