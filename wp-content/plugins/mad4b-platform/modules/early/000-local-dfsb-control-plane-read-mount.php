<?php
/**
 * Plugin Name: Local Lab — Dynamic Filter SEO Bridge Control Plane Read Mount
 * Description: Source-reviewed neutral read adapter registration only, local QA.
 */
defined('ABSPATH') || exit;
if ( ! function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'local' ) return;
// Preserve the built-in registry's reviewed source policy; no ability is
// registered directly, and no mutation adapter or approval override is mounted.
add_action('mad4b_scp_register_adapters', static function($registry): void {
    if (!is_object($registry) || !method_exists($registry,'register')
        || !class_exists('MAD4B_SCP_DFSB_Adapter', false)) return;
    $adapter = new MAD4B_SCP_DFSB_Adapter();
    if (! $adapter->is_available()) return;
    $registry->register($adapter);
}, 40);
