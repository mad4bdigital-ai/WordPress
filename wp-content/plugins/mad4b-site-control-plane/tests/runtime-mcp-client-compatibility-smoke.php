<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function mad4b_client_compat_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';

add_filter( 'mad4b_scp_mcp_client_profiles', function ( $profiles ) {
	$profiles[] = array(
		'id' => 'future-agent',
		'display_name' => 'Future Agent',
		'match_any' => array( 'future-agent' ),
		'transports' => array( 'streamable_http' ),
		'authentication' => array( 'oauth_discovery', 'bearer_header' ),
		'registration_hint' => 'separate_oauth_client_recommended',
		'authority_effect' => 'none',
	);
	return $profiles;
} );
MAD4B_SCP_MCP_Client_Profile_Registry::reset_for_tests();

$status = MAD4B_SCP_MCP_Client_Compatibility::status();
if ( empty( $status['client_agnostic'] ) ) mad4b_client_compat_fail( 'Client compatibility must be vendor-agnostic.', $status );
if ( 'streamable_http' !== $status['transport'] ) mad4b_client_compat_fail( 'Expected Streamable HTTP compatibility.', $status );
if ( ! empty( $status['client_profiles_create_authority'] ) ) mad4b_client_compat_fail( 'Client profiles must not create authority.', $status );
if ( ! empty( $status['client_vendor_required_for_authorization'] ) ) mad4b_client_compat_fail( 'Authorization must not depend on client vendor name.', $status );
if ( empty( $status['unknown_clients_supported'] ) ) mad4b_client_compat_fail( 'Unknown standards-compliant clients must use generic fallback.', $status );
if ( empty( $status['oauth_discovery_ready'] ) ) mad4b_client_compat_fail( 'OAuth discovery should be policy-ready in the disposable Staging fixture.', $status );
if ( 'external' !== $status['oauth_authority_mode'] || 1 !== (int) $status['authorization_server_count'] ) mad4b_client_compat_fail( 'Fixture must truthfully report one external authority.', $status );
if ( empty( $status['authorization_server_external'] ) || ! empty( $status['authorization_server_local'] ) || ! empty( $status['authorization_server_hybrid'] ) ) mad4b_client_compat_fail( 'Authority type booleans are inconsistent.', $status );
if ( 'MAD4B WordPress Staging Read MCP' !== $status['resource_name'] ) mad4b_client_compat_fail( 'Resource name must derive Staging environment rather than be hardcoded.', $status );
if ( $status['profile_count'] < 6 ) mad4b_client_compat_fail( 'Dynamic client profile extension was not loaded.', $status );

$resource = 'https://mad4b-client.test/wp-json/mcp/mad4b-read';
if ( $resource !== MAD4B_SCP_MCP_Client_Compatibility::resource_identifier() ) mad4b_client_compat_fail( 'Unexpected protected resource identifier.', MAD4B_SCP_MCP_Client_Compatibility::resource_identifier() );

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
if ( 'MAD4B WordPress Staging Read MCP' !== $metadata['resource_name'] ) mad4b_client_compat_fail( 'Metadata resource name mismatch.', $metadata );
if ( 'external' !== $metadata['mad4b_authority_mode'] ) mad4b_client_compat_fail( 'Metadata authority mode mismatch.', $metadata );
if ( empty( $metadata['authorization_servers'][0] ) || 'https://auth.mad4b.test' !== $metadata['authorization_servers'][0] ) mad4b_client_compat_fail( 'Authorization server metadata mismatch.', $metadata );
if ( empty( $metadata['scopes_supported'] ) || ! in_array( 'mad4b:read', $metadata['scopes_supported'], true ) ) mad4b_client_compat_fail( 'Read scope missing from metadata.', $metadata );
if ( empty( $metadata['mad4b_client_compatibility'] ) ) mad4b_client_compat_fail( 'Compatibility manifest extension missing.', $metadata );

$alias_metadata = MAD4B_SCP_MCP_Client_Compatibility::metadata_for_path( $host_alias );
if ( ! is_wp_error( $alias_metadata ) || 'mad4b_oauth_resource_metadata_path_unknown' !== $alias_metadata->get_error_code() ) mad4b_client_compat_fail( 'Host alias must redirect at HTTP serving layer rather than emit mismatched resource metadata.', $alias_metadata );

$future = MAD4B_SCP_MCP_Client_Profile_Registry::detect_request_profile( 'Future-Agent/9.1', '' );
if ( empty( $future['matched'] ) || 'future-agent' !== $future['profile']['id'] || ! empty( $future['authoritative'] ) ) mad4b_client_compat_fail( 'Dynamic client profile detection failed.', $future );
$claude = MAD4B_SCP_MCP_Client_Profile_Registry::detect_request_profile( 'Claude-Remote-MCP/1.0', '' );
if ( empty( $claude['matched'] ) || 'anthropic-claude' !== $claude['profile']['id'] ) mad4b_client_compat_fail( 'Built-in Claude evidence profile was not detected.', $claude );
$unknown_client = MAD4B_SCP_MCP_Client_Profile_Registry::detect_request_profile( 'UnknownMCPClient/42', '' );
if ( ! empty( $unknown_client['matched'] ) || 'generic-mcp' !== $unknown_client['profile']['id'] ) mad4b_client_compat_fail( 'Unknown client must fall back to generic MCP profile.', $unknown_client );

$manifest = MAD4B_SCP_MCP_Client_Compatibility::manifest();
if ( empty( $manifest['client_agnostic'] ) || ! empty( $manifest['authorization_depends_on_vendor'] ) ) mad4b_client_compat_fail( 'Compatibility manifest must remain vendor-neutral.', $manifest );
if ( 'external' !== $manifest['authentication']['authority_mode'] || 1 !== count( $manifest['authentication']['authorization_servers'] ) ) mad4b_client_compat_fail( 'Compatibility manifest authority truth mismatch.', $manifest );
if ( 'deny_sensitive_generic_introspection' !== $manifest['remote_oauth_read_policy']['default'] ) mad4b_client_compat_fail( 'Remote OAuth read blast-radius policy missing from manifest.', $manifest );
$manifest_ids = array();
foreach ( isset( $manifest['profiles'] ) && is_array( $manifest['profiles'] ) ? $manifest['profiles'] : array() as $profile ) if ( isset( $profile['id'] ) ) $manifest_ids[] = $profile['id'];
foreach ( array( 'openai-chatgpt', 'anthropic-claude', 'google-gemini', 'manus', 'generic-mcp', 'future-agent' ) as $id ) if ( ! in_array( $id, $manifest_ids, true ) ) mad4b_client_compat_fail( 'Expected dynamic compatibility profile missing from manifest.', $id );

$unknown = MAD4B_SCP_MCP_Client_Compatibility::metadata_for_path( '/.well-known/oauth-protected-resource/not-mad4b' );
if ( ! is_wp_error( $unknown ) || 'mad4b_oauth_resource_metadata_path_unknown' !== $unknown->get_error_code() ) mad4b_client_compat_fail( 'Unknown metadata path must fail closed.', $unknown );

fwrite( STDOUT, 'mad4b.site-control-plane.runtime-mcp-client-compatibility.v3: PASS' . PHP_EOL );
