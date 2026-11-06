<?php
/**
 * Plugin Name: MAD4B Adaptive Asset Intelligence
 * Description: Native WordPress management and notification bridge for autonomous asset optimization.
 * Version: 0.2.0
 * Requires PHP: 7.4
 * Requires Plugins: mad4b-site-control-plane
 * Author: MAD4B
 */
defined('ABSPATH') || exit;
if (!in_array(wp_get_environment_type(),['staging','production'],true)) return;
// Public page rendering is owned exclusively by MU335, not the management module.
// Load authoring/notification hooks only in WordPress admin, CLI and REST contexts.
$mad4b_req=(string)($_SERVER['REQUEST_URI']??'');
$mad4b_rest=(strpos($mad4b_req,'/wp-json/')!==false || isset($_GET['rest_route']));
if (!is_admin() && !(defined('WP_CLI')&&WP_CLI) && !$mad4b_rest && !(defined('DOING_CRON')&&DOING_CRON)) return;
unset($mad4b_req,$mad4b_rest);
require_once __DIR__.'/includes/class-mad4b-scp-adaptive-asset-intelligence.php';
require_once __DIR__.'/includes/class-mad4b-scp-adaptive-presentation-discovery.php';
require_once __DIR__.'/includes/class-mad4b-scp-theme-builder-priority-plan.php';
add_action('plugins_loaded',static function() {
 if (!class_exists('MAD4B_SCP_Admin_Route_Registry',false)) return;
 MAD4B_SCP_Adaptive_Asset_Intelligence::boot();
},100);
