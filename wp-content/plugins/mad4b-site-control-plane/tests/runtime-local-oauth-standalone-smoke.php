<?php

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "WordPress not loaded\n" ); exit( 1 ); }
if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) || ! class_exists( 'MAD4B_SCP_Local_OAuth_Store' ) ) {
	fwrite( STDERR, "Local OAuth classes unavailable\n" );
	exit( 1 );
}
if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_CLIENTS' ) ) {
	define(
		'MAD4B_MCP_LOCAL_OAUTH_CLIENTS',
		array(
			'https://client.example.test/mcp-client.json' => array(
				'client_name' => 'MAD4B CI Client',
				'redirect_uris' => array( 'https://client.example.test/callback' ),
				'application_type' => 'web',
			),
		)
	);
}

MAD4B_SCP_Local_OAuth_Server::ensure_runtime();
$status = MAD4B_SCP_Local_OAuth_Server::status();
if ( empty( $status['effective'] ) ) { fwrite( STDERR, 'Local OAuth not effective: ' . wp_json_encode( $status ) . "\n" ); exit( 1 ); }
if ( 'pre_registered' !== $status['client_registration_mode'] || ! empty( $status['client_id_metadata_document_supported'] ) || ! empty( $status['dynamic_client_registration_supported'] ) ) exit( 1 );
if ( empty( $status['authorization_response_iss_parameter_supported'] ) || 'RS256' !== $status['access_token_signing_alg'] ) exit( 1 );
if ( empty( $status['private_key_present'] ) || ! empty( $status['private_key_exposed'] ) || ! empty( $status['private_key_stored_in_database'] ) ) exit( 1 );

$metadata = MAD4B_SCP_Local_OAuth_Server::metadata();
if ( $metadata['issuer'] !== MAD4B_SCP_Local_OAuth_Server::issuer() ) exit( 1 );
if ( empty( $metadata['authorization_response_iss_parameter_supported'] ) ) exit( 1 );
if ( ! in_array( 'S256', $metadata['code_challenge_methods_supported'], true ) ) exit( 1 );
if ( ! in_array( MAD4B_SCP_Local_OAuth_Server::resource_identifier(), $metadata['protected_resources'], true ) ) exit( 1 );
if ( isset( $metadata['registration_endpoint'] ) ) exit( 1 );

$jwks = MAD4B_SCP_Local_OAuth_Server::jwks_document();
if ( is_wp_error( $jwks ) || empty( $jwks['keys'][0]['kid'] ) || empty( $jwks['keys'][0]['n'] ) || empty( $jwks['keys'][0]['e'] ) ) exit( 1 );
$key = $jwks['keys'][0];
if ( 'RSA' !== $key['kty'] || 'RS256' !== $key['alg'] ) exit( 1 );

$key_path_method = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'private_key_path' );
$key_path_method->setAccessible( true );
$key_path = $key_path_method->invoke( null );
if ( is_wp_error( $key_path ) || ! is_file( $key_path ) ) exit( 1 );
if ( 0 === strpos( trailingslashit( wp_normalize_path( dirname( $key_path ) ) ), trailingslashit( wp_normalize_path( ABSPATH ) ) ) ) exit( 1 );
$mode = fileperms( $key_path ) & 0777;
if ( 0600 !== $mode ) { fwrite( STDERR, sprintf( "Unexpected key permissions: %o\n", $mode ) ); exit( 1 ); }

