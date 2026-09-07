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
if ( empty( $status['oauth_discovery_ready'] ) ) mad4b_client_compat_fail( 'OAuth discovery should be ready in the disposable Staging fixture.', $status );

$resource = 'https://mad4b-client.test/wp-json/mcp/mad4b-read';
if ( $resource !== MAD4B_SCP_MCP_Client_Compatibility::resource_identifier() ) {
	mad4b_client_compat_fail( 'Unexpected protected resource identifier.', MAD4B_SCP_MCP_Client_Compatibility::resource_identifier() );
}

$path_specific = '/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-read';
$host_alias = '/.well-known/oauth-protected-resource';
if ( ! MAD4B_SCP_MCP_Client_Compatibility::is_well_known_path( $path_specific ) ) mad4b_client_compat_fail( 'Path-derived well-known location was not recognized.' );
if ( ! MAD4B_SCP_MCP_Client_Compatibility::is_well_known_path( $host_alias ) ) mad4b_client_compat_fail( 'Host compatibility alias was not recognized.' );

$expected_well_known = 'https://mad4b-client.test' . $path_specific;
if ( $expected_well_known !== $status['authoritative_well_known_url'] ) mad4b_client_compat_fail( 'Authoritative RFC 9728 URL mismatch.', $status );
if ( 'https://mad4b-client.test' . $host_alias !== $status['compatibility_alias_url'] ) mad4b_client_compat_fail( 'Compatibility alias URL mismatch.', $status );

$metadata = MAD4B_SCP_MCP_Client_Compatibility::metadata_for_path( $path_specific );
if ( is_wp_error( $metadata ) ) mad4b_client_compat_fail( 'Path-derived well-known metadata unexpectedly unavailable.', $metadata->get_error_code() );
if ( $resource !== $metadata['resource'] ) mad4b_client_compat_fail( 'Metadata resource mismatch.', $metadata );
if ( empty( $metadata['authorization_servers'][0] ) || 'https://auth.mad4b.test' !== $metadata['authorization_servers'][0] ) mad4b_client_compat_fail( 'Authorization server metadata mismatch.', $metadata );
if ( empty( $metadata['scopes_supported'] ) || ! in_array( 'mad4b:read', $metadata['scopes_supported'], true ) ) mad4b_client_compat_fail( 'Read scope missing from metadata.', $metadata );

$alias_metadata = MAD4B_SCP_MCP_Client_Compatibility::metadata_for_path( $host_alias );
if ( ! is_wp_error( $alias_metadata ) || 'mad4b_oauth_resource_metadata_path_unknown' !== $alias_metadata->get_error_code() ) mad4b_client_compat_fail( 'Host alias must redirect at HTTP serving layer rather than emit mismatched resource metadata.', $alias_metadata );

$unknown = MAD4B_SCP_MCP_Client_Compatibility::metadata_for_path( '/.well-known/oauth-protected-resource/not-mad4b' );
if ( ! is_wp_error( $unknown ) || 'mad4b_oauth_resource_metadata_path_unknown' !== $unknown->get_error_code() ) mad4b_client_compat_fail( 'Unknown metadata path must fail closed.', $unknown );

$profiles = isset( $status['client_profiles'] ) && is_array( $status['client_profiles'] ) ? $status['client_profiles'] : array();
foreach ( array( 'openai_chatgpt', 'anthropic_claude', 'google_gemini', 'manus', 'generic_mcp_client' ) as $profile ) {
	if ( ! in_array( $profile, $profiles, true ) ) mad4b_client_compat_fail( 'Expected client evidence profile missing.', $profile );
}

fwrite( STDOUT, 'mad4b.site-control-plane.runtime-mcp-client-compatibility.v1: PASS' . PHP_EOL );
