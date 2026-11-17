<?php
/** Source-owned tenant Booking V2 pricing/validation only. No Commerce activation. */
defined('ABSPATH')||exit;
add_action('plugins_loaded',static function():void {
 $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')&&MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
 $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')&&MAD4B_COMMERCE_RUNTIME_ENABLED===true;
 if(!$presentation||$commerce)return;
 if(!class_exists('MAD4B_SCP_Site_Profile',false))return;
 $profile=MAD4B_SCP_Site_Profile::status();
 if(empty($profile['configured'])||empty($profile['origin_match'])||
    empty($profile['environment_match'])||empty($profile['deployment_binding_match']))return;
 $root=WPMU_PLUGIN_DIR.'/shared-product-core/';
 $files=[
  'pricing/taxonomy-pricing-context.php',
  'pricing/market-price-policy.php',
  'pricing/booking-addons.php',
  'pricing/booking-modifiers.php',
  'pricing/composite-quote.php',
  'pricing/package-level-policy.php',
  'pricing/property-layer-policy.php',
  'adapters/nationality-source.php',
  'pricing/booking-configuration-quote-readonly-v2.php',
  'pricing/booking-configuration-v2.php',
  'adapters/presentation-form6-price-quote-v1.php',
 ];
 foreach($files as $rel){
  $path=$root.$rel;
  if(!is_file($path)||!is_readable($path)||is_link($path))return;
 }
 // This flag is never a WooCommerce/Original313 entitlement. PHP functions
 // included below are side-effect-free declarations except for the original
 // Form #6 REST and Fluent submission validation, which never writes orders.
 if(!defined('MAD4B_BOOKING_V2_READONLY_ENABLED'))
  define('MAD4B_BOOKING_V2_READONLY_ENABLED',true);
 foreach($files as $rel){
  $path=$root.$rel;
  if($rel==='adapters/presentation-form6-price-quote-v1.php')continue;
  require_once $path;
 }
 // The Booking V2 engine must exist before attaching the original Form #6.
 if(!function_exists('shared_product_booking_v2_preview')||
    !function_exists('shared_product_booking_configuration_quote')||
    !function_exists('shared_product_market_price_apply'))return;
 require_once $root.'adapters/presentation-form6-price-quote-v1.php';
},20);
