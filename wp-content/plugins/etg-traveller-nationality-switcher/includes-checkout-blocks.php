<?php
/** WooCommerce Checkout Block adapter — original order and cart APIs; no currency or price mutation. */
if (!defined('ABSPATH')) exit;
final class ETG_Traveller_Nationality_Checkout_Blocks {
 const NAMESPACE_ID='etg-traveller-nationality';
 public static function bookable_types(){return (array)apply_filters('etg_traveller_nationality/bookable_types',['tours-and-activities','tour-rates']);}
 public static function is_bookable($cart_item){
  $product=$cart_item['data']??null;
  return $product instanceof WC_Product && in_array(get_post_type($product->get_id()),self::bookable_types(),true);
 }
 /**
  * Resolve people from the EXISTING server-quoted booking contract, not Woo cart units.
  * Tour Rates currently keep their quote in shared_booking_configuration (v1);
  * its quantities.persons is independent from Woo quantity (normally one booking).
  * Booking v2 cart identity is preview-only, so never grant checkout entitlement
  * from its uncommitted draft/configuration or from browser-supplied counts.
  */
 public static function verified_booking_count($cart_item){
  $saved=$cart_item['shared_booking_configuration']??null;
  if(!is_array($saved))return 0;
  $product=$cart_item['data']??null;
  if(!$product instanceof WC_Product || get_post_type($product->get_id())!=='tour-rates')return 0;
  if((int)($cart_item['quantity']??0)!==1 ||
     (int)($saved['rate_id']??0)!==$product->get_id() ||
     !function_exists('shared_product_booking_configuration_matches'))return 0;
  // The source owns server-side recomputation and price-validity decisions.
  $fresh=shared_product_booking_configuration_matches($saved);
  if(is_wp_error($fresh) || !is_array($fresh) ||
     (int)($fresh['rate_id']??0)!==$product->get_id())return 0;
  $raw=$fresh['quantities']['persons']??null;
  $n=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>20]]);
  if($n===false)return 0;
  $option=$fresh['options']['travelers']??null;
  if($option!==null){
   $provided=filter_var($option,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>20]]);
   if($provided===false || $provided!==$n)return 0;
  }
  return $n;
 }
 public static function traveller_count($cart_item){
  $verified=self::verified_booking_count($cart_item);
  // A future booking adapter may return its separately VERIFIED count here.
  // Never use Woo quantity as an implicit fallback.
  $n=apply_filters('etg_traveller_nationality/required_count',$verified,$cart_item);
  return is_int($n)&&$n>=1&&$n<=20?$n:0;
 }
 public static function expected_items($cart){
  $out=[];foreach((array)$cart as $key=>$line)if(self::is_bookable($line)){
   $n=self::traveller_count($line);
   $out[(string)$key]=['product_id'=>(int)$line['product_id'],'variation_id'=>(int)($line['variation_id']??0),'quantity'=>(int)($line['quantity']??1),'travellers'=>$n];
  }
  return $out;
 }
 /** Validates the entire request before mutating any order item. */
 public static function validate_request($payload,$expected){
  if(!$expected)return [];
  if(!is_array($payload)||!isset($payload['travellers'])||!is_array($payload['travellers']))return new WP_Error('etg_block_passengers_missing','Passport nationalities are required for each booked traveller');
  $submitted=$payload['travellers'];
  if(count($submitted)!==count($expected)||array_diff(array_keys($submitted),array_keys($expected)))return new WP_Error('etg_block_cart_mismatch','Traveller nationality data does not match the current cart');
  $normalized=[];
  foreach($expected as $key=>$line){
   if(!$line['travellers'])return new WP_Error('etg_block_passenger_count','Traveller count must be 1–20 per bookable cart line');
   $value=ETG_Traveller_Nationality_Switcher::validate_list($submitted[$key]??null);
   if(is_wp_error($value)||count($value)!==$line['travellers'])return new WP_Error('etg_block_passengers_invalid','Select one valid passport nationality for every traveller');
   $normalized[$key]=$value;
  }
  return $normalized;
 }
 public static function apply_to_order($order,$request){
  if(!$order instanceof WC_Order||!$request instanceof WP_REST_Request||!function_exists('WC')||!WC()->cart)return;
  $lines=WC()->cart->get_cart();$expected=self::expected_items($lines);
  if(!$expected)return;
  $namespace=(array)($request['extensions'][self::NAMESPACE_ID]??[]);
  $validated=self::validate_request($namespace,$expected);
  if(is_wp_error($validated))throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException($validated->get_error_code(),$validated->get_error_message(),400);
  // Checkout Blocks can reconstruct order items independently of Classic hooks.
  // Match product + variation + quantity, and refuse ambiguous duplicate lines.
  $assignments=[];$used=[];
  foreach($expected as $key=>$line){
   $matches=[];
   foreach($order->get_items('line_item') as $item_id=>$item){
    if(isset($used[$item_id]))continue;
    if((int)$item->get_product_id()===$line['product_id']&&(int)$item->get_variation_id()===$line['variation_id']&&(int)$item->get_quantity()===$line['quantity'])$matches[$item_id]=$item;
   }
   if(count($matches)!==1)throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('etg_block_order_line_ambiguous','Unable to match traveller nationalities to an unambiguous booked tour',400);
   $id=array_key_first($matches);$assignments[]=[$matches[$id],$validated[$key]];$used[$id]=true;
  }
  foreach($assignments as [$item,$codes]){
   foreach($item->get_meta_data() as $entry){if(preg_match('/^Traveller [0-9]+ passport country \(ISO2\)$/D',(string)$entry->key))$item->delete_meta_data($entry->key);}
   foreach($codes as $i=>$country)$item->add_meta_data('Traveller '.($i+1).' passport country (ISO2)',$country,true);
   // WooCommerce persists item metadata with its checkout order lifecycle; do not save an orphan item early.
  }
 }
 public static function register_cart_source(){
  if(!function_exists('woocommerce_store_api_register_endpoint_data'))return;
  woocommerce_store_api_register_endpoint_data([
    'endpoint'=>\Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
    'namespace'=>self::NAMESPACE_ID,
    'data_callback'=>static function($item){return [
     'bookable'=>self::is_bookable($item),
     'travellers'=>self::is_bookable($item)?self::traveller_count($item):0,
    ];},
    'schema_callback'=>static function(){return ['properties'=>[
     'bookable'=>['type'=>'boolean','readonly'=>true],
     'travellers'=>['type'=>'integer','readonly'=>true]
    ]];},'schema_type'=>ARRAY_A
  ]);
 }
 public static function assets(){
  if(!function_exists('has_block')||!has_block('woocommerce/checkout',get_post()))return;
  // Prevent LiteSpeed concatenation from reordering the Woo Blocks runtime on checkout.
  add_filter('litespeed_optm_uri_exc',static function($items){
   $items=(array)$items;$path=(string)wp_parse_url(get_permalink(get_post()),PHP_URL_PATH);
   if($path&&!in_array($path,$items,true))$items[]=$path;
   return $items;
  },90);
  $file=plugin_dir_path(__FILE__).'assets/checkout-blocks.js';
  wp_enqueue_style('etg-nationality-switcher',plugins_url('assets/nationality.css',__FILE__),[],'0.3.0');
  wp_enqueue_script('etg-traveller-nationality-checkout-blocks',plugins_url('assets/checkout-blocks.js',__FILE__),['wp-element','wp-plugins','wp-data','wc-blocks-checkout','wc-blocks-data-store'],'0.3.0',true);
  $countries=ETG_Traveller_Nationality_Switcher::countries();$options=[];
  foreach($countries as $code=>$label)$options[]=['value'=>$code,'label'=>$label];
  wp_add_inline_script('etg-traveller-nationality-checkout-blocks','window.etgTravellerBlocks='.wp_json_encode(['namespace'=>self::NAMESPACE_ID,'types'=>self::bookable_types(),'countries'=>$options,'max'=>20,'preferred'=>ETG_Traveller_Nationality_Switcher::selected()]).';','before');
 }
 public static function boot(){
  add_action('wp_enqueue_scripts',[__CLASS__,'assets'],20);
  add_action('woocommerce_blocks_loaded',[__CLASS__,'register_cart_source'],20);
  add_action('woocommerce_store_api_checkout_update_order_from_request',[__CLASS__,'apply_to_order'],15,2);
 }
}
ETG_Traveller_Nationality_Checkout_Blocks::boot();
