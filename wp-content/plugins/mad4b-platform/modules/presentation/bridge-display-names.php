<?php
/** Plugin Name: Bridge Display Names */
if (!defined('ABSPATH')) exit;
// Display-only alias. Keep the certified SEO bridge file basename and package identity unchanged.
add_filter('all_plugins', static function($plugins) {
    $key = 'etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php';
    if (isset($plugins[$key])) {
        $plugins[$key]['Name'] = 'Dynamic Filter SEO Bridge';
        $plugins[$key]['Title'] = 'Dynamic Filter SEO Bridge';
    }
    return $plugins;
});
