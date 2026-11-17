<?php
/** MAD4B Form #6 date source context; read-only, independent of payment runtime. */
defined('ABSPATH') || exit;
/**
 * Read-only date-scoped source decision for Form #6.
 * One canonical Pricing Policy; no quote, order, allocation, supplier
 * confirmation, payment or authority derived from browser inputs.
 */
if(!function_exists('shared_form6_source_context_v1')){
function shared_form6_source_context_v1(int $tour,string $date) {
 $post=get_post($tour);
 if(!$post||$post->post_type!=='tours-and-activities'||$post->post_status!=='publish'
   ||$post->post_password!=='')return new WP_Error('form6_source_tour_invalid');
 $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
 if(!$day||$day->format('Y-m-d')!==$date||$date<gmdate('Y-m-d'))
   return new WP_Error('form6_source_date_invalid');
 if(!function_exists('shared_product_price_source_policy')||
    !function_exists('shared_product_booking_v2_rate_covers_date'))
   return new WP_Error('form6_source_policy_missing');
 $policy=shared_product_price_source_policy((string)get_post_meta($tour,'tour_mode',true));
 if(is_wp_error($policy))return $policy;
 $sourceTour=$tour;
 if(class_exists('MAD4B_Tour_Rate_Language_Policy_V1',false)){
  $context=MAD4B_Tour_Rate_Language_Policy_V1::resolve_context($tour);
  $candidate=absint($context['query_tour_id']??0);
  if($candidate&&get_post_type($candidate)==='tours-and-activities'&&
      get_post_status($candidate)==='publish')$sourceTour=$candidate;
 }
 $ids=get_posts(['post_type'=>'tour-rates','post_status'=>'publish',
   'posts_per_page'=>101,'fields'=>'ids','suppress_filters'=>true,
   'meta_key'=>'related_tour_id','meta_value'=>(string)$sourceTour]);
 if(count($ids)>100)return new WP_Error('form6_source_discovery_limit');
 $eligible=[];
 foreach($ids as $rid){
  $rid=absint($rid);
  if(!$rid||(string)get_post_field('post_password',$rid)!==''||
     (int)get_post_meta($rid,'related_tour_id',true)!==$sourceTour||
     get_post_meta($rid,'_booking_experience_qa_pricing_fixture',true)!=='')continue;
  if($sourceTour!==$tour &&
    (!class_exists('MAD4B_Tour_Rate_Language_Policy_V1',false)||
      !MAD4B_Tour_Rate_Language_Policy_V1::eligible_rate($tour,$rid)))continue;
  if(function_exists('shared_product_rate_locale_decision')&&
      empty(shared_product_rate_locale_decision($rid,$sourceTour)['allowed']))continue;
  if(in_array(strtolower((string)get_post_meta($rid,'stop_sale',true)),
       ['1','yes','true'],true))continue;
  if(!shared_product_booking_v2_rate_covers_date($rid,$date))continue;
  if(function_exists('pricing_rate_presentation')){
    $presented=pricing_rate_presentation($rid);
    if(!is_array($presented)||($presented['status']??'')!=='available')continue;
  }
  $eligible[]=$rid;
 }
 // Direct quotes must use this language Tour, even if a Rate uses a
 // default-language sibling after a verified WPML fallback.
 $directAmount=get_post_meta($tour,'start_price',true);
 $directCurrency=strtoupper((string)get_post_meta($tour,'tour_base_currency',true));
 $directUnit=(string)get_post_meta($tour,'tour_price_unit',true);
 $directAvailable=is_numeric($directAmount)&&is_finite((float)$directAmount)&&
   (float)$directAmount>0&&preg_match('/^[A-Z]{3}$/D',$directCurrency)&&
   in_array($directUnit,['per_person','per_trip','per_day','per_room',
     'per_cabin','per_vehicle'],true);
 $ratePreferred=$policy['rate_weight']>$policy['direct_weight']||
   ($policy['rate_weight']===$policy['direct_weight']&&$policy['tie_break']==='rate');
 $preferred=$ratePreferred?'rate':'direct';
 $preferredAvailable=$ratePreferred?!empty($eligible):$directAvailable;
 $source='unavailable';
 if($preferredAvailable)$source=$preferred;
 elseif(($policy['fallback']??'strict')==='next_valid'){
  if($ratePreferred && $directAvailable)$source='direct';
  elseif(!$ratePreferred && $eligible)$source='rate';
 }
 return ['contract'=>'mad4b.form6.source-date-context.v1','tour_id'=>$tour,
  'travel_date'=>$date,'source'=>$source,
  'eligible_rate_ids'=>$source==='rate'?array_values($eligible):[],
  'date_eligible_rate_count'=>count($eligible),
  'fallback_applied'=>$source!=='unavailable'&&$source!==$preferred,
  'authority'=>'source_hint_only_quote_and_payment_revalidated_by_server',
  'checkout_ready'=>false,'order_created'=>false];
}


}

add_action('rest_api_init',static function(){
 if(isset(rest_get_server()->get_routes()['/shared-product/v1/tour-form-source-context']))return;
 register_rest_route('shared-product/v1','/tour-form-source-context',[
  'methods'=>WP_REST_Server::READABLE,'permission_callback'=>'__return_true',
  'callback'=>static function(WP_REST_Request $request){
   $tour=absint($request->get_param('tour_id'));
   $date=$request->get_param('travel_date');
   $result=shared_form6_source_context_v1($tour,is_string($date)?$date:'');
   if(is_wp_error($result))return new WP_Error($result->get_error_code(),
       'Source context unavailable',['status'=>422]);
   $response=rest_ensure_response($result);
   $response->header('Cache-Control','private, no-store, max-age=0');
   $response->header('X-Robots-Tag','noindex, nofollow');
   return $response;
  }
 ]);

},25);
