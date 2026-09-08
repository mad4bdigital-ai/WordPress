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
if ( 'mad4b.local-oauth-server.v3' !== $status['contract'] ) exit( 1 );
if ( 'cimd_or_pre_registered' !== $status['client_registration_mode'] || empty( $status['client_id_metadata_document_supported'] ) || ! empty( $status['dynamic_client_registration_supported'] ) ) exit( 1 );
if ( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID !== $status['cimd_chatgpt_client_id'] ) exit( 1 );
if ( empty( $status['authorization_response_iss_parameter_supported'] ) || 'RS256' !== $status['access_token_signing_alg'] ) exit( 1 );
if ( empty( $status['issuer_same_origin_required'] ) || empty( $status['issuer_configuration_valid'] ) || empty( $status['consent_clickjacking_protected'] ) ) exit( 1 );
if ( 191 !== (int) $status['max_client_id_bytes'] || 191 !== MAD4B_SCP_Local_OAuth_Store::MAX_CLIENT_ID_BYTES ) exit( 1 );
if ( empty( $status['private_key_present'] ) || ! empty( $status['private_key_exposed'] ) || ! empty( $status['private_key_stored_in_database'] ) ) exit( 1 );

$metadata = MAD4B_SCP_Local_OAuth_Server::metadata();
if ( $metadata['issuer'] !== MAD4B_SCP_Local_OAuth_Server::issuer() ) exit( 1 );
if ( empty( $metadata['authorization_response_iss_parameter_supported'] ) || empty( $metadata['client_id_metadata_document_supported'] ) ) exit( 1 );
if ( ! in_array( 'S256', $metadata['code_challenge_methods_supported'], true ) ) exit( 1 );
if ( ! in_array( MAD4B_SCP_Local_OAuth_Server::resource_identifier(), $metadata['protected_resources'], true ) ) exit( 1 );
if ( isset( $metadata['registration_endpoint'] ) ) exit( 1 );
if ( false === strpos( MAD4B_SCP_Local_OAuth_Server::resource_identifier(), '/wp-json/mcp/mad4b-chatgpt' ) ) exit( 1 );

// Resolve the exact ChatGPT CIMD client without internet access. The runtime
// HTTP filter models ChatGPT's production metadata document and proves that an
// arbitrary HTTPS client URL is rejected before any outbound request occurs.
$chatgpt_fetches = 0;
$unexpected_fetches = 0;
$cimd_filter = static function ( $preempt, $args, $url ) use ( &$chatgpt_fetches, &$unexpected_fetches ) {
	if ( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID !== $url ) {
		++$unexpected_fetches;
		return new WP_Error( 'ci_unexpected_outbound', 'Unexpected CIMD outbound URL.' );
	}
	++$chatgpt_fetches;
	$payload = array(
		'client_id' => MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID,
		'client_name' => 'ChatGPT',
		'redirect_uris' => array( 'https://chatgpt.com/connector_platform_oauth_redirect' ),
		'grant_types' => array( 'authorization_code' ),
		'response_types' => array( 'code' ),
		'token_endpoint_auth_methods_supported' => array( 'none', 'private_key_jwt' ),
		'token_endpoint_auth_method' => 'private_key_jwt',
	);
	return array(
		'headers' => array( 'content-type' => 'application/json', 'cache-control' => 'max-age=120' ),
		'body' => wp_json_encode( $payload ),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies' => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $cimd_filter, -100, 3 );
$client_method = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'client' );
$client_method->setAccessible( true );
$chatgpt_client = $client_method->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID );
if ( ! is_array( $chatgpt_client ) || 'cimd' !== $chatgpt_client['registration_mode'] || 'ChatGPT' !== $chatgpt_client['client_name'] ) exit( 1 );
if ( ! in_array( 'https://chatgpt.com/connector_platform_oauth_redirect', $chatgpt_client['redirect_uris'], true ) ) exit( 1 );
if ( 1 !== $chatgpt_fetches ) exit( 1 );
$cached_chatgpt = $client_method->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID );
if ( ! is_array( $cached_chatgpt ) || 1 !== $chatgpt_fetches ) exit( 1 );
$untrusted_client = $client_method->invoke( null, 'https://evil.example.test/client.json' );
if ( null !== $untrusted_client || 0 !== $unexpected_fetches ) exit( 1 );
remove_filter( 'pre_http_request', $cimd_filter, -100 );

$validate_cimd = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'validate_cimd_metadata' );
$validate_cimd->setAccessible( true );
$wrong_identity = $validate_cimd->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID, array(
	'client_id' => 'https://chatgpt.com/oauth/other.json',
	'client_name' => 'ChatGPT',
	'redirect_uris' => array( 'https://chatgpt.com/connector_platform_oauth_redirect' ),
	'token_endpoint_auth_methods_supported' => array( 'none' ),
) );
if ( ! is_wp_error( $wrong_identity ) || 'invalid_client' !== $wrong_identity->get_error_code() ) exit( 1 );
$private_only = $validate_cimd->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID, array(
	'client_id' => MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID,
	'client_name' => 'ChatGPT',
	'redirect_uris' => array( 'https://chatgpt.com/connector_platform_oauth_redirect' ),
	'token_endpoint_auth_methods_supported' => array( 'private_key_jwt' ),
) );
if ( ! is_wp_error( $private_only ) ) exit( 1 );

