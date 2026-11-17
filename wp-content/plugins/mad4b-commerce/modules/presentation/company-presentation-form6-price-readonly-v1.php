<?php
/** First-party compatibility facade for the one canonical Booking V2/Form #6 quote.
 * Original source adapters own all money and REST route registration in both
 * Commerce and company Presentation. No parallel price calculator remains.
 */
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Form6_Readonly_Price_Preview_V1',false)){
final class MAD4B_Form6_Readonly_Price_Preview_V1 {
 public const CONTRACT='mad4b.form6-readonly-price-preview.v1';
 private static function identity():bool {
  if(!class_exists('MAD4B_SCP_Site_Profile',false))return false;
  $p=MAD4B_SCP_Site_Profile::status();
  return !empty($p['configured'])&&!empty($p['origin_match'])&&
    !empty($p['environment_match'])&&!empty($p['deployment_binding_match']);
 }
 public static function status():array {
  $f=['shared_product_booking_v2_preview','shared_presentation_form6_price_quote',
      'shared_product_booking_configuration_quote','shared_product_resolve_price_sources',
      'shared_product_quote_snapshot','shared_product_strategy_quote',
      'shared_product_resolve_price_unit','shared_product_unit_extended_amount',
      'shared_product_extra_source_quote','shared_product_booking_v2_rate_covers_date',
      'pricing_display_currency','pricing_currency_convert'];
  $functions=[];foreach($f as $name)$functions[$name]=function_exists($name);
  $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')&&MAD4B_COMMERCE_RUNTIME_ENABLED===true;
  $presentation=defined('MAD4B_BOOKING_V2_READONLY_ENABLED')&&MAD4B_BOOKING_V2_READONLY_ENABLED===true;
  $deps=!in_array(false,$functions,true);
  return ['contract'=>self::CONTRACT,'enabled'=>self::identity()&&($commerce||$presentation)&&$deps,
    'all_readonly_dependencies'=>$deps,'functions'=>$functions,
    'runtime'=>$commerce?'commerce':($presentation?'presentation':'disabled'),
    'commerce_runtime_activated'=>$commerce,'quote_authority'=>'shared_product_booking_v2_preview',
    'checkout_authority'=>false,'order_write_authority'=>false];
 }
 public static function quote($raw){
  $s=self::status();
  if(!$s['enabled']||!function_exists('shared_presentation_form6_price_quote'))
   return new WP_Error('form6_booking_v2_dependencies_unavailable','Price preview unavailable',['status'=>422]);
  return shared_presentation_form6_price_quote($raw);
 }
 public static function register():void {
  // Original Form6 adapter registers exactly one REST route in both modes.
  // A second route would shadow the original pricing and submission owner.
  if(function_exists('shared_presentation_form6_price_quote'))return;
  // Fallback deliberately fails closed: no duplicate financial calculator.
 }
}
}
add_action('rest_api_init',[MAD4B_Form6_Readonly_Price_Preview_V1::class,'register'],25);
