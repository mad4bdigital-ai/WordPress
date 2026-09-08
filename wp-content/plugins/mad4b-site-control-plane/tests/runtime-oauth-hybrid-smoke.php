<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';

function mad4b_hybrid_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}
function mad4b_hybrid_b64url( $value ) { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
function mad4b_hybrid_jwt( array $claims, $private_key, $kid ) {
	$header = array( 'alg' => 'RS256', 'typ' => 'at+jwt', 'kid' => $kid );
	$input = mad4b_hybrid_b64url( wp_json_encode( $header ) ) . '.' . mad4b_hybrid_b64url( wp_json_encode( $claims ) );
	$signature = '';
	if ( ! openssl_sign( $input, $signature, $private_key, OPENSSL_ALGO_SHA256 ) ) mad4b_hybrid_fail( 'Unable to sign hybrid test JWT.' );
	return $input . '.' . mad4b_hybrid_b64url( $signature );
}
function mad4b_hybrid_request( $token ) {
	$request = new WP_REST_Request( 'POST', '/mcp/mad4b-read' );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	return $request;
}
function mad4b_hybrid_http_response( $code, $body ) {
	return array( 'headers' => array(), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 200 === $code ? 'OK' : 'Not Found' ), 'cookies' => array(), 'filename' => null );
}
function mad4b_hybrid_status_code( $value ) { return $value instanceof WP_REST_Response ? $value->get_status() : 0; }

foreach ( array( 'MAD4B_SCP_Local_OAuth_Server', 'MAD4B_SCP_OAuth_Resource_Bridge', 'MAD4B_SCP_OAuth_Subject_Gate', 'MAD4B_SCP_Governed_Ability_Overrides' ) as $class ) {
	if ( ! class_exists( $class ) ) mad4b_hybrid_fail( 'Required hybrid class unavailable.', $class );
}
if ( ! defined( 'MAD4B_MCP_OAUTH_MODE' ) || 'hybrid' !== MAD4B_MCP_OAUTH_MODE ) mad4b_hybrid_fail( 'Hybrid mode constant is not effective.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ) ) mad4b_hybrid_fail( 'Issuer-bound subject policy missing.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) mad4b_hybrid_fail( 'Local compatibility alias was not derived during plugin boot.' );

MAD4B_SCP_Local_OAuth_Server::ensure_runtime();
$local_status = MAD4B_SCP_Local_OAuth_Server::status();
if ( empty( $local_status['effective'] ) ) mad4b_hybrid_fail( 'Local OAuth authority is not effective in hybrid mode.', $local_status );

$local_issuer = MAD4B_SCP_Local_OAuth_Server::issuer();
$external_issuer = rtrim( (string) MAD4B_MCP_OAUTH_ISSUER, '/' );
$resource = MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier();
$status = MAD4B_SCP_OAuth_Resource_Bridge::status();
if ( empty( $status['effective'] ) || 'hybrid' !== $status['authority_mode'] || 2 !== (int) $status['authority_count'] ) mad4b_hybrid_fail( 'Hybrid authority registry is not effective.', $status );
if ( empty( $status['authority_registry_valid'] ) || empty( $status['subject_policy_ready'] ) ) mad4b_hybrid_fail( 'Hybrid authority registry is not policy-ready.', $status );
$trusted = MAD4B_SCP_OAuth_Resource_Bridge::trusted_issuers();
if ( ! in_array( $local_issuer, $trusted, true ) || ! in_array( $external_issuer, $trusted, true ) ) mad4b_hybrid_fail( 'Hybrid trusted issuers are incomplete.', $trusted );

if ( ! MAD4B_SCP_OAuth_Resource_Bridge::subject_allowed( $local_issuer, 'user:1' ) ) mad4b_hybrid_fail( 'Local issuer-bound subject was not accepted.' );
if ( ! MAD4B_SCP_OAuth_Resource_Bridge::subject_allowed( $external_issuer, 'user:user-1' ) ) mad4b_hybrid_fail( 'External issuer-bound subject was not accepted.' );
if ( MAD4B_SCP_OAuth_Resource_Bridge::subject_allowed( $external_issuer, 'user:1' ) ) mad4b_hybrid_fail( 'Cross-authority subject confusion was accepted.' );

$metadata = MAD4B_SCP_OAuth_Resource_Bridge::protected_resource_metadata();
if ( 2 !== count( $metadata['authorization_servers'] ) || $resource !== $metadata['resource'] ) mad4b_hybrid_fail( 'Hybrid protected-resource metadata is incomplete.', $metadata );

// Mint a real token with the WordPress-local private key/JWK and prove the same
// resource bridge verifies it as one member of the hybrid trust registry.
$mint = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'mint_access_token' );
$mint->setAccessible( true );
$local_token = $mint->invoke( null, 'https://client.example.test/mcp-client.json', 1, $resource, array( 'mad4b:read' ) );
if ( is_wp_error( $local_token ) ) mad4b_hybrid_fail( 'Unable to mint local hybrid token.', $local_token->get_error_message() );
wp_set_current_user( 0 );
$request = mad4b_hybrid_request( $local_token );
$gate = MAD4B_SCP_OAuth_Subject_Gate::enforce( null, null, $request );
if ( null !== $gate ) mad4b_hybrid_fail( 'Local hybrid token failed subject pre-gate.', $gate );
$result = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, $request );
if ( null !== $result ) mad4b_hybrid_fail( 'Local hybrid token failed cryptographic bridge.', $result );
$identity = MAD4B_SCP_Identity_Context::current();
if ( is_wp_error( $identity ) || 'oauth2_bearer' !== $identity['auth_method'] ) mad4b_hybrid_fail( 'Local hybrid token did not produce OAuth identity.', $identity );
$restricted = MAD4B_SCP_Governed_Ability_Overrides::can_remote_oauth_read_ability( 'mad4b/database-select' );
if ( ! is_wp_error( $restricted ) || 'mad4b_remote_oauth_sensitive_read_denied' !== $restricted->get_error_code() ) mad4b_hybrid_fail( 'Local-issued remote bearer bypassed sensitive read default deny.', $restricted );

