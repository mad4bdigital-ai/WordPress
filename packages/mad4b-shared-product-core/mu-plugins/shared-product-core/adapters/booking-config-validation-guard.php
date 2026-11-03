<?php
/** Lab-only: refuse a malformed composite booking before cart item insertion. Never enable purchases. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
add_filter('woocommerce_add_to_cart_validation', static function($valid,$product_id,$quantity) {
 if (!$valid || get_post_type($product_id)!=='tour-rates') return $valid;
 if (!isset($_REQUEST['shared_booking_configuration'])) return $valid;
 $raw=wp_unslash($_REQUEST['shared_booking_configuration']);
 $selection=is_string($raw)?json_decode($raw,true):null;
 $audience=sanitize_key(wp_unslash($_REQUEST['shared_audience']??''));
 $occupancy=sanitize_key(wp_unslash($_REQUEST['shared_occupancy']??''));
 $quote=is_array($selection)?shared_product_booking_configuration_quote($product_id,$audience,$occupancy,$selection):new WP_Error('booking_invalid_configuration');
 if (is_wp_error($quote) || (int)$quantity!==1) {
  wc_add_notice(__('Invalid booking configuration.','woocommerce'),'error');
  return false;
 }
 return $valid;
},30,3);
