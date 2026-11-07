<?php
/**
 * Backwards compatibility bridge. Canonical owner:
 * mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-asset-intelligence.php
 * No company UUID, hostname or environment may be embedded here.
 */
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'MAD4B_SCP_Adaptive_Asset_Intelligence', false )
    && function_exists( 'get_option' )
    && defined( 'WP_PLUGIN_DIR' )
    && in_array( 'mad4b-adaptive-asset-intelligence/mad4b-adaptive-asset-intelligence.php',
        (array) get_option( 'active_plugins', [] ), true ) ) {
    $canonical = WP_PLUGIN_DIR . '/mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-asset-intelligence.php';
    if ( is_readable( $canonical ) ) require_once $canonical;
}
