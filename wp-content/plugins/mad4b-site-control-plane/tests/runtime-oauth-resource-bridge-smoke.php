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
	$header = array( 'alg' => 'RS256', 'typ' => 'at+jwt', 'kid' => $kid );
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
function mad4b_oauth_jwk_from_key( $private_key, $kid, $extra = array() ) {
	$details = openssl_pkey_get_details( $private_key );
	if ( ! is_array( $details ) || empty( $details['rsa']['n'] ) || empty( $details['rsa']['e'] ) ) mad4b_oauth_smoke_fail( 'Unable to derive JWK material.', $kid );
	return array_merge(
		array(
			'kty' => 'RSA',
			'use' => 'sig',
			'key_ops' => array( 'verify' ),
			'alg' => 'RS256',
			'kid' => $kid,
			'n' => mad4b_oauth_b64url( $details['rsa']['n'] ),
			'e' => mad4b_oauth_b64url( $details['rsa']['e'] ),
		),
		$extra
	);
}

if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) mad4b_oauth_smoke_fail( 'OAuth resource bridge class unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_OAuth_Subject_Gate' ) ) mad4b_oauth_smoke_fail( 'OAuth subject gate class unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_Governed_Ability_Overrides' ) ) mad4b_oauth_smoke_fail( 'Governed ability overrides unavailable.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_ENABLED' ) || true !== MAD4B_MCP_OAUTH_ENABLED ) mad4b_oauth_smoke_fail( 'OAuth test requires enabled bridge.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) mad4b_oauth_smoke_fail( 'OAuth issuer missing.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) mad4b_oauth_smoke_fail( 'OAuth WP user missing.' );
if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) mad4b_oauth_smoke_fail( 'OAuth external subject allowlist missing.' );

$gate_priority = has_filter( 'rest_pre_dispatch', array( 'MAD4B_SCP_OAuth_Subject_Gate', 'enforce' ) );
$bridge_priority = has_filter( 'rest_pre_dispatch', array( 'MAD4B_SCP_OAuth_Resource_Bridge', 'authenticate_rest_request' ) );
if ( 0 !== $gate_priority || 1 !== $bridge_priority ) mad4b_oauth_smoke_fail( 'OAuth subject gate must deny before bridge privileged mapping.', array( 'gate' => $gate_priority, 'bridge' => $bridge_priority ) );

$status = MAD4B_SCP_OAuth_Resource_Bridge::status();
if ( empty( $status['effective'] ) || 'staging' !== $status['environment'] ) mad4b_oauth_smoke_fail( 'OAuth bridge should be effective for staging runtime.', $status );
if ( 'external' !== $status['authority_mode'] || 1 !== (int) $status['authority_count'] ) mad4b_oauth_smoke_fail( 'Single external authority mode drifted.', $status );
if ( empty( $status['authority_registry_valid'] ) || empty( $status['subject_policy_ready'] ) ) mad4b_oauth_smoke_fail( 'External authority registry should be policy-ready.', $status );
if ( 2048 !== (int) $status['jwks_minimum_rsa_bits'] || empty( $status['jwks_use_sig_enforced'] ) || empty( $status['jwks_key_ops_verify_enforced'] ) || empty( $status['jwt_resource_claim_required'] ) ) mad4b_oauth_smoke_fail( 'JWK/JWT hardening truth is incomplete.', $status );
if ( ! empty( $status['stores_bearer_tokens'] ) || ! empty( $status['creates_credentials'] ) || ! empty( $status['write_surfaces_enabled'] ) ) mad4b_oauth_smoke_fail( 'OAuth bridge safety claims invalid.', $status );

$gate_status = MAD4B_SCP_OAuth_Subject_Gate::status();
if ( empty( $gate_status['configured'] ) || empty( $gate_status['effective'] ) || empty( $gate_status['post_signature_subject_reauthorization'] ) ) mad4b_oauth_smoke_fail( 'OAuth subject gate should be configured, effective and cryptographically rechecked.', $gate_status );
if ( 'issuer+subject+aud+resource' !== $gate_status['binding'] || 'deny-only' !== $gate_status['claims_used_before_signature'] ) mad4b_oauth_smoke_fail( 'OAuth subject gate binding/order truth is invalid.', $gate_status );

