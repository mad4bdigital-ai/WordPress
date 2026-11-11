<?php
/** Plugin Name: JetFormBuilder User Journey Native Dependency (Lab) */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
add_action('wp_enqueue_scripts', static function () {
    if (!function_exists('jet_form_builder')) return;
    $builder = jet_form_builder();
    if (!$builder->has_module('user-journey') || !$builder->has_module('jet-plugins')) return;
    $journey = $builder->module('user-journey');
    if (!is_object($journey) || !method_exists($journey, 'is_user_journey_enabled') || !$journey->is_user_journey_enabled()) return;
    $module = $builder->module('jet-plugins');
    if (!is_object($module) || !method_exists($module, 'register_scripts')) return;
    if (!wp_script_is('jet-plugins', 'registered')) $module->register_scripts();
    if (wp_script_is('jet-plugins', 'registered')) wp_enqueue_script('jet-plugins');
}, 100);
