<?php
/**
 * Plugin Name: MAD4B Company Astra Addon Frontend Asset Policy
 * Version: 1.0.0
 * Description: Site-scoped official Astra Addon asset opt-out; theme and admin remain operational.
 */
if (!defined('ABSPATH')) exit;
add_filter('astra_addon_enqueue_assets',static function($enabled){
 if (is_admin() || wp_doing_ajax()
   || (defined('REST_REQUEST') && REST_REQUEST)
   || (function_exists('is_customize_preview') && is_customize_preview())) return $enabled;
 // Default inherit; only sites with proven unneeded assets opt out.
 return get_option('mad4b_astra_addon_front_assets_policy','inherit') === 'off' ? false : $enabled;
}, PHP_INT_MAX);