$read_policy = MAD4B_SCP_Governed_Ability_Overrides::remote_oauth_read_policy_status();
if ( 'deny_sensitive_generic_introspection' !== $read_policy['default'] ) mad4b_oauth_smoke_fail( 'Remote OAuth read policy must be default-deny for generic sensitive introspection.', $read_policy );
if ( true !== MAD4B_SCP_Governed_Ability_Overrides::can_remote_oauth_read_ability( 'mad4b/filesystem-read' ) ) mad4b_oauth_smoke_fail( 'Local/non-bearer read policy should remain unchanged.' );

$issuer = rtrim( (string) MAD4B_MCP_OAUTH_ISSUER, '/' );
$issuer_parts = wp_parse_url( $issuer );
$issuer_origin = $issuer_parts['scheme'] . '://' . $issuer_parts['host'];
$rfc8414 = $issuer_origin . '/.well-known/oauth-authorization-server' . ( isset( $issuer_parts['path'] ) ? $issuer_parts['path'] : '' );
$documents = MAD4B_SCP_OAuth_Resource_Bridge::authorization_server_metadata_urls( $issuer );
if ( ! in_array( $rfc8414, $documents, true ) ) mad4b_oauth_smoke_fail( 'RFC 8414 path-scoped issuer metadata candidate missing.', $documents );

$private_key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 ) );
$weak_key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024 ) );
$rotated_key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 ) );
if ( false === $private_key || false === $weak_key || false === $rotated_key ) mad4b_oauth_smoke_fail( 'Unable to create RSA test keys.' );

$kid = 'mad4b-ci-key';
$weak_kid = 'mad4b-ci-weak';
$wrong_use_kid = 'mad4b-ci-enc';
$wrong_ops_kid = 'mad4b-ci-sign-only';
$duplicate_kid = 'mad4b-ci-duplicate';
$rotated_kid = 'mad4b-ci-rotated';
$jwks_uri = $issuer_origin . '/auth/mcp/.well-known/jwks.json';

$valid_jwk = mad4b_oauth_jwk_from_key( $private_key, $kid );
$weak_jwk = mad4b_oauth_jwk_from_key( $weak_key, $weak_kid );
$wrong_use_jwk = mad4b_oauth_jwk_from_key( $private_key, $wrong_use_kid, array( 'use' => 'enc' ) );
$wrong_ops_jwk = mad4b_oauth_jwk_from_key( $private_key, $wrong_ops_kid, array( 'key_ops' => array( 'sign' ) ) );
$duplicate_a = mad4b_oauth_jwk_from_key( $private_key, $duplicate_kid );
$duplicate_b = mad4b_oauth_jwk_from_key( $rotated_key, $duplicate_kid );
$rotated_jwk = mad4b_oauth_jwk_from_key( $rotated_key, $rotated_kid );
$initial_keys = array( $valid_jwk, $weak_jwk, $wrong_use_jwk, $wrong_ops_jwk, $duplicate_a, $duplicate_b );
$jwks_fetches = 0;
$http_calls = 0;

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) use ( $issuer, $rfc8414, $jwks_uri, $initial_keys, $rotated_jwk, &$jwks_fetches, &$http_calls ) {
		++$http_calls;
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
		if ( $jwks_uri === $url ) {
			++$jwks_fetches;
			$keys = 1 < $jwks_fetches ? array_merge( $initial_keys, array( $rotated_jwk ) ) : $initial_keys;
			return mad4b_oauth_http_response( 200, array( 'keys' => $keys ) );
		}
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

wp_set_current_user( 0 );
$request = mad4b_oauth_request( $token );
$gate_result = MAD4B_SCP_OAuth_Subject_Gate::enforce( null, null, $request );
if ( null !== $gate_result || 0 !== get_current_user_id() ) mad4b_oauth_smoke_fail( 'Approved subject gate must not itself grant WordPress identity.', $gate_result );
$result = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, $request );
if ( null !== $result ) mad4b_oauth_smoke_fail( 'Valid bearer should pass cryptographic pre-dispatch.', $result );
$identity = MAD4B_SCP_Identity_Context::current();
if ( is_wp_error( $identity ) || empty( $identity['authenticated'] ) || 'oauth' !== $identity['subject_type'] || 'oauth2_bearer' !== $identity['auth_method'] ) mad4b_oauth_smoke_fail( 'Verified bearer did not create normalized OAuth identity.', $identity );
if ( false !== strpos( wp_json_encode( $identity ), $token ) ) mad4b_oauth_smoke_fail( 'Raw bearer token leaked into identity context.' );
$remote_read = MAD4B_SCP_Governed_Ability_Overrides::can_remote_oauth_read_ability( 'mad4b/filesystem-read' );
if ( ! is_wp_error( $remote_read ) || 'mad4b_remote_oauth_sensitive_read_denied' !== $remote_read->get_error_code() ) mad4b_oauth_smoke_fail( 'Verified OAuth bearer must not gain generic filesystem read by service-user capability.', $remote_read );

