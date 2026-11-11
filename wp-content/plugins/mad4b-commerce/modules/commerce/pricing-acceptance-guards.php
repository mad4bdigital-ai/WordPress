<?php
/** Plugin Name: Pricing Acceptance Guards (Lab) */
if (!defined('ABSPATH') || (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || MAD4B_COMMERCE_RUNTIME_ENABLED!==true)) return;
function pricing_currency_ready($currency = null) {
 $currency = $currency ?: (function_exists('pricing_display_currency') ? pricing_display_currency() : get_option('woocommerce_currency','USD'));
 $base=get_option('woocommerce_currency','USD');
 return $base===$currency || (function_exists('pricing_public_fx_snapshot') && pricing_public_fx_snapshot() && pricing_public_fx_rate($base,$currency)!==null);
}
add_filter('woocommerce_is_purchasable',static function($allowed){ return $allowed && pricing_currency_ready(); },9999);
add_filter('woocommerce_available_payment_gateways',static function($gateways){ return pricing_currency_ready() ? $gateways : []; },9999);
add_action('woocommerce_check_cart_items',static function(){if(!pricing_currency_ready())wc_add_notice(__('Current exchange rates are unavailable. Please try again later.','woocommerce'),'error');});
add_shortcode('pricing_currency_switcher',static function(){return shortcode_exists('currency_switcher') ? do_shortcode('[currency_switcher]') : '';});