// Configure an independent external authority on another origin.
$external_key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 ) );
if ( false === $external_key ) mad4b_hybrid_fail( 'Unable to create external hybrid RSA key.' );
$details = openssl_pkey_get_details( $external_key );
$external_kid = 'mad4b-hybrid-external';
$external_jwk = array(
	'kty' => 'RSA', 'use' => 'sig', 'key_ops' => array( 'verify' ), 'alg' => 'RS256', 'kid' => $external_kid,
	'n' => mad4b_hybrid_b64url( $details['rsa']['n'] ), 'e' => mad4b_hybrid_b64url( $details['rsa']['e'] ),
);
$parts = wp_parse_url( $external_issuer );
$origin = $parts['scheme'] . '://' . $parts['host'];
$rfc8414 = $origin . '/.well-known/oauth-authorization-server' . ( isset( $parts['path'] ) ? $parts['path'] : '' );
$jwks_uri = $origin . '/auth/mcp/.well-known/jwks.json';
$external_calls = 0;
add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) use ( $external_issuer, $rfc8414, $jwks_uri, $external_jwk, &$external_calls ) {
		++$external_calls;
		if ( $external_issuer . '/.well-known/openid-configuration' === $url ) return mad4b_hybrid_http_response( 404, '{}' );
		if ( $rfc8414 === $url ) return mad4b_hybrid_http_response( 200, array(
			'issuer' => $external_issuer,
			'authorization_endpoint' => $external_issuer . '/oauth/authorize',
			'token_endpoint' => $external_issuer . '/oauth/token',
			'jwks_uri' => $jwks_uri,
			'code_challenge_methods_supported' => array( 'S256' ),
		) );
		if ( $jwks_uri === $url ) return mad4b_hybrid_http_response( 200, array( 'keys' => array( $external_jwk ) ) );
		return $preempt;
	},
	20,
	3
);

$now = time();
$external_claims = array(
	'iss' => $external_issuer,
	'sub' => 'user:user-1',
	'aud' => $resource,
	'resource' => $resource,
	'exp' => $now + 300,
	'nbf' => $now - 5,
	'iat' => $now - 5,
	'scope' => 'mad4b:read',
);
$external_token = mad4b_hybrid_jwt( $external_claims, $external_key, $external_kid );
wp_set_current_user( 0 );
$request = mad4b_hybrid_request( $external_token );
if ( null !== MAD4B_SCP_OAuth_Subject_Gate::enforce( null, null, $request ) ) mad4b_hybrid_fail( 'External hybrid token failed subject pre-gate.' );
$result = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, $request );
if ( null !== $result ) mad4b_hybrid_fail( 'External hybrid token failed cryptographic bridge.', $result );

// Same textual subject as the local authority must not inherit local authority.
$confused = $external_claims;
$confused['sub'] = 'user:1';
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_hybrid_request( mad4b_hybrid_jwt( $confused, $external_key, $external_kid ) ) );
if ( 403 !== mad4b_hybrid_status_code( $response ) ) mad4b_hybrid_fail( 'Cross-authority subject confusion must fail after signature verification.', $response );

// Unknown issuer is not allowed to trigger discovery.
$unknown = $external_claims;
$unknown['iss'] = 'https://unknown.example.invalid/oauth';
$calls_before = $external_calls;
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_hybrid_request( mad4b_hybrid_jwt( $unknown, $external_key, $external_kid ) ) );
if ( 401 !== mad4b_hybrid_status_code( $response ) || $calls_before !== $external_calls ) mad4b_hybrid_fail( 'Unknown hybrid issuer must fail before outbound discovery.', array( 'before' => $calls_before, 'after' => $external_calls ) );

$compat = MAD4B_SCP_MCP_Client_Compatibility::status();
if ( 'hybrid' !== $compat['oauth_authority_mode'] || empty( $compat['authorization_server_hybrid'] ) || empty( $compat['authorization_server_local'] ) || empty( $compat['authorization_server_external'] ) || 2 !== (int) $compat['authorization_server_count'] ) mad4b_hybrid_fail( 'Client compatibility metadata does not truthfully expose hybrid authority.', $compat );
$manifest = MAD4B_SCP_MCP_Client_Compatibility::manifest();
if ( 'hybrid' !== $manifest['authentication']['authority_mode'] || 2 !== count( $manifest['authentication']['authorization_servers'] ) ) mad4b_hybrid_fail( 'Hybrid client manifest authority metadata is incomplete.', $manifest );
if ( 'deny_sensitive_generic_introspection' !== $manifest['remote_oauth_read_policy']['default'] ) mad4b_hybrid_fail( 'Hybrid client manifest omits remote read blast-radius policy.', $manifest );

echo 'mad4b.site-control-plane.runtime-oauth-hybrid.v1: PASS' . PHP_EOL;
