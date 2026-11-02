<?php
/**
 * Plugin Name: MAD4B Frontend Impact Priority Runtime Loader
 * Description: Optional Control Plane bridge for public-surface impact discovery.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_action('plugins_loaded',static function():void {
    $file=WP_PLUGIN_DIR.'/mad4b-site-control-plane/includes/class-mad4b-scp-frontend-impact-priority.php';
    if(!is_readable($file))return;
    // On consolidated Control Plane builds the main plugin already loads and
    // boots the service. On legacy installations this MU bridge does it once.
    if(class_exists('MAD4B_SCP_Frontend_Impact_Priority',false))return;
    require_once $file;
    MAD4B_SCP_Frontend_Impact_Priority::boot();
},999);