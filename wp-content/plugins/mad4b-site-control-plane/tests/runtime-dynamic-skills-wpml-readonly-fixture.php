<?php
/**
 * Disposable WPML-like REST route and read-only observation.
 * MUST execute in an isolated WP-CLI request before grants are reconciled:
 * defining ICL_SITEPRESS_VERSION here can change adapter registration for
 * this single request, and must not alter the permanent runtime grant set.
 */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$fail = static function ( $message ) {
    fwrite( STDERR, '[MAD4B WPML isolated read-only fixture] ' . $message . PHP_EOL );
    exit( 1 );
};
// Explicitly verify no governed write authority exists before the synthetic
// provider is loaded. A future CI rewrite must not run a grant mutation here.
$before_grants = class_exists( 'MAD4B_SCP_Agent_Registry' )
    ? MAD4B_SCP_Agent_Registry::counts() : array();
// Register a disposable WPML-compatible route directly on the REST server. The
// low-level register_route() API expects the full route path; WordPress' public
// register_rest_route() wrapper performs this namespace prefixing itself.
// The deep diagnostic intentionally short-circuits when WPML is not active, so
// this synthetic provider fixture must explicitly model an active WPML runtime.
if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) define( 'ICL_SITEPRESS_VERSION', 'ci-fixture' );
$rest_server = rest_get_server();
$rest_server->register_route( 'wpml/v1', '/wpml/v1/rest/status', array(
	array(
		'methods' => 'GET',
		'callback' => static function ( $request ) {
			return new WP_REST_Response( array(
				'status' => '1' === (string) $request->get_param( 'test_get_parameter' ) ? 'valid' : 'invalid',
				'get_parameters' => null !== $request->get_param( 'cachebuster' ) ? 'valid' : 'invalid',
			), 200 );
		},
		'permission_callback' => '__return_true',
	),
), true );
// Behavioral proof belongs to the explicit, bounded diagnostic surface.
$deep = MAD4B_SCP_REST_Compatibility::deep_diagnostic( array( 'provider' => 'wpml' ) );
if ( is_wp_error( $deep ) ) $fail( 'Explicit WPML deep diagnostic failed: ' . $deep->get_error_code() );
if ( empty( $deep['ready'] ) || empty( $deep['query_parameters_preserved'] ) || 'behavior_verified' !== (string) $deep['state'] ) {
	$fail( 'Explicit WPML deep diagnostic did not prove query parameter pass-through.' );
}
if ( empty( $deep['active_probe_performed'] ) || empty( $deep['internal_rest_dispatch_performed'] ) || 1 !== (int) $deep['provider_self_calls_started'] ) {
	$fail( 'Explicit WPML deep diagnostic did not report its one bounded internal dispatch.' );
}
if ( ! empty( $deep['loopback_http_performed'] ) || ! empty( $deep['automatic_retry_allowed'] ) || ! empty( $deep['authorizing'] ) || ! empty( $deep['acceptance_evidence_persisted'] ) ) {
	$fail( 'Explicit WPML deep diagnostic crossed its non-authorizing boundary.' );
}

$rest_compat = MAD4B_SCP_REST_Compatibility::status();
if ( empty( $rest_compat['rest_enabled'] ) ) $fail( 'REST API is disabled in the disposable Staging runtime.' );
if ( ! empty( $rest_compat['control_plane_disables_rest'] ) ) $fail( 'Control Plane reports REST disablement.' );
if ( ! empty( $rest_compat['control_plane_filters_rest_enabled'] ) ) $fail( 'Control Plane unexpectedly owns a rest_enabled callback.' );
if ( ! empty( $rest_compat['control_plane_filters_rest_authentication_errors'] ) ) $fail( 'Control Plane unexpectedly owns a global rest_authentication_errors callback.' );
if ( ! empty( $rest_compat['rest_enabled_hook']['truncated'] ) || ! empty( $rest_compat['rest_authentication_errors_hook']['truncated'] ) ) $fail( 'REST hook evidence was truncated.' );
if ( true !== $rest_compat['wpml']['route_registered'] || empty( $rest_compat['wpml']['ready'] ) ) $fail( 'Passive WPML route observation did not see the already-materialized route.' );
if ( null !== $rest_compat['wpml']['query_parameters_preserved'] ) $fail( 'Passive WPML status fabricated behavioral evidence.' );
if ( ! empty( $rest_compat['wpml']['active_probe_performed'] ) || ! empty( $rest_compat['wpml']['internal_rest_dispatch_performed'] ) || 0 !== (int) $rest_compat['wpml']['provider_self_calls_started'] ) $fail( 'Passive WPML status started provider work.' );
if ( ! empty( $rest_compat['wpml']['control_plane_block_detected'] ) ) $fail( 'Control Plane blocked the WPML-compatible REST route.' );
$wpml_receipt = MAD4B_SCP_WPML_Response_Contract::receipt_status();
if ( ! empty( $wpml_receipt['observed'] ) ) $fail( 'Internal rest_do_request incorrectly minted external WPML evidence.' );

$after_grants = class_exists( 'MAD4B_SCP_Agent_Registry' )
    ? MAD4B_SCP_Agent_Registry::counts() : array();
if ( $before_grants !== $after_grants )
    $fail( 'WPML read-only fixture changed the global grant registry.' );
echo "MAD4B_WPML_READONLY_ISOLATED: PASS".PHP_EOL;