// The bridge must independently reauthorize subjects after cryptographic verification.
$unapproved = $base_claims; $unapproved['sub'] = 'user:user-2';
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $unapproved, $private_key, $kid ) ) );
if ( 403 !== mad4b_oauth_status_code( $response ) || 0 !== get_current_user_id() ) mad4b_oauth_smoke_fail( 'Unapproved subject must fail after signature verification even when the pre-gate is bypassed.', $response );

$missing_resource = $base_claims; unset( $missing_resource['resource'] );
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $missing_resource, $private_key, $kid ) ) );
if ( 401 !== mad4b_oauth_status_code( $response ) ) mad4b_oauth_smoke_fail( 'Missing resource claim must fail cryptographic bridge validation.', $response );

$weak_token = mad4b_oauth_jwt( $base_claims, $weak_key, $weak_kid );
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( $weak_token ) );
if ( 401 !== mad4b_oauth_status_code( $response ) ) mad4b_oauth_smoke_fail( 'RSA keys below 2048 bits must be rejected.', $response );

wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $base_claims, $private_key, $wrong_use_kid ) ) );
if ( 401 !== mad4b_oauth_status_code( $response ) ) mad4b_oauth_smoke_fail( 'JWK use=enc must be rejected.', $response );

wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $base_claims, $private_key, $wrong_ops_kid ) ) );
if ( 401 !== mad4b_oauth_status_code( $response ) ) mad4b_oauth_smoke_fail( 'JWK key_ops without verify must be rejected.', $response );

wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $base_claims, $private_key, $duplicate_kid ) ) );
if ( 401 !== mad4b_oauth_status_code( $response ) ) mad4b_oauth_smoke_fail( 'Duplicate kid entries must fail ambiguous instead of selecting one.', $response );

// Rotation: the new kid is absent from cached JWKS and appears only after the
// bridge performs its single forced refresh on kid miss.
$fetches_before_rotation = $jwks_fetches;
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $base_claims, $rotated_key, $rotated_kid ) ) );
if ( null !== $response || $jwks_fetches <= $fetches_before_rotation ) mad4b_oauth_smoke_fail( 'JWKS kid rotation must refresh once and accept the newly published key.', array( 'response' => $response, 'fetches' => $jwks_fetches ) );

// Unknown issuers must fail before outbound discovery/JWKS traffic.
$unknown = $base_claims; $unknown['iss'] = 'https://unknown.example.invalid/oauth';
$calls_before_unknown = $http_calls;
wp_set_current_user( 0 );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, mad4b_oauth_request( mad4b_oauth_jwt( $unknown, $private_key, $kid ) ) );
if ( 401 !== mad4b_oauth_status_code( $response ) || $calls_before_unknown !== $http_calls ) mad4b_oauth_smoke_fail( 'Unknown issuer must be denied without outbound discovery.', array( 'response' => $response, 'before' => $calls_before_unknown, 'after' => $http_calls ) );

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

$compat = MAD4B_SCP_MCP_Client_Compatibility::status();
if ( 'external' !== $compat['oauth_authority_mode'] || empty( $compat['authorization_server_external'] ) || ! empty( $compat['authorization_server_local'] ) || 1 !== (int) $compat['authorization_server_count'] ) mad4b_oauth_smoke_fail( 'Client compatibility metadata does not truthfully report external authority mode.', $compat );
if ( 'MAD4B WordPress Staging Read MCP' !== $compat['resource_name'] ) mad4b_oauth_smoke_fail( 'Staging resource name should be derived from environment.', $compat );

echo 'mad4b.site-control-plane.runtime-oauth-resource-bridge.v4: PASS' . PHP_EOL;
