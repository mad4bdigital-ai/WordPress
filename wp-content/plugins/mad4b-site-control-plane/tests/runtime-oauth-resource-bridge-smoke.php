<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';

function mad4b_oauth_smoke_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}
function mad4b_oauth_b64url( $value ) { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
function mad4b_oauth_jwt( array $claims, $private_key, $kid ) {
	$header = array( 'alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid );
	$input = mad4b_oauth_b64url( wp_json_encode( $header ) ) . '.' . mad4b_oauth_b64url( wp_json_encode( $claims ) );
	$signature = '';
	if ( ! openssl_sign( $input, $signature, $private_key, OPENSSL_ALGO_SHA256 ) ) mad4b_oauth_smoke_fail( 'Unable to sign test JWT.' );
	return $input . '.' . mad4b_oauth_b64url( $signature );
}
function mad4b_oauth_request( $token = '' ) {
	$request = new WP_REST_Request( 'POST', '/mcp/mad4b-read' );
	if ( '' !== $token ) $request->set_header( 'Authorization', 'Bearer ' . $token );
	return $request;
}
function mad4b_oauth_status_code( $value ) { return $value instanceof WP_REST_Response ? $value->get_status() : 0; }
function mad4b_oauth_http_response( $code, $body ) {
	return array( 'headers' => array(), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 200 === $code ? 'OK' : 'Not Found' ), 'cookies' => array(), 'filename' => null );
}

if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) mad4b_oauth_smoke_fail( 'OAuth resource bridge class unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_OAuth_Subject_Gate' ) ) mad4b_oauth_smoke_fail( 'OAuth subject gate class unavailable.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_ENABLED' ) || true !== MAD4B_MCP_OAUTH_ENABLED ) mad4b_oauth_smoke_fail( 'OAuth test requires enabled bridge.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) mad4b_oauth_smoke_fail( 'OAuth issuer missing.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) mad4b_oauth_smoke_fail( 'OAuth WP user missing.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) mad4b_oauth_smoke_fail( 'OAuth external subject allowlist missing.' );

$gate_priority = has_filter( 'rest_pre_dispatch', array( 'MAD4B_SCP_OAuth_Subject_Gate', 'enforce' ) );
$bridge_priority = has_filter( 'rest_pre_dispatch', array( 'MAD4B_SCP_OAuth_Resource_Bridge', 'authenticate_rest_request' ) );
if ( 0 !== $gate_priority || 1 !== $bridge_priority ) mad4b_oauth_smoke_fail( 'OAuth subject gate must deny before bridge privileged mapping.', array( 'gate' => $gate_priority, 'bridge' => $bridge_priority ) );

$status = MAD4B_SCP_OAuth_Resource_Bridge::status();
if ( empty( $status['effective'] ) || 'staging' !== $status['environment'] ) mad4b_oauth_smoke_fail( 'OAuth bridge should be effective only for staging runtime.', $status );
if ( 'https' !== wp_parse_url( $status['resource'], PHP_URL_SCHEME ) ) mad4b_oauth_smoke_fail( 'OAuth resource must be HTTPS.', $status );
if ( array( 'mad4b:read' ) !== $status['scopes_supported'] ) mad4b_oauth_smoke_fail( 'Unexpected OAuth scope contract.', $status );
if ( ! empty( $status['stores_bearer_tokens'] ) || ! empty( $status['creates_credentials'] ) || ! empty( $status['write_surfaces_enabled'] ) ) mad4b_oauth_smoke_fail( 'OAuth bridge safety claims invalid.', $status );
if ( array( 'RS256' ) !== $status['accepted_bearer_algorithms'] || empty( $status['jwks_rsa_ne_supported'] ) || ! empty( $status['jwks_x5c_required'] ) ) mad4b_oauth_smoke_fail( 'OAuth JWKS compatibility truth is invalid.', $status );
$gate_status = MAD4B_SCP_OAuth_Subject_Gate::status();
if ( empty( $gate_status['configured'] ) || empty( $gate_status['effective'] ) || 1 !== (int) $gate_status['allowed_subject_count'] || empty( $gate_status['fail_closed'] ) ) mad4b_oauth_smoke_fail( 'OAuth subject gate should be configured and effective.', $gate_status );
if ( 'iss+sub+aud+resource' !== $gate_status['binding'] || 'deny-only' !== $gate_status['claims_used_before_signature'] ) mad4b_oauth_smoke_fail( 'OAuth subject gate binding/order truth is invalid.', $gate_status );

$issuer = rtrim( (string) MAD4B_MCP_OAUTH_ISSUER, '/' );
$issuer_parts = wp_parse_url( $issuer );
$issuer_origin = $issuer_parts['scheme'] . '://' . $issuer_parts['host'];
$rfc8414 = $issuer_origin . '/.well-known/oauth-authorization-server' . ( isset( $issuer_parts['path'] ) ? $issuer_parts['path'] : '' );
$documents = MAD4B_SCP_OAuth_Resource_Bridge::authorization_server_metadata_urls();
if ( ! in_array( $rfc8414, $documents, true ) ) mad4b_oauth_smoke_fail( 'RFC 8414 path-scoped issuer metadata candidate missing.', $documents );

$private_key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 ) );
if ( false === $private_key ) mad4b_oauth_smoke_fail( 'Unable to create RSA key.' );
$key_details = openssl_pkey_get_details( $private_key );
if ( ! is_array( $key_details ) || empty( $key_details['rsa']['n'] ) || empty( $key_details['rsa']['e'] ) ) mad4b_oauth_smoke_fail( 'Unable to extract RSA JWK material.' );
$kid = 'mad4b-ci-key';
$jwks_uri = $issuer_origin . '/auth/mcp/.well-known/jwks.json';
$jwk = array(
	'kty' => 'RSA',
	'use' => 'sig',
	'alg' => 'RS256',
	'kid' => $kid,
	'n' => mad4b_oauth_b64url( $key_details['rsa']['n'] ),
	'e' => mad4b_oauth_b64url( $key_details['rsa']['e'] ),
);

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) use ( $issuer, $rfc8414, $jwks_uri, $jwk ) {
		if ( $issuer . '/.well-known/openid-configuration' === $url ) return mad4b_oauth_http_response( 404, '{}' );
		if ( $rfc8414 === $url ) {
			return mad4b_oauth_http_response( 200, array(
				'issuer' => $issuer,
				'authorization_endpoint' => $issuer . '/oauth/authorize',
				'token_endpoint' => $issuer . '/oauth/token',
				'jwks_uri' => $jwks_uri,
				'code_challenge_methods_supported' => array( 'S256' ),
			) );
		}
		if ( $jwks_uri === $url ) return mad4b_oauth_http_response( 200, array( 'keys' => array( $jwk ) ) );
		return $preempt;
	},
	10,
	3
);

