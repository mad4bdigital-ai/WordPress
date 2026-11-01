<?php
/**
 * Plugin Name: MAD4B Adaptive Asset Intelligence
 * Description: Native WordPress management and notification bridge for autonomous asset optimization.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Requires Plugins: mad4b-site-control-plane
 * Author: MAD4B
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'staging') return;
require_once __DIR__.'/includes/class-mad4b-scp-adaptive-asset-intelligence.php';
add_action('plugins_loaded',static function() {
 if (!class_exists('MAD4B_SCP_Admin_Route_Registry',false)) return;
 MAD4B_SCP_Adaptive_Asset_Intelligence::boot();
},100);
