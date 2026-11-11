<?php
/** Site-scoped Booking Fulfillment Policy. Controls intent routing, not prices or checkout. */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Booking_Fulfillment_Policy_V1')){
final class MAD4B_Booking_Fulfillment_Policy_V1 {
 const OPTION='etg-dynamic-pricing-controls_booking_fulfillment_mode';
 const META='_mad4b_booking_fulfillment_override_v1';
 const SLUG='mad4b-booking-fulfillment';
 const SAVE='mad4b_booking_fulfillment_save_v1';
 const VALUES=['inquiry_only','request_to_book','instant_booking'];
 public static function permitted():bool{
  return current_user_can('commerce_manage_pricing')&&current_user_can('manage_woocommerce');
 }
 public static function mode($mode):bool{return is_string($mode)&&in_array($mode,self::VALUES,true);}
 public static function global_mode():string{
  $value=get_option(self::OPTION,'inquiry_only');
  return self::mode($value)?$value:'inquiry_only';
 }
 public static function resolve(int $tour,int $rate=0):array|WP_Error{
  if(!class_exists('MAD4B_Unified_Booking_Fulfillment_V2'))
   return new WP_Error('unified_fulfillment_runtime_unavailable');
  if(get_post_type($tour)!=='tours-and-activities')
   return new WP_Error('fulfillment_tour_invalid');
  if($rate && get_post_type($rate)!=='tour-rates')
   return new WP_Error('fulfillment_rate_invalid');
  $decision=MAD4B_Unified_Booking_Fulfillment_V2::resolved($rate?:$tour);
  if(is_wp_error($decision))return $decision;
  if((int)$decision['product_id']!==$tour)return new WP_Error('fulfillment_rate_invalid');
  return ['contract'=>'mad4b.booking-fulfillment-policy.v2',
   'mode'=>$decision['effective_mode'],
   'source'=>$decision['blocker']==='tour_booking_globally_disabled'
     ?'booking_disabled':($decision['blocker']?:$decision['source']),
   'tour_id'=>$tour,'rate_id'=>$rate?:null,'family'=>'tours',
   'service_activation_mode'=>$decision['service_activation_mode'],
   'pricing_engine_independent'=>true,'checkout_authority'=>false];
 }
 public static function authorize_intent(int $tour,int $rate,string $intent):array|WP_Error{
  if(!class_exists('MAD4B_Unified_Booking_Fulfillment_V2'))
   return new WP_Error('unified_fulfillment_runtime_unavailable');
  if(get_post_type($tour)!=='tours-and-activities')
   return new WP_Error('fulfillment_tour_invalid');
  if($rate && get_post_type($rate)!=='tour-rates')
   return new WP_Error('fulfillment_rate_invalid');
  $decision=MAD4B_Unified_Booking_Fulfillment_V2::authorize($rate?:$tour,$intent);
  if(is_wp_error($decision))return $decision;
  if((int)$decision['product_id']!==$tour)
   return new WP_Error('fulfillment_rate_invalid');
  return ['contract'=>'mad4b.booking-fulfillment-policy.v2',
   'mode'=>$decision['effective_mode'],'source'=>$decision['blocker']?:$decision['source'],
   'tour_id'=>$tour,'rate_id'=>$rate?:null,'intent'=>$intent,'allowed'=>true,
   'booking_authority'=>false,'checkout_authority'=>false];
 }
 public static function labels():array{
  return ['inherit'=>'Inherit','inquiry_only'=>'Inquiry only',
   'request_to_book'=>'Request to Book','instant_booking'=>'Instant Booking (subject to readiness)'];
 }
 public static function boot():void{
  if(!is_admin())return;
  add_action('admin_init',[__CLASS__,'redirect_legacy_page'],2);
  // Native Product/Rate boxes and save handlers belong to Unified v2.
  // No second fulfillment form is registered by the legacy compatibility shim.
 }
 /** Old bookmarks route to the one authoritative native JetEngine settings page. */
 public static function redirect_legacy_page():void{
  if(!self::permitted()||($_SERVER['REQUEST_METHOD']??'GET')!=='GET'
    ||sanitize_key((string)($_GET['page']??''))!==self::SLUG)return;
  wp_safe_redirect(admin_url('admin.php?page=dynamic-pricing-controls'),302,'MAD4B Unified Pricing Controls');
  exit;
 }

}
MAD4B_Booking_Fulfillment_Policy_V1::boot();
}