$resource = MAD4B_SCP_Local_OAuth_Server::resource_identifier();
$mint = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'mint_access_token' );
$mint->setAccessible( true );
$token = $mint->invoke( null, 'https://client.example.test/mcp-client.json', 1, $resource, array( 'mad4b:read', 'offline_access' ) );
if ( is_wp_error( $token ) ) { fwrite( STDERR, $token->get_error_message() . "\n" ); exit( 1 ); }
$parts = explode( '.', $token );
if ( 3 !== count( $parts ) ) exit( 1 );
$decode = static function ( $value ) {
	$value = strtr( $value, '-_', '+/' );
	$pad = strlen( $value ) % 4;
	if ( $pad ) $value .= str_repeat( '=', 4 - $pad );
	return base64_decode( $value, true );
};
$header = json_decode( $decode( $parts[0] ), true );
$claims = json_decode( $decode( $parts[1] ), true );
$signature = $decode( $parts[2] );
if ( ! is_array( $header ) || ! is_array( $claims ) || false === $signature ) exit( 1 );
if ( 'RS256' !== $header['alg'] || $key['kid'] !== $header['kid'] ) exit( 1 );
if ( MAD4B_SCP_Local_OAuth_Server::issuer() !== $claims['iss'] || $resource !== $claims['aud'] || $resource !== $claims['resource'] || 'user:1' !== $claims['sub'] ) exit( 1 );

$jwk_to_pem = new ReflectionMethod( 'MAD4B_SCP_OAuth_Resource_Bridge', 'public_key_from_jwk' );
$jwk_to_pem->setAccessible( true );
$public_key = $jwk_to_pem->invoke( null, $key );
if ( is_wp_error( $public_key ) ) exit( 1 );
if ( 1 !== openssl_verify( $parts[0] . '.' . $parts[1], $signature, $public_key, OPENSSL_ALGO_SHA256 ) ) exit( 1 );

$now = gmdate( 'Y-m-d H:i:s' );
$code_hash = hash( 'sha256', 'ci-code-' . wp_generate_uuid4() );
if ( ! MAD4B_SCP_Local_OAuth_Store::insert_code( array(
	'code_hash' => $code_hash,
	'client_id' => 'https://client.example.test/mcp-client.json',
	'wp_user_id' => 1,
	'redirect_uri' => 'https://client.example.test/callback',
	'resource' => $resource,
	'scope' => 'mad4b:read offline_access',
	'code_challenge' => str_repeat( 'A', 43 ),
	'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ),
	'created_at' => $now,
) ) ) exit( 1 );
$code_row = MAD4B_SCP_Local_OAuth_Store::get_code( $code_hash );
if ( ! is_array( $code_row ) || ! MAD4B_SCP_Local_OAuth_Store::mark_code_used( (int) $code_row['id'], $now ) ) exit( 1 );
if ( MAD4B_SCP_Local_OAuth_Store::mark_code_used( (int) $code_row['id'], $now ) ) exit( 1 );

$refresh_hash = hash( 'sha256', 'ci-refresh-' . wp_generate_uuid4() );
$replacement_hash = hash( 'sha256', 'ci-replacement-' . wp_generate_uuid4() );
$family = wp_generate_uuid4();
if ( ! MAD4B_SCP_Local_OAuth_Store::insert_refresh_token( array(
	'token_hash' => $refresh_hash,
	'family_id' => $family,
	'client_id' => 'https://client.example.test/mcp-client.json',
	'wp_user_id' => 1,
	'resource' => $resource,
	'scope' => 'mad4b:read offline_access',
	'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
	'created_at' => $now,
) ) ) exit( 1 );
$refresh_row = MAD4B_SCP_Local_OAuth_Store::get_refresh_token( $refresh_hash );
if ( ! is_array( $refresh_row ) || ! MAD4B_SCP_Local_OAuth_Store::rotate_refresh_token( (int) $refresh_row['id'], $now, $replacement_hash ) ) exit( 1 );
if ( MAD4B_SCP_Local_OAuth_Store::rotate_refresh_token( (int) $refresh_row['id'], $now, $replacement_hash ) ) exit( 1 );

$tables = MAD4B_SCP_Local_OAuth_Store::tables();
global $wpdb;
$wpdb->delete( $tables['codes'], array( 'code_hash' => $code_hash ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->delete( $tables['refresh_tokens'], array( 'family_id' => $family ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

echo "mad4b.site-control-plane.local-oauth-standalone.runtime.v1: PASS\n";
