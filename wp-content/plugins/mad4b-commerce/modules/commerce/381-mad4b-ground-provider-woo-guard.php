<?php
/**
 * Plugin Name: MAD4B Ground Provider WooCommerce Eligibility Guard
 * Version: 0.1.0
 * Description: Provider vehicle-class catalogue is never a priced transport service or confirmed booking inventory.
 */
defined('ABSPATH') || exit;
function mad4b_ground_import_requires_quote($id):bool {
 $id=absint($id);
 return $id>0 && get_post_type($id)==='transportations' &&
   (string)get_post_meta($id,'_mad4b_ground_provider_id',true)!=='';
}
add_filter('woocommerce_is_purchasable',static function($valid,$product){
 if($product instanceof WC_Product && mad4b_ground_import_requires_quote($product->get_id()))
   return false;
 return $valid;
},PHP_INT_MAX,2);
add_filter('woocommerce_add_to_cart_validation',static function($valid,$productId){
 if(mad4b_ground_import_requires_quote($productId)){
   if(function_exists('wc_add_notice'))wc_add_notice(
     __('Please request an authenticated supplier quote for this transfer.','woocommerce'),'error');
   return false;
 }
 return $valid;
},PHP_INT_MAX,2);
add_action('woocommerce_check_cart_items',static function(){
 if(!function_exists('WC') || !WC()->cart)return;
 foreach(WC()->cart->get_cart() as $line){
   $id=absint($line['product_id']??0);
   if(mad4b_ground_import_requires_quote($id)){
      wc_add_notice(__('Supplier quote and vehicle availability must be confirmed before checkout.','woocommerce'),'error');
      break;
   }
 }
},PHP_INT_MAX);
