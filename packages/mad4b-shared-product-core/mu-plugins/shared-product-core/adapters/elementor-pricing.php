<?php
/** Elementor integration: shortcode widget [pricing_currency_price] and [pricing_price_display]. */
if (!defined('ABSPATH')) return;
add_action('elementor/init',static function(){
 // Use Elementor's native Shortcode widget; do not override existing dynamic tags.
 do_action('shared_product_pricing_elementor_ready');
});
