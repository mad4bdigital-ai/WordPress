<?php
/**
 * Backwards compatibility bridge. Canonical owner:
 * mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-asset-intelligence.php
 * No company UUID, hostname or environment may be embedded here.
 */
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'MAD4B_SCP_Adaptive_Asset_Intelligence', false )
    && function_exists( 'get_option' ) && defined( 'WP_PLUGIN_DIR' ) ) {
    $active = get_option( 'active_plugins', array() );
    if ( is_array( $active ) && in_array(
        'mad4b-adaptive-asset-intelligence/mad4b-adaptive-asset-intelligence.php', $active, true
    ) ) {
        // The legacy shim is never a second runtime implementation or an
        // arbitrary include loader. Resolve and confine the canonical class
        // to the installed original WordPress plugin, without following
        // a symbolic link to an unowned path.
        $plugin_root = realpath( WP_PLUGIN_DIR );
        $owner = realpath( WP_PLUGIN_DIR . '/mad4b-adaptive-asset-intelligence' );
        $canonical = WP_PLUGIN_DIR . '/mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-asset-intelligence.php';
        $real = realpath( $canonical );
        if ( $plugin_root && $owner && $real && ! is_link( $canonical )
            && ! is_link( WP_PLUGIN_DIR . '/mad4b-adaptive-asset-intelligence' )
            && 0 === strpos( $owner, rtrim( $plugin_root, '/' ) . '/' )
            && 0 === strpos( $real, rtrim( $owner, '/' ) . '/includes/' )
            && is_file( $real ) && is_readable( $real ) ) {
            require_once $real;
        }
    }
}
