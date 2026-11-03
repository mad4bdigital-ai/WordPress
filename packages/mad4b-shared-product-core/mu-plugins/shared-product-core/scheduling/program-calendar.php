<?php
/**
 * Program scheduling (Lab): date presentation, not bookable inventory.
 * Read-only; never treats rate coverage as a confirmed reservation.
 */
if (!defined('ABSPATH') || wp_get_environment_type() !== 'local' ||
    !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

final class Shared_Product_Program_Schedule {
 public static function parse_date($value,?DateTimeZone $timezone=null){
  if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))return false;
  $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$timezone??wp_timezone());
  return $date&&$date->format('Y-m-d')===$value?$date:false;
 }
 public static function lead_days(int $tour): int {
  return Shared_Product_Schedule_Policy::lead_days($tour)[0];
 }
 public static function schedule_decision(int $tour): array {
  [$mode,$modeSource]=Shared_Product_Schedule_Policy::mode($tour);
  $weekdays=Shared_Product_Schedule_Policy::weekdays($tour);
  $source='taxonomy:'.Shared_Product_Schedule_Policy::weekdays_taxonomy();
  if($mode==='all_days'){$weekdays=range(0,6);$source=$modeSource;}
  elseif($mode==='unknown'){$weekdays=[];$source=$modeSource;}
  elseif($mode==='inherit'&&!$weekdays) {
   $fallback=(string)Shared_Product_Schedule_Policy::option('unset_mode','unknown');
   if($fallback==='all_days'){$weekdays=range(0,6);$source='woocommerce_fallback:all_days';}
   elseif($fallback==='inherit'){
    $raw=(string)Shared_Product_Schedule_Policy::option('default_weekdays','');
    $values=explode(',',$raw);$valid=[];
    foreach($values as $value){
     $value=trim($value);
     if($value!==''&&preg_match('/^[0-6]$/D',$value))$valid[]=(int)$value;
    }
    $weekdays=array_values(array_unique($valid));sort($weekdays);
    if($weekdays)$source='woocommerce_fallback:weekdays';
   }
  }
  $cruise=in_array(get_post_meta($tour,'cruise_trip_itinerary',true),['1',1,true,'true'],true);
  $runs=get_post_meta($tour,'cruise_runs',true);
  $hasRuns=is_array($runs)&&count($runs)>0;
  $exceptions=Shared_Product_Schedule_Policy::exceptional_dates($tour);
  // Explicit included dates are known, but all other dates remain unresolved if the
  // underlying weekly schedule is unknown. Never turn partial evidence into full availability.
  $status=$weekdays?'eligible':($exceptions['include']?'partial':'unknown');
  $reason=$weekdays?'weekly_schedule_valid':($exceptions['include']?'specific_operating_dates_defined':($cruise&&$hasRuns?'cruise_runs_unresolved':'program_schedule_unconfigured'));
  if(!$weekdays && $exceptions['include'])$source='tour_post_meta';
  return ['status'=>$status,'reason_code'=>$reason,'source'=>$source,'policy_version'=>Shared_Product_Schedule_Policy::VERSION,
    'mode'=>$mode,'weekdays'=>$weekdays,'exceptions'=>$exceptions,'cruise_itinerary_enabled'=>$cruise,'cruise_runs_present'=>$hasRuns];
 }
 public static function evaluate(int $tour_id,string $from,string $to,array $context=[]): array|WP_Error {
  if(get_post_type($tour_id)!=='tours-and-activities'||get_post_status($tour_id)!=='publish')
   return new WP_Error('invalid_program','A published tour is required',['status'=>404]);
  [$timezone,$tzOrigin]=Shared_Product_Schedule_Policy::timezone($tour_id);
  $start=self::parse_date($from,$timezone);$end=self::parse_date($to,$timezone);
  if(!$start||!$end||$end<$start||$start->diff($end)->days>366)
   return new WP_Error('invalid_program_date_window','Invalid date window or over 366 days',['status'=>400]);
  $schedule=self::schedule_decision($tour_id);
  [$lead,$leadSource]=Shared_Product_Schedule_Policy::lead_days($tour_id);
  $noticeReceipt=null;
  if($context){
   $rate=absint($context['rate_id']??0);
   $selections=array_filter([
     'property_id'=>absint($context['property_id']??0),
     'unit_id'=>absint($context['unit_id']??0),
     'transport_id'=>absint($context['transport_id']??0),
   ]);
   // Same component/link validation as Booking v2; rate coverage need not be current for calendar rendering.
   $decision=Shared_Product_Scheduling_Eligibility::decide(
     $tour_id,$from,$rate?'rate':'direct',$rate?:null,'quote','general',$selections);
   if(is_wp_error($decision))return $decision;
   $lead=(int)$decision['request_lead_time']['days'];
   $leadSource=(string)$decision['request_lead_time']['source'];
   $noticeReceipt=$decision['request_lead_time']['effective_components'];
  }
  $today=new DateTimeImmutable('today',$timezone);
  $earliest=$today->modify('+'.$lead.' days');
  $operating=[];$requestable=[];
  if($schedule['weekdays']||$schedule['exceptions']['include'])for($day=$start;$day<=$end;$day=$day->modify('+1 day')){
   $iso=$day->format('Y-m-d');
   if(in_array($iso,$schedule['exceptions']['exclude'],true))continue;
   if(!in_array((int)$day->format('w'),$schedule['weekdays'],true)
      &&!in_array($iso,$schedule['exceptions']['include'],true))continue;
   $operating[]=$iso;
   if($day >= $earliest)$requestable[]=$iso;
  }
  $rate_dates=[];$rate_error=null;
  if(class_exists('Shared_Product_Date_Service')){
   $result=Shared_Product_Date_Service::coverage($tour_id,null,null,$from,$to);
   if(is_wp_error($result))$rate_error=$result->get_error_code();
   else $rate_dates=array_values((array)($result['dates']??[]));
  }else $rate_error='rate_coverage_provider_missing';
  return [
   'schema'=>2,'tour_id'=>$tour_id,'timezone'=>$timezone->getName(),'timezone_source'=>$tzOrigin,
   'from'=>$from,'to'=>$to,
   'program_calendar'=>[
    'status'=>$schedule['status']==='eligible'?'weekday_schedule':($schedule['status']==='partial'?'specific_dates_only':'unconfigured'),
    'decision'=>$schedule,
    'source'=>$schedule['source'],
    'weekdays'=>$schedule['weekdays'],'operating_dates'=>$operating,
    'dates'=>$requestable, // Legacy form calendar: requestable dates, never confirmed booking dates.
    'lead_days'=>$lead,'lead_source'=>$leadSource,'advance_notice_components'=>$noticeReceipt,
    'earliest_request_date'=>$earliest->format('Y-m-d'),
    'cruise_itinerary_enabled'=>$schedule['cruise_itinerary_enabled'],
    'cruise_runs_present'=>$schedule['cruise_runs_present'],
   ],
   'pricing_coverage'=>['source'=>'tour_rates','dates'=>$rate_dates,'error'=>$rate_error,'semantics'=>'rate_date_coverage_only'],
   'booking'=>['inventory_checked'=>false,'booking_confirmed'=>false,
    'semantics'=>'program_schedule_not_booking_availability'],
   'policy_version'=>Shared_Product_Schedule_Policy::VERSION,
  ];
 }
}
add_action('wp_enqueue_scripts',static function(){
 if (!is_singular('tours-and-activities')) return;
 $tour=get_queried_object_id();
 if (!$tour || get_post_status($tour)!=='publish') return;
 $asset=WP_CONTENT_DIR.'/mu-plugins/woo-lab-assets/program-calendar-clients.js';
 if (!is_file($asset)) return;
 wp_enqueue_script('shared-product-program-calendar',content_url('mu-plugins/woo-lab-assets/program-calendar-clients.js'),[],(string)filemtime($asset),true);
 wp_add_inline_script('shared-product-program-calendar','window.SharedProgramCalendarConfig='.wp_json_encode([
  'tourId'=>(int)$tour,
  'endpoint'=>rest_url('shared-product/v1/program-schedule'),
  'decisionEndpoint'=>rest_url('shared-product/v1/program-decision')
 ]).';','before');
},31);

add_action('rest_api_init',static function(){
 register_rest_route('shared-product/v1','/program-schedule',[
  'methods'=>WP_REST_Server::READABLE,
  'permission_callback'=>'__return_true',
  'args'=>[
   'tour_id'=>['required'=>true,'sanitize_callback'=>'absint'],
   'from'=>['sanitize_callback'=>'sanitize_text_field'],
   'to'=>['sanitize_callback'=>'sanitize_text_field'],
  ],
  'callback'=>static function(WP_REST_Request $req){
   $tour=(int)$req->get_param('tour_id');
   $today=new DateTimeImmutable('today',wp_timezone());
   $from=$req->get_param('from')?:$today->format('Y-m-d');
   $to=$req->get_param('to')?:$today->modify('+90 days')->format('Y-m-d');
   return Shared_Product_Program_Schedule::evaluate($tour,(string)$from,(string)$to,[
    'rate_id'=>absint($req->get_param('rate_id')),
    'property_id'=>absint($req->get_param('property_id')),
    'unit_id'=>absint($req->get_param('unit_id')),
    'transport_id'=>absint($req->get_param('transport_id')),
   ]);
  },
 ]);
},16);
