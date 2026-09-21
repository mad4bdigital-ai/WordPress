<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function mad4b_local_transport_misconfigured_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

if ( ! defined( 'MAD4B_MCP_OAUTH_ENABLED' ) || true !== constant( 'MAD4B_MCP_OAUTH_ENABLED' ) ) {
	mad4b_local_transport_misconfigured_fail( 'Fixture must configure OAuth.' );
}

$bridge = MAD4B_SCP_OAuth_Resource_Bridge::status();
if ( empty( $bridge['configured'] ) || ! empty( $bridge['effective'] ) ) {
	mad4b_local_transport_misconfigured_fail( 'Fixture must model configured but ineffective OAuth.', $bridge );
}

$oauth_filters = array(
	array( 'MAD4B_SCP_OAuth_Request_Context_Guard', 'reset_request_context' ),
	array( 'MAD4B_SCP_OAuth_JWT_Header_Guard', 'enforce' ),
	array( 'MAD4B_SCP_OAuth_Resource_Bridge', 'authenticate_rest_request' ),
	array( 'MAD4B_SCP_OAuth_Subject_Gate', 'enforce' ),
);
foreach ( $oauth_filters as $callback ) {
	if ( false !== has_filter( 'rest_pre_dispatch', $callback ) ) {
		mad4b_local_transport_misconfigured_fail( 'Ineffective OAuth must not intercept local MCP transport.', $callback );
	}
}

wp_set_current_user( 1 );
if ( true !== MAD4B_SCP_Policy::can_read() ) {
	mad4b_local_transport_misconfigured_fail( 'Configured-but-ineffective OAuth disabled local WordPress read permission.' );
}

if ( function_exists( 'rest_get_server' ) ) rest_get_server();
$status = MAD4B_SCP_Connection_Status::status();
if ( empty( $status['local_transport_ready'] ) ) {
	mad4b_local_transport_misconfigured_fail( 'OAuth misconfiguration propagated into local transport readiness.', $status['local_blockers'] );
}
if ( empty( $status['remote_preflight_blockers'] ) ) {
	mad4b_local_transport_misconfigured_fail( 'Remote preflight must remain blocked for ineffective OAuth.', $status );
}
if ( ! in_array( 'oauth_wp_subject_unconfigured', $status['remote_preflight_blockers'], true ) ) {
	mad4b_local_transport_misconfigured_fail( 'Expected OAuth subject configuration blocker missing.', $status['remote_preflight_blockers'] );
}

if ( ! class_exists( 'MAD4B_SCP_MCP_Client_Compatibility' ) ) {
	mad4b_local_transport_misconfigured_fail( 'Client compatibility layer unavailable.' );
}
$metadata_path = (string) wp_parse_url( MAD4B_SCP_MCP_Client_Compatibility::authoritative_well_known_url(), PHP_URL_PATH );
$metadata = MAD4B_SCP_MCP_Client_Compatibility::metadata_for_path( $metadata_path );
if ( ! is_wp_error( $metadata ) || 'mad4b_oauth_resource_discovery_not_ready' !== $metadata->get_error_code() ) {
	mad4b_local_transport_misconfigured_fail( 'Ineffective OAuth published protected-resource metadata as ready.', $metadata );
}
$manifest = MAD4B_SCP_MCP_Client_Compatibility::manifest();
$authorization_servers = isset( $manifest['authentication']['authorization_servers'] ) && is_array( $manifest['authentication']['authorization_servers'] ) ? $manifest['authentication']['authorization_servers'] : array();
if ( ! empty( $authorization_servers ) ) {
	mad4b_local_transport_misconfigured_fail( 'Compatibility manifest advertised authorization servers for ineffective OAuth.', $authorization_servers );
}

wp_set_current_user( 0 );
echo 'mad4b.site-control-plane.runtime-local-transport-oauth-misconfigured.v2: PASS' . PHP_EOL;