$now = time();
$resource = MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier();
$base_claims = array(
	'iss' => $issuer,
	'sub' => 'user:user-1',
	'aud' => $resource,
	'resource' => $resource,
	'exp' => $now + 300,
	'nbf' => $now - 5,
	'iat' => $now - 5,
	'scope' => 'mad4b:read',
);
$token = mad4b_oauth_jwt( $base_claims, $private_key, $kid );

// The subject gate is deny-only and runs before the cryptographic bridge.
wp_set_current_user( 0 );
$request = mad4b_oauth_request( $token );
$gate_result = MAD4B_SCP_OAuth_Subject_Gate::enforce( null, null, $request );
if ( null !== $gate_result || 0 !== get_current_user_id() ) mad4b_oauth_smoke_fail( 'Approved subject gate must not itself grant WordPress identity.', $gate_result );
$result = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, $request );
if ( null !== $result ) mad4b_oauth_smoke_fail( 'Valid bearer should pass cryptographic pre-dispatch.', $result );
$identity = MAD4B_SCP_Identity_Context::current();
if ( is_wp_error( $identity ) || empty( $identity['authenticated'] ) || 'oauth' !== $identity['subject_type'] || 'oauth2_bearer' !== $identity['auth_method'] ) mad4b_oauth_smoke_fail( 'Verified bearer did not create normalized OAuth identity.', $identity );
if ( ! in_array( 'mad4b:read', $identity['token_scopes'], true ) || empty( $identity['subject_fingerprint'] ) ) mad4b_oauth_smoke_fail( 'OAuth identity scope/fingerprint missing.', $identity );
if ( false !== strpos( wp_json_encode( $identity ), $token ) ) mad4b_oauth_smoke_fail( 'Raw bearer token leaked into identity context.' );
if ( (int) MAD4B_MCP_OAUTH_WP_USER_ID !== get_current_user_id() || ! current_user_can( 'manage_options' ) ) mad4b_oauth_smoke_fail( 'OAuth identity did not bind exact configured WordPress subject.' );

