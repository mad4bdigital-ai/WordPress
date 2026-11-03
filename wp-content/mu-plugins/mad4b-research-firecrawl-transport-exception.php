<?php
/**
 * Plugin Name: MAD4B Research - Scoped Sandbox Transport Exception
 * Description: Exact Firecrawl transport admission for the local Woo Lab sandbox; does not weaken any other HTTP policy.
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/*
 * The existing Woo Lab sandbox guard intentionally rejects private Docker HTTP.
 * Only its *specific* WP_Error can be lifted, and only while a permission-checked
 * MAD4B Research Ability owns an exact fixed-destination, no-redirect request.
 * Other denied requests, origins, methods, users, and targets remain blocked.
 */
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
    if ( ! is_wp_error( $pre )
        || 'etg_sandbox_network_blocked' !== $pre->get_error_code()
        || ! class_exists( 'MAD4B_Research_Firecrawl_Bridge' )
        || ! MAD4B_Research_Firecrawl_Bridge::transport_permitted( $url, $args ) ) {
        return $pre;
    }
    return false;
}, PHP_INT_MAX, 3 );
