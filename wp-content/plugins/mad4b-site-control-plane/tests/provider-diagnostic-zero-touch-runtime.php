<?php

define( 'ABSPATH', '/srv/wordpress/' );
define( 'ICL_SITEPRESS_VERSION', 'test' );

function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function is_admin() { return false; }
function current_user_can( $cap ) { return true; }
function rest_get_server() {
	fwrite( STDERR, "FAIL: passive provider status attempted to materialize REST\n" );
	exit( 1 );
}
function rest_do_request( $request ) {
	fwrite( STDERR, "FAIL: passive provider status attempted internal REST dispatch\n" );
	exit( 1 );
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-diagnostic-policy.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-rest-compatibility.php';

function mad4b_diag_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

global $wp_rest_server;
$wp_rest_server = null;
$probe = MAD4B_SCP_REST_Compatibility::wpml_probe();
mad4b_diag_assert( 'passive_route_unobserved' === $probe['state'], 'WPML active + unmaterialized REST must remain passive/unobserved.' );
mad4b_diag_assert( false === $probe['active_probe_performed'], 'Passive status must not run an active probe.' );
mad4b_diag_assert( false === $probe['internal_rest_dispatch_performed'], 'Passive status must not internally dispatch provider REST.' );
mad4b_diag_assert( 0 === $probe['provider_self_calls_started'], 'Passive status must start zero provider self-calls.' );
mad4b_diag_assert( null === $probe['route_registered'], 'Unmaterialized REST route truth must remain unknown, not fabricated.' );

class MAD4B_Test_REST_Server {
	public function get_routes() {
		return array( '/wpml/v1/rest/status' => array() );
	}
}
$wp_rest_server = new MAD4B_Test_REST_Server();
$snapshot = MAD4B_SCP_Provider_Diagnostic_Policy::rest_route_snapshot( '/wpml/v1/rest/status' );
mad4b_diag_assert( true === $snapshot['rest_server_materialized'], 'Existing REST server should be observed.' );
mad4b_diag_assert( true === $snapshot['route_registered'], 'Existing route should be observed without dispatch.' );
mad4b_diag_assert( false === $snapshot['internal_rest_dispatch_performed'], 'Route snapshot must remain zero-dispatch.' );
mad4b_diag_assert( 0 === $snapshot['provider_self_calls_started'], 'Route snapshot must start zero provider calls.' );

echo "mad4b.provider-diagnostic-zero-touch.runtime.v1: PASS\n";
