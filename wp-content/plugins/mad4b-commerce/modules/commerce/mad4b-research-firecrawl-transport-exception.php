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
    if ( ! is_wp_error( $pre ) ||
      ! in_array( $pre->get_error_code(), array(
        'etg_sandbox_network_blocked','mad4b_sandbox_network_blocked'
      ), true ) ) return $pre;
    $old = $pre->get_error_code()==='etg_sandbox_network_blocked' &&
       class_exists('MAD4B_Research_Firecrawl_Bridge') &&
       MAD4B_Research_Firecrawl_Bridge::transport_permitted($url,$args);
    $native = $pre->get_error_code()==='mad4b_sandbox_network_blocked' &&
       class_exists('MAD4B_SCP_Unified_Provider_Routing',false) &&
       MAD4B_SCP_Unified_Provider_Routing::transport_permitted($url,$args);
    return ($old||$native) ? false : $pre;
}, PHP_INT_MAX, 3 );
