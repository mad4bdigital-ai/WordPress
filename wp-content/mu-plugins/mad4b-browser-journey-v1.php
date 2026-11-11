<?php
/**
 * Plugin Name: MAD4B Browser Journey Runtime — Staging v1
 * Description: Browser context hints and on-demand nonce single-flight, without server-side pricing or page prefetch.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts',static function() {
    if (wp_get_environment_type()!=='staging'
        || wp_parse_url(home_url('/'),PHP_URL_HOST)!=='tours.dev.mad4b.com'
        || is_admin() || wp_doing_ajax()) return;
    $path=WPMU_PLUGIN_DIR.'/assets/mad4b-browser-journey-v1.js';
    if(!is_file($path)) return;
    wp_enqueue_script('mad4b-browser-journey-v1',
        content_url('/mu-plugins/assets/mad4b-browser-journey-v1.js'),
        [],(string)filemtime($path),true);
},8);
