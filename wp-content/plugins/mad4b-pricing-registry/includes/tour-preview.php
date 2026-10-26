<?php
if (!defined('ABSPATH')) exit;
/** Delegates immutable tour previews to canonical Booking Configuration v2. */
final class MAD4B_Pricing_Tour_Preview {
 public static function preview($payload) {
  if(!is_array($payload))return new WP_Error('pricing_registry_tour_payload_invalid');
  if(!function_exists('shared_product_booking_v2_preview'))return new WP_Error('pricing_registry_tour_engine_missing');
  $quote=shared_product_booking_v2_preview($payload);
  if(is_wp_error($quote))return $quote;
  if(!is_array($quote)||($quote['checkout_ready']??null)!==false||($quote['schema_version']??null)!==2||!isset($quote['total'],$quote['currency']))return new WP_Error('pricing_registry_tour_quote_unverified');
  return ['registry_contract'=>MAD4B_Pricing_Source_Registry::CONTRACT,'domain'=>'tour','source'=>'booking_configuration_v2','quote'=>$quote,'mutation_performed'=>false,'checkout_authorized'=>false];
 }
}
