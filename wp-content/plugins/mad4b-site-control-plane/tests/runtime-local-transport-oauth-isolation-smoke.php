<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function mad4b_local_transport_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

if ( defined( 'MAD4B_MCP_OAUTH_ENABLED' ) && true === constant( 'MAD4B_MCP_OAUTH_ENABLED' ) ) {
	mad4b_local_transport_fail( 'Fixture unexpectedly enabled OAuth.' );
}

$oauth_filters = array(
	array( 'MAD4B_SCP_OAuth_Request_Context_Guard', 'reset_request_context' ),
	array( 'MAD4B_SCP_OAuth_JWT_Header_Guard', 'enforce' ),
	array( 'MAD4B_SCP_OAuth_Resource_Bridge', 'authenticate_rest_request' ),
	array( 'MAD4B_SCP_OAuth_Subject_Gate', 'enforce' ),
);
foreach ( $oauth_filters as $callback ) {
	if ( false !== has_filter( 'rest_pre_dispatch', $callback ) ) {
		mad4b_local_transport_fail( 'OAuth resource bridge must not intercept an OAuth-disabled local transport.', $callback );
	}
}

wp_set_current_user( 1 );
if ( 1 !== get_current_user_id() || ! current_user_can( 'manage_options' ) ) {
	mad4b_local_transport_fail( 'Fixture administrator is unavailable.' );
}
if ( true !== MAD4B_SCP_Policy::can_read() ) {
	mad4b_local_transport_fail( 'WordPress-authenticated admin lost local read permission.' );
}

// Prime the local MCP/REST registry and verify Connection truth still separates
// local registration from OAuth-dependent remote preflight.
if ( function_exists( 'rest_get_server' ) ) rest_get_server();
$status = MAD4B_SCP_Connection_Status::status();
if ( empty( $status['local_transport_ready'] ) ) {
	mad4b_local_transport_fail( 'Local transport should be ready independently of OAuth.', $status['local_blockers'] );
}
if ( ! in_array( 'oauth_resource_bridge_not_configured', isset( $status['remote_preflight_blockers'] ) ? $status['remote_preflight_blockers'] : array(), true ) ) {
	mad4b_local_transport_fail( 'Remote preflight must remain blocked when OAuth is not configured.', $status['remote_preflight_blockers'] );
}
if ( 'wordpress-authenticated-request-plus-server-bound-mad4b-transport-context' !== $status['authentication']['transport_model'] ) {
	mad4b_local_transport_fail( 'Local transport authentication model drifted.', $status['authentication'] );
}

wp_set_current_user( 0 );
echo 'mad4b.site-control-plane.runtime-local-transport-oauth-isolation.v1: PASS' . PHP_EOL;
