<?php
/** Backwards compatibility, only for previously published site identifiers. */
if (!defined('ABSPATH')) exit;
foreach ([
 'ETG_Traveller_Nationality_Switcher'=>'Travel_Booking_Traveller_Nationality_Switcher',
 'ETG_Traveller_Management'=>'Travel_Booking_Traveller_Management',
 'ETG_Traveller_Nationality_Checkout_Blocks'=>'Travel_Booking_Traveller_Nationality_Checkout_Blocks'
] as $old=>$new) if(!class_exists($old,false)&&class_exists($new,false))class_alias($new,$old);
add_filter('travel_booking/legacy_options',static function($fallback){
 $stored=get_option('etg_traveller_management_v1',[]);
 return is_array($stored)?$stored:$fallback;
});
add_filter('travel_booking/legacy_meta_key',static function($default,$key){
 $names=[
  '_travel_booking_traveller_preset_id'=>'_etg_traveller_preset_id',
  '_travel_booking_departure_inventory_mode'=>'_etg_departure_inventory_mode',
  '_travel_booking_minimum_departure_participants'=>'_etg_minimum_departure_participants',
  '_travel_booking_departure_max_capacity'=>'_etg_departure_max_capacity',
  '_travel_booking_small_party_routing'=>'_etg_small_party_routing',
  '_travel_booking_tm_registry_id'=>'_etg_tm_registry_id',
  '_travel_booking_tm_parent_registry_id'=>'_etg_tm_parent_registry_id',
  '_travel_booking_tm_traveller_indices'=>'_etg_tm_traveller_indices',
 ];
 return $names[$key]??$default;
},10,2);
add_filter('travel_booking/accept_legacy_nationality_contract',
 static fn($ok,$contract)=>$ok||$contract==='etg.traveller-nationality.v1',10,2);
add_filter('travel_booking/legacy_nationality_preference_keys',
 static fn($keys)=>array_merge((array)$keys,['etg_nationality_preference_v1']));
add_filter('travel_booking/legacy_cart_nationalities',
 static fn($old,$line)=>$line['etg_traveller_nationalities']??$old,10,2);
add_filter('travel_booking/legacy_checkout_extension',
 static fn($old,$request)=>$request['extensions']['etg-traveller-nationality']??$old,10,2);
add_filter('travel_booking_traveller_management/verified_count',
 static function($value,$line,$type){
  $old=apply_filters('etg_traveller_management/verified_count',$value,$line,$type);
  if(is_array($old)&&($old['contract']??'')==='etg.traveller-count.v1')
   $old['contract']=Travel_Booking_Traveller_Management::COUNT_CONTRACT;
  return $old;
 },9,3);
add_filter('travel_booking_traveller_management/verified_service_links',
 static function($links,$cart){
  $old=apply_filters('etg_traveller_management/verified_service_links',$links,$cart);
  if(is_array($old))foreach($old as &$link){
   if(is_array($link)&&($link['contract']??'')==='etg.traveller-management.v1')
    $link['contract']=Travel_Booking_Traveller_Management::CONTRACT;
  }
  return $old;
 },9,2);
add_filter('travel_booking_traveller_management/certified_relationship',
 static fn($verified,$root,$line,$link)=>
  $verified||apply_filters('etg_traveller_management/certified_relationship',false,$root,$line,$link),
 9,4);
add_shortcode('etg_nationality_switcher',['Travel_Booking_Traveller_Nationality_Switcher','header_shortcode']);
add_shortcode('etg_traveller_nationalities',['Travel_Booking_Traveller_Nationality_Switcher','traveler_shortcode']);
add_action('wp_ajax_etg_nationality_state',['Travel_Booking_Traveller_Nationality_Switcher','ajax_state']);
add_action('wp_ajax_nopriv_etg_nationality_state',['Travel_Booking_Traveller_Nationality_Switcher','ajax_state']);
$oldSubmit=static function(){
 $nonce=$_POST['_etg_nationality_nonce']??null;
 if(!is_string($nonce)||!wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)),'etg_save_nationality'))
  wp_die('Invalid nationality request','',['response'=>403]);
 $iso=sanitize_text_field(wp_unslash($_POST['nationality']??''));
 $result=Travel_Booking_Traveller_Nationality_Switcher::choose($iso);
 if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'',['response'=>400]);
 nocache_headers();wp_safe_redirect(wp_get_referer()?:home_url('/'));exit;
};
add_action('admin_post_etg_save_nationality',$oldSubmit);
add_action('admin_post_nopriv_etg_save_nationality',$oldSubmit);
add_filter('woocommerce_add_to_cart_validation',static function($valid){
 if(!isset($_POST['travel_booking_traveller_nationalities'])&&isset($_POST['etg_traveller_nationalities']))
  $_POST['travel_booking_traveller_nationalities']=$_POST['etg_traveller_nationalities'];
 return $valid;
},8,1);
add_filter('fluentform/validation_errors',static function($errors,$form){
 if(!is_array($form))return $errors;
 if(isset($form['etg_traveller_nationalities']) &&
    is_wp_error(Travel_Booking_Traveller_Nationality_Switcher::validate_list($form['etg_traveller_nationalities'])))
  $errors['etg_traveller_nationalities'][]='Invalid passport country';
 if(isset($form['etg_passport_country']) &&
    !Travel_Booking_Traveller_Nationality_Switcher::valid($form['etg_passport_country']))
  $errors['etg_passport_country'][]='Invalid passport country';
 return $errors;
},16,2);
