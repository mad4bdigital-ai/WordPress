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

if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) mad4b_oauth_smoke_fail( 'OAuth resource bridge class unavailable.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_ENABLED' ) || true !== MAD4B_MCP_OAUTH_ENABLED ) mad4b_oauth_smoke_fail( 'OAuth test requires enabled bridge.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) mad4b_oauth_smoke_fail( 'OAuth issuer missing.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) mad4b_oauth_smoke_fail( 'OAuth WP user missing.' );

$status = MAD4B_SCP_OAuth_Resource_Bridge::status();
if ( empty( $status['effective'] ) || 'staging' !== $status['environment'] ) mad4b_oauth_smoke_fail( 'OAuth bridge should be effective only for staging runtime.', $status );
if ( 'https' !== wp_parse_url( $status['resource'], PHP_URL_SCHEME ) ) mad4b_oauth_smoke_fail( 'OAuth resource must be HTTPS.', $status );
if ( array( 'mad4b:read' ) !== $status['scopes_supported'] ) mad4b_oauth_smoke_fail( 'Unexpected OAuth scope contract.', $status );
if ( ! empty( $status['stores_bearer_tokens'] ) || ! empty( $status['creates_credentials'] ) || ! empty( $status['write_surfaces_enabled'] ) ) mad4b_oauth_smoke_fail( 'OAuth bridge safety claims invalid.', $status );

$private_key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 ) );
if ( false === $private_key ) mad4b_oauth_smoke_fail( 'Unable to create RSA key.' );
$csr = openssl_csr_new( array( 'commonName' => 'auth.mad4b.test' ), $private_key, array( 'digest_alg' => 'sha256' ) );
$cert = openssl_csr_sign( $csr, null, $private_key, 2, array( 'digest_alg' => 'sha256' ) );
$pem = '';
if ( ! openssl_x509_export( $cert, $pem ) ) mad4b_oauth_smoke_fail( 'Unable to export certificate.' );
$x5c = preg_replace( '/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $pem );
$kid = 'mad4b-ci-key';
$issuer = rtrim( (string) MAD4B_MCP_OAUTH_ISSUER, '/' );
$jwks_uri = $issuer . '/.well-known/jwks.json';

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) use ( $issuer, $jwks_uri, $kid, $x5c ) {
		if ( $issuer . '/.well-known/openid-configuration' === $url ) {
			$body = array(
				'issuer' => $issuer,
				'authorization_endpoint' => $issuer . '/authorize',
				'token_endpoint' => $issuer . '/oauth/token',
				'jwks_uri' => $jwks_uri,
				'code_challenge_methods_supported' => array( 'S256' ),
			);
			return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		if ( $jwks_uri === $url ) {
			$body = array( 'keys' => array( array( 'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'x5c' => array( $x5c ) ) ) );
			return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return $preempt;
	},
	10,
	3
);

$now = time();
$base_claims = array(
	'iss' => $issuer,
	'sub' => 'chatgpt-staging-ci-subject',
	'aud' => MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier(),
	'exp' => $now + 300,
	'nbf' => $now - 5,
	'iat' => $now - 5,
	'scope' => 'mad4b:read',
);
$token = mad4b_oauth_jwt( $base_claims, $private_key, $kid );
wp_set_current_user( 0 );
$result = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( $token ) );
if ( null !== $result ) mad4b_oauth_smoke_fail( 'Valid bearer should pass pre-dispatch.', $result );
$identity = MAD4B_SCP_Identity_Context::current();
if ( is_wp_error( $identity ) || empty( $identity['authenticated'] ) || 'oauth' !== $identity['subject_type'] || 'oauth2_bearer' !== $identity['auth_method'] ) mad4b_oauth_smoke_fail( 'Verified bearer did not create normalized OAuth identity.', $identity );
if ( ! in_array( 'mad4b:read', $identity['token_scopes'], true ) || empty( $identity['subject_fingerprint'] ) ) mad4b_oauth_smoke_fail( 'OAuth identity scope/fingerprint missing.', $identity );
if ( false !== strpos( wp_json_encode( $identity ), $token ) ) mad4b_oauth_smoke_fail( 'Raw bearer token leaked into identity context.' );
if ( (int) MAD4B_MCP_OAUTH_WP_USER_ID !== get_current_user_id() || ! current_user_can( 'manage_options' ) ) mad4b_oauth_smoke_fail( 'OAuth identity did not bind exact configured WordPress subject.' );

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
if ( empty( $challenge['WWW-Authenticate'] ) || false === strpos( $challenge['WWW-Authenticate'], 'resource_metadata=' ) || false === strpos( $challenge['WWW-Authenticate'], 'mad4b:read' ) ) mad4b_oauth_smoke_fail( '401 must advertise protected-resource metadata and read scope.', $challenge );

$metadata = MAD4B_SCP_OAuth_Resource_Bridge::protected_resource_metadata();
if ( $status['resource'] !== $metadata['resource'] || array( $issuer ) !== $metadata['authorization_servers'] || array( 'mad4b:read' ) !== $metadata['scopes_supported'] ) mad4b_oauth_smoke_fail( 'Protected resource metadata mismatch.', $metadata );

echo 'mad4b.site-control-plane.runtime-oauth-resource-bridge.v1: PASS' . PHP_EOL;
