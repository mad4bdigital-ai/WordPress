<?php
/** Source-neutral scheduling and quote eligibility decisions; inventory never silently assumed. Lab only. */
if (!defined('ABSPATH')||wp_get_environment_type()!=='local'||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
final class Shared_Product_Scheduling_Eligibility {
 const INTENTS=['inquiry','quote','request','instant'];
 private static function result(string $status,string $reason,string $source): array {
  return ['status'=>$status,'reason_code'=>$reason,'source'=>$source,
   'policy_version'=>Shared_Product_Schedule_Policy::VERSION];
 }
 public static function decide(int $tour,string $date,string $source='direct',?int $rate=null,string $intent='quote',string $audience='general',array $selections=[],array $extras=[],array $additional=[]): array|WP_Error {
  if(!in_array($intent,self::INTENTS,true))return new WP_Error('program_intent_invalid','Unsupported booking intent');
  if(!in_array($source,['direct','rate'],true))return new WP_Error('program_pricing_source_invalid');
  if(!in_array($audience,['general','egyptians'],true))return new WP_Error('program_pricing_audience_invalid');
  [$timezone,$tzSource]=Shared_Product_Schedule_Policy::timezone($tour);
  $on=Shared_Product_Program_Schedule::parse_date($date,$timezone);
  if(!$on)return new WP_Error('program_date_invalid','Invalid travel date');
  if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')
   return new WP_Error('program_tour_invalid','Published tour required');
  $schedule=Shared_Product_Program_Schedule::schedule_decision($tour);
  $weekdays=$schedule['weekdays'];
  $weekday=(int)$on->format('w');
  $exceptions=$schedule['exceptions'];
  $excluded=in_array($date,$exceptions['exclude'],true);
  $included=in_array($date,$exceptions['include'],true);
  $operates=$included||in_array($weekday,$weekdays,true);
  if($excluded)$operation=self::result('ineligible','program_date_excluded','tour_post_meta');
  elseif($included)$operation=self::result('eligible','program_date_included','tour_post_meta');
  elseif(!$weekdays)$operation=self::result('unknown',$schedule['reason_code'],$schedule['source']);
  else $operation=self::result($operates?'eligible':'ineligible',
   $operates?'program_operates':'weekday_not_operated',$schedule['source']);
  if(!is_array($selections)||array_diff(array_keys($selections),['property_id','unit_id','transport_id']))
   return new WP_Error('advance_notice_invalid_selections');
  if(!is_array($extras)||count($extras)>30)return new WP_Error('advance_notice_invalid_extras');
  $property=absint($selections['property_id']??0);$unit=absint($selections['unit_id']??0);
  if($unit && (!($property && Shared_Product_Advance_Notice::linked_unit($unit,$property,$rate??0))))
   return new WP_Error('advance_notice_unit_not_linked');
  if($rate && ($property||!empty($selections['transport_id'])) && function_exists('shared_product_rate_related_options')){
   $related=shared_product_rate_related_options($rate);
   if(is_wp_error($related))return $related;
   if($property&&!in_array($property,array_map('intval',array_column($related['properties']??[],'id')),true))
     return new WP_Error('advance_notice_property_not_in_rate');
   if(!empty($selections['transport_id'])){
    $transportIds=[];foreach(($related['transportation']??[]) as $list)foreach($list as $entry)$transportIds[]=absint($entry['id']??0);
    if(!in_array(absint($selections['transport_id']),$transportIds,true))
      return new WP_Error('advance_notice_transport_not_in_rate');
   }
  }
  $allowedExtras=array_map('intval',(array)get_post_meta($tour,'tour_optional_extras',true));
  foreach($extras as $extra){
   if(!is_array($extra))return new WP_Error('advance_notice_extra_invalid');
   $id=absint($extra['tour_id']??$extra['extra_tour_id']??0);
   if(!$id||!in_array($id,$allowedExtras,true))return new WP_Error('advance_notice_extra_not_linked');
   $extraRate=absint($extra['rate_id']??$extra['extra_rate_id']??0);
   if($extraRate&&(get_post_type($extraRate)!=='tour-rates'||(int)get_post_meta($extraRate,'related_tour_id',true)!==$id))
     return new WP_Error('advance_notice_extra_rate_not_linked');
  }
  $advance=Shared_Product_Advance_Notice::combine($tour,$rate,$selections,$extras,$additional);
  if(is_wp_error($advance))return $advance;
  $leadDays=$advance['days'];$leadSource=$advance['winner']['source']??'woocommerce_global';
  $earliest=(new DateTimeImmutable('today',$timezone))->modify('+'.$leadDays.' days');
  $lead=self::result($on>=$earliest?'eligible':'ineligible',
    $on>=$earliest?'lead_time_satisfied':'request_lead_time_not_met',$leadSource);
  $lead['days']=$leadDays;$lead['timezone']=$timezone->getName();
  $lead['earliest_date']=$earliest->format('Y-m-d');
  $lead['effective_components']=$advance['components'];
  $lead['winning_component']=$advance['winner'];
  $lead['aggregation_rule']=$advance['rule'];
  $price=self::result('unknown','source_price_not_verified','pricing_source');
  if($source==='rate'){
   if(!$rate||get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'||
      (int)get_post_meta($rate,'related_tour_id',true)!==$tour)
    $price=self::result('ineligible','rate_not_linked_to_tour','tour_rates');
   elseif(!function_exists('shared_product_booking_v2_rate_covers_date'))
    $price=self::result('unknown','rate_date_provider_missing','tour_rates');
   else {
    $covered=shared_product_booking_v2_rate_covers_date($rate,$date);
    $price=self::result($covered?'eligible':'ineligible',
     $covered?'rate_covers_travel_date':'rate_outside_travel_date','tour_rates');
   }
  }else{
   $unit=(string)get_post_meta($tour,'tour_price_unit',true);
   $raw=get_post_meta($tour,$audience==='egyptians'?'egyptians_start_price':'start_price',true);
   $currency=get_post_meta($tour,$audience==='egyptians'?'tour_egyptians_currency':'tour_base_currency',true);
   $ok=in_array($unit,['per_person','per_room','per_cabin','per_vehicle','per_day','per_trip','table'],true)
    &&is_numeric($raw)&&is_finite((float)$raw)&&(float)$raw>0
    &&is_string($currency)&&preg_match('/^[A-Z]{3}$/D',$currency);
   $price=self::result($ok?'eligible':'ineligible',$ok?'direct_price_fields_valid':'direct_price_fields_missing','tour_post_meta');
  }
  $inventory=self::result('not_required','inventory_not_required_for_quote','optional_inventory');
  $decision=self::result('eligible','preview_only_eligible','composite_eligibility');
  if($intent==='instant'){
   $inventory=self::result('unknown','inventory_not_verified','optional_inventory');
  }
  if($intent!=='inquiry'){
   foreach([$operation,$lead,$price] as $candidate){
    if($candidate['status']==='ineligible'){$decision=self::result('ineligible',$candidate['reason_code'],$candidate['source']);break;}
   }
   if($decision['status']==='eligible'&&$operation['status']==='unknown'){
    $allowQuote=(string)Shared_Product_Schedule_Policy::option('quote_unknown','allow')==='allow';
    $decision=self::result($intent==='quote'&&$allowQuote?'unknown':'ineligible',
     $intent==='quote'&&$allowQuote?'quote_pending_schedule_verification':'schedule_unresolved',$operation['source']);
   }
   if($intent==='instant'&&$decision['status']!=='ineligible')
    $decision=self::result('unknown','booking_inventory_not_verified','optional_inventory');
  }else $decision=self::result('eligible','inquiry_permitted_not_bookable','inquiry_policy');
  return ['schema'=>1,'tour_id'=>$tour,'travel_date'=>$date,'booking_intent'=>$intent,
   'source'=>$source,'rate_id'=>$rate,'timezone'=>$timezone->getName(),
   'program_schedule'=>$operation,'request_lead_time'=>$lead,'price_coverage'=>$price,
   'booking_eligibility'=>$decision,'inventory'=>$inventory,'booking_confirmed'=>false,
   'evaluated_at'=>gmdate('c'),'policy_version'=>Shared_Product_Schedule_Policy::VERSION];
 }
}
add_action('rest_api_init',static function(){
 register_rest_route('shared-product/v1','/program-decision',[
  'methods'=>WP_REST_Server::READABLE,'permission_callback'=>'__return_true',
  'args'=>['tour_id'=>['required'=>true,'sanitize_callback'=>'absint'],
   'travel_date'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
   'source'=>['sanitize_callback'=>'sanitize_key'],
   'rate_id'=>['sanitize_callback'=>'absint'],
   'intent'=>['sanitize_callback'=>'sanitize_key']],
  'callback'=>static function(WP_REST_Request $r){
   $result=Shared_Product_Scheduling_Eligibility::decide((int)$r->get_param('tour_id'),
    (string)$r->get_param('travel_date'),(string)($r->get_param('source')?:'direct'),
    $r->get_param('rate_id')?(int)$r->get_param('rate_id'):null,
    (string)($r->get_param('intent')?:'quote'),(string)($r->get_param('audience')?:'general'),
    array_filter(['property_id'=>absint($r->get_param('property_id')),
      'unit_id'=>absint($r->get_param('unit_id')),'transport_id'=>absint($r->get_param('transport_id'))]),
    $r->get_param('extra_tour_id')?[['tour_id'=>absint($r->get_param('extra_tour_id')),
       'rate_id'=>absint($r->get_param('extra_rate_id'))]]:[]);
   if(is_wp_error($result))return new WP_Error($result->get_error_code(),$result->get_error_message(),['status'=>422]);
   return rest_ensure_response($result);
  },
 ]);
},20);
