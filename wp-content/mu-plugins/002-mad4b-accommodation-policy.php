<?php
/** Plugin Name: MAD4B Accommodation Policy Presentation */
defined('ABSPATH')||exit;
add_action('plugins_loaded',static function(){
 $root=WPMU_PLUGIN_DIR.'/shared-product-core/';
 if(!function_exists('shared_product_tour_context'))require_once $root.'pricing/strategy-registry.php';
 if(!function_exists('shared_product_rate_related_options'))require_once $root.'adapters/tour-rate-related-options.php';
 $component=WP_PLUGIN_DIR.'/commerce-composition/includes/AccommodationPolicyComponent.php';
 if(is_readable($component))require_once $component;
},25);