$jwks = MAD4B_SCP_Local_OAuth_Server::jwks_document();
if ( is_wp_error( $jwks ) || empty( $jwks['keys'][0]['kid'] ) || empty( $jwks['keys'][0]['n'] ) || empty( $jwks['keys'][0]['e'] ) ) exit( 1 );
$key = $jwks['keys'][0];
if ( 'RSA' !== $key['kty'] || 'RS256' !== $key['alg'] || 'sig' !== $key['use'] || ! in_array( 'verify', $key['key_ops'], true ) ) exit( 1 );

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
$too_long_client = str_repeat( 'c', 192 );
if ( ! is_wp_error( $mint->invoke( null, $too_long_client, 1, $resource, array( 'mad4b:read' ) ) ) ) exit( 1 );

$request_param = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'request_param' );
$request_param->setAccessible( true );
$bounded = $request_param->invoke( null, array( 'client_id' => str_repeat( 'a', 191 ) ), 'client_id', 191, true );
$oversized = $request_param->invoke( null, array( 'client_id' => str_repeat( 'a', 192 ) ), 'client_id', 191, true );
$array_param = $request_param->invoke( null, array( 'client_id' => array( 'not', 'scalar' ) ), 'client_id', 191, true );
if ( is_wp_error( $bounded ) || ! is_wp_error( $oversized ) || ! is_wp_error( $array_param ) ) exit( 1 );

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
if ( MAD4B_SCP_Local_OAuth_Store::insert_code( array(
	'code_hash' => hash( 'sha256', 'ci-overlong-' . wp_generate_uuid4() ),
	'client_id' => $too_long_client,
	'wp_user_id' => 1,
	'redirect_uri' => 'https://client.example.test/callback',
	'resource' => $resource,
	'scope' => 'mad4b:read',
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
if ( MAD4B_SCP_Local_OAuth_Store::insert_refresh_token( array(
	'token_hash' => hash( 'sha256', 'ci-overlong-refresh-' . wp_generate_uuid4() ),
	'family_id' => wp_generate_uuid4(),
	'client_id' => $too_long_client,
	'wp_user_id' => 1,
	'resource' => $resource,
	'scope' => 'mad4b:read',
	'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
	'created_at' => $now,
) ) ) exit( 1 );
$refresh_row = MAD4B_SCP_Local_OAuth_Store::get_refresh_token( $refresh_hash );
if ( ! is_array( $refresh_row ) || ! MAD4B_SCP_Local_OAuth_Store::rotate_refresh_token( (int) $refresh_row['id'], $now, $replacement_hash ) ) exit( 1 );
if ( MAD4B_SCP_Local_OAuth_Store::rotate_refresh_token( (int) $refresh_row['id'], $now, $replacement_hash ) ) exit( 1 );

// Model the dangerous interleaving explicitly: request A has rotated the old
// token but has not inserted its replacement yet; request B detects replay and
// poisons the family. The replacement must never become a live token afterward.
MAD4B_SCP_Local_OAuth_Store::revoke_family( $family, $now );
if ( ! MAD4B_SCP_Local_OAuth_Store::family_is_revoked( $family ) ) exit( 1 );
if ( MAD4B_SCP_Local_OAuth_Store::insert_refresh_token( array(
	'token_hash' => $replacement_hash,
	'family_id' => $family,
	'client_id' => 'https://client.example.test/mcp-client.json',
	'wp_user_id' => 1,
	'resource' => $resource,
	'scope' => 'mad4b:read offline_access',
	'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
	'created_at' => $now,
) ) ) exit( 1 );
if ( is_array( MAD4B_SCP_Local_OAuth_Store::get_refresh_token( $replacement_hash ) ) ) exit( 1 );

$tables = MAD4B_SCP_Local_OAuth_Store::tables();
global $wpdb;
$wpdb->delete( $tables['codes'], array( 'code_hash' => $code_hash ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->delete( $tables['refresh_tokens'], array( 'family_id' => $family ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

// Cross-origin issuer configuration is a configuration error, not an alternate
// local authority. Define it only after all valid-authority proofs above so the
// same process can verify the fail-closed transition deterministically.
if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ISSUER' ) ) define( 'MAD4B_MCP_LOCAL_OAUTH_ISSUER', 'https://foreign-issuer.example.test/oauth/mcp' );
$issuer_validation = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'configured_issuer_validation' );
$issuer_validation->setAccessible( true );
$issuer_error = $issuer_validation->invoke( null );
if ( ! is_wp_error( $issuer_error ) || 'mad4b_local_oauth_issuer_cross_origin' !== $issuer_error->get_error_code() ) exit( 1 );
$invalid_status = MAD4B_SCP_Local_OAuth_Server::status();
if ( ! empty( $invalid_status['effective'] ) || ! empty( $invalid_status['issuer_configuration_valid'] ) ) exit( 1 );

echo "mad4b.site-control-plane.local-oauth-standalone.runtime.v4: PASS\n";