// An unapproved but otherwise well-formed subject is denied before the bridge
// can map the fixed privileged WordPress service identity.
$unapproved = $base_claims; $unapproved['sub'] = 'user:user-2';
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Subject_Gate::enforce( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $unapproved, $private_key, $kid ) ) );
if ( 403 !== mad4b_oauth_status_code( $response ) || 0 !== get_current_user_id() ) mad4b_oauth_smoke_fail( 'Unapproved OAuth subject must fail before privileged WordPress mapping.', $response );

$wrong_resource_claim = $base_claims; $wrong_resource_claim['resource'] = 'https://example.invalid/not-mad4b';
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Subject_Gate::enforce( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $wrong_resource_claim, $private_key, $kid ) ) );
if ( 403 !== mad4b_oauth_status_code( $response ) || 0 !== get_current_user_id() ) mad4b_oauth_smoke_fail( 'Wrong resource claim must fail before privileged WordPress mapping.', $response );

$wrong_aud = $base_claims; $wrong_aud['aud'] = 'https://example.invalid/not-mad4b';
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $wrong_aud, $private_key, $kid ) ) );
if ( 401 !== mad4b_oauth_status_code( $response ) ) mad4b_oauth_smoke_fail( 'Wrong audience must fail 401.', $response );

$wrong_scope = $base_claims; $wrong_scope['scope'] = 'profile';
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $wrong_scope, $private_key, $kid ) ) );
if ( 401 !== mad4b_oauth_status_code( $response ) ) mad4b_oauth_smoke_fail( 'Missing mad4b:read scope must fail 401.', $response );

$expired = $base_claims; $expired['exp'] = $now - 300;
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $expired, $private_key, $kid ) ) );
if ( 401 !== mad4b_oauth_status_code( $response ) ) mad4b_oauth_smoke_fail( 'Expired token must fail 401.', $response );

wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request() );
if ( 401 !== mad4b_oauth_status_code( $response ) ) mad4b_oauth_smoke_fail( 'Anonymous request without bearer must fail 401.', $response );
$challenge = $response instanceof WP_REST_Response ? $response->get_headers() : array();
$expected_metadata = class_exists( 'MAD4B_SCP_MCP_Client_Compatibility' ) ? MAD4B_SCP_MCP_Client_Compatibility::authoritative_well_known_url() : '';
if ( empty( $challenge['WWW-Authenticate'] ) || false === strpos( $challenge['WWW-Authenticate'], 'resource_metadata=' ) || false === strpos( $challenge['WWW-Authenticate'], 'mad4b:read' ) || ( $expected_metadata && false === strpos( $challenge['WWW-Authenticate'], $expected_metadata ) ) ) mad4b_oauth_smoke_fail( '401 must advertise authoritative protected-resource metadata and read scope.', $challenge );

$metadata = MAD4B_SCP_OAuth_Resource_Bridge::protected_resource_metadata();
if ( $status['resource'] !== $metadata['resource'] || array( $issuer ) !== $metadata['authorization_servers'] || array( 'mad4b:read' ) !== $metadata['scopes_supported'] ) mad4b_oauth_smoke_fail( 'Protected resource metadata mismatch.', $metadata );

echo 'mad4b.site-control-plane.runtime-oauth-resource-bridge.v3: PASS' . PHP_EOL;
