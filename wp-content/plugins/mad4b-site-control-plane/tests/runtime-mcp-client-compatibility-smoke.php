<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function mad4b_client_compat_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';

$status = MAD4B_SCP_MCP_Client_Compatibility::status();
if ( empty( $status['client_agnostic'] ) ) mad4b_client_compat_fail( 'Client compatibility must be vendor-agnostic.', $status );
if ( 'streamable_http' !== $status['transport'] ) mad4b_client_compat_fail( 'Expected Streamable HTTP compatibility.', $status );
if ( ! empty( $status['client_profiles_create_authority'] ) ) mad4b_client_compat_fail( 'Client profiles must not create authority.', $status );
if ( ! empty( $status['client_vendor_required_for_authorization'] ) ) mad4b_client_compat_fail( 'Authorization must not depend on client vendor name.', $status );

$resource = 'https://mad4b-client.test/wp-json/mcp/mad4b-read';
if ( $resource !== MAD4B_SCP_MCP_Client_Compatibility::resource_identifier() ) {
	mad4b_client_compat_fail( 'Unexpected protected resource identifier.', MAD4B_SCP_MCP_Client_Compatibility::resource_identifier() );
}

$path_specific = '/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-read';
$host_alias = '/.well-known/oauth-protected-resource';
foreach ( array( $path_specific, $host_alias ) as $path ) {
	if ( ! MAD4B_SCP_MCP_Client_Compatibility::is_well_known_path( $path ) ) mad4b_client_compat_fail( 'Expected well-known path was not recognized.', $path );
	$metadata = MAD4B_SCP_MCP_Client_Compatibility::metadata_for_path( $path );
	if ( is_wp_error( $metadata ) ) mad4b_client_compat_fail( 'Well-known metadata unexpectedly unavailable.', array( $path, $metadata->get_error_code() ) );
	if ( $resource !== $metadata['resource'] ) mad4b_client_compat_fail( 'Metadata resource mismatch.', $metadata );
	if ( empty( $metadata['authorization_servers'][0] ) || 'https://auth.mad4b.test' !== $metadata['authorization_servers'][0] ) mad4b_client_compat_fail( 'Authorization server metadata mismatch.', $metadata );
	if ( empty( $metadata['scopes_supported'] ) || ! in_array( 'mad4b:read', $metadata['scopes_supported'], true ) ) mad4b_client_compat_fail( 'Read scope missing from metadata.', $metadata );
}

$unknown = MAD4B_SCP_MCP_Client_Compatibility::metadata_for_path( '/.well-known/oauth-protected-resource/not-mad4b' );
if ( ! is_wp_error( $unknown ) || 'mad4b_oauth_resource_metadata_path_unknown' !== $unknown->get_error_code() ) mad4b_client_compat_fail( 'Unknown metadata path must fail closed.', $unknown );

$profiles = isset( $status['client_profiles'] ) && is_array( $status['client_profiles'] ) ? $status['client_profiles'] : array();
foreach ( array( 'openai_chatgpt', 'anthropic_claude', 'google_gemini', 'manus', 'generic_mcp_client' ) as $profile ) {
	if ( ! in_array( $profile, $profiles, true ) ) mad4b_client_compat_fail( 'Expected client evidence profile missing.', $profile );
}

fwrite( STDOUT, 'mad4b.site-control-plane.runtime-mcp-client-compatibility.v1: PASS' . PHP_EOL );
