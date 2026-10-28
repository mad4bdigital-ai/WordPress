<?php
/**
 * Lab-only safety boundary for CPTWoo native product identity.
 * CPTWoo owns the mapping, while Rate/Quote registries own commercial approval.
 */
if (!defined('ABSPATH') || wp_get_environment_type()!=='local' ||
    !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

/** Child rate records have no standalone price/checkout authority by being WC-readable. */
function shared_product_cptwoo_rate_requires_review($id): bool {
 $id=absint($id);
 if(!$id)return false;
 $type=get_post_type($id);
 if(in_array($type,['property-rates','transport-rates'],true))return true;
 return $type==='tour-rates' &&
  (string)get_post_meta($id,'_shared_generated_rate_key',true)!=='';
}
add_filter('woocommerce_is_purchasable',static function($valid,$product){
 if($product instanceof WC_Product &&
    shared_product_cptwoo_rate_requires_review($product->get_id()))return false;
 return $valid;
},PHP_INT_MAX,2);
foreach(['woocommerce_product_get_price','woocommerce_product_get_regular_price','woocommerce_product_get_sale_price'] as $hook){
 add_filter($hook,static function($price,$product){
  if($product instanceof WC_Product &&
     shared_product_cptwoo_rate_requires_review($product->get_id()))return '';
  return $price;
 },PHP_INT_MAX,2);
}
add_filter('woocommerce_get_price_html',static function($html,$product){
 if($product instanceof WC_Product &&
    shared_product_cptwoo_rate_requires_review($product->get_id()))return '';
 return $html;
},PHP_INT_MAX,2);
add_filter('woocommerce_product_get_catalog_visibility',static function($visibility,$product){
 if($product instanceof WC_Product &&
    shared_product_cptwoo_rate_requires_review($product->get_id()))return 'hidden';
 return $visibility;
},PHP_INT_MAX,2);
add_filter('woocommerce_product_is_visible',static function($visible,$id){
 if(shared_product_cptwoo_rate_requires_review($id))return false;
 return $visible;
},PHP_INT_MAX,2);
add_filter('woocommerce_add_to_cart_validation',static function($valid,$product_id){
 if(shared_product_cptwoo_rate_requires_review($product_id)){
  if(function_exists('wc_add_notice'))
   wc_add_notice(__('This rate needs supplier and pricing approval before booking.','woocommerce'),'error');
  return false;
 }
 return $valid;
},PHP_INT_MAX,2);
