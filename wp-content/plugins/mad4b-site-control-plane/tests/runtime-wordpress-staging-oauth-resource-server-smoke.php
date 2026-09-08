<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

function mad4b_oauth_smoke_fail( $message ) {
	fwrite( STDERR, "FAIL wordpress-staging-oauth-resource-server: {$message}\n" );
	exit( 1 );
}
function mad4b_oauth_smoke_assert( $condition, $message ) {
	if ( ! $condition ) mad4b_oauth_smoke_fail( $message );
}
function mad4b_oauth_b64u( $value ) {
	return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
}
function mad4b_oauth_sign( array $claims, $private_key, $kid, array $extra_header = array() ) {
	$header = array_merge( array( 'alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid ), $extra_header );
	$h = mad4b_oauth_b64u( wp_json_encode( $header, JSON_UNESCAPED_SLASHES ) );
	$p = mad4b_oauth_b64u( wp_json_encode( $claims, JSON_UNESCAPED_SLASHES ) );
	$signature = '';
	mad4b_oauth_smoke_assert( openssl_sign( $h . '.' . $p, $signature, $private_key, OPENSSL_ALGO_SHA256 ), 'openssl_sign failed' );
	return $h . '.' . $p . '.' . mad4b_oauth_b64u( $signature );
}

// WP-CLI has no inbound HTTPS transport context. Emulate the exact external
// Staging request so home_url() preserves the configured HTTPS origin while
// the bridge still fails closed for real non-HTTPS requests.
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';

mad4b_oauth_smoke_assert( MAD4B_SCP_Staging_OAuth_Bridge::enabled(), 'bridge must be enabled on disposable staging target' );
$metadata = MAD4B_SCP_Staging_OAuth_Bridge::protected_resource_metadata();
mad4b_oauth_smoke_assert( MAD4B_SCP_Staging_OAuth_Bridge::RESOURCE === $metadata['resource'], 'resource metadata mismatch' );
mad4b_oauth_smoke_assert( array( MAD4B_SCP_Staging_OAuth_Bridge::ISSUER ) === $metadata['authorization_servers'], 'authorization server metadata mismatch' );
mad4b_oauth_smoke_assert( false !== strpos( MAD4B_SCP_Staging_OAuth_Bridge::challenge_header(), 'resource_metadata="' . MAD4B_SCP_Staging_OAuth_Bridge::METADATA_URL . '"' ), 'challenge metadata URL missing' );

$key = openssl_pkey_new( array( 'private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
mad4b_oauth_smoke_assert( false !== $key, 'RSA key generation failed' );
$details = openssl_pkey_get_details( $key );
mad4b_oauth_smoke_assert( is_array( $details ) && isset( $details['rsa']['n'], $details['rsa']['e'] ), 'RSA key details missing' );
$kid = 'ci_kid_1234567890';
$jwks = array( 'keys' => array( array(
	'kty' => 'RSA',
	'kid' => $kid,
	'n' => mad4b_oauth_b64u( $details['rsa']['n'] ),
	'e' => mad4b_oauth_b64u( $details['rsa']['e'] ),
	'alg' => 'RS256',
	'use' => 'sig',
) ) );
add_filter( 'mad4b_scp_wordpress_staging_oauth_jwks_document', static function () use ( $jwks ) { return $jwks; } );

$subject = 'tenant:ci-tenant:user:ci-user';
$fingerprint = MAD4B_SCP_Staging_OAuth_Bridge::subject_fingerprint( $subject );
$user_id = wp_create_user( 'mad4b-oauth-ci', wp_generate_password( 32, true, true ), 'mad4b-oauth-ci@example.invalid' );
if ( is_wp_error( $user_id ) ) {
	$existing = get_user_by( 'login', 'mad4b-oauth-ci' );
	$user_id = $existing ? (int) $existing->ID : 0;
}
mad4b_oauth_smoke_assert( $user_id > 0, 'service user unavailable' );
$user = new WP_User( $user_id );
$user->set_role( 'administrator' );
update_user_meta( $user_id, MAD4B_SCP_Staging_OAuth_Bridge::USER_META_KEY, $fingerprint );

$now = time();
$claims = array(
	'iss' => MAD4B_SCP_Staging_OAuth_Bridge::ISSUER,
	'aud' => MAD4B_SCP_Staging_OAuth_Bridge::RESOURCE,
	'resource' => MAD4B_SCP_Staging_OAuth_Bridge::RESOURCE,
	'purpose' => MAD4B_SCP_Staging_OAuth_Bridge::TOKEN_PURPOSE,
	'scope' => 'mad4b:read offline_access',
	'client_id' => 'mcp_stg_wp_abcdefghijklmnop',
	'azp' => 'mcp_stg_wp_abcdefghijklmnop',
	'client_profile_key' => 'wordpress_staging_mcp:generic_remote_mcp_client',
	'sub' => $subject,
	'tenant_id' => 'ci-tenant',
	'user_id' => 'ci-user',
	'iat' => $now,
	'exp' => $now + 900,
	'jti' => 'ci-jti-1',
);
$token = mad4b_oauth_sign( $claims, $key, $kid );
$verified = MAD4B_SCP_Staging_OAuth_Bridge::verify_access_token( $token, $jwks, $now );
mad4b_oauth_smoke_assert( ! is_wp_error( $verified ), 'valid RS256 token rejected' );
mad4b_oauth_smoke_assert( $user_id === MAD4B_SCP_Staging_OAuth_Bridge::resolve_bound_user_id( $verified ), 'subject-to-user binding mismatch' );

$_SERVER['REQUEST_URI'] = MAD4B_SCP_Staging_OAuth_Bridge::RESOURCE_PATH;
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$resolved_user = MAD4B_SCP_Staging_OAuth_Bridge::authenticate_bearer_user( 0 );
mad4b_oauth_smoke_assert( $user_id === $resolved_user, 'bearer request did not resolve bound user' );
wp_set_current_user( $resolved_user );
mad4b_oauth_smoke_assert( true === MAD4B_SCP_Staging_OAuth_Bridge::rest_authentication_errors( null ), 'REST authentication bridge not asserted' );
$context = MAD4B_SCP_Staging_OAuth_Bridge::inject_subject_context( array() );
mad4b_oauth_smoke_assert( ! empty( $context['authenticated'] ), 'subject context not authenticated' );
mad4b_oauth_smoke_assert( $fingerprint === $context['subject_fingerprint'], 'subject fingerprint mismatch' );
mad4b_oauth_smoke_assert( 'oauth_rs256_jwks' === $context['auth_method'], 'auth method mismatch' );
mad4b_oauth_smoke_assert( in_array( 'mad4b:read', $context['token_scopes'], true ), 'read scope missing from subject context' );

$wrong = $claims;
$wrong['aud'] = 'https://example.invalid/wrong-resource';
$rejected = MAD4B_SCP_Staging_OAuth_Bridge::verify_access_token( mad4b_oauth_sign( $wrong, $key, $kid ), $jwks, $now );
mad4b_oauth_smoke_assert( is_wp_error( $rejected ) && 'mad4b_oauth_audience_invalid' === $rejected->get_error_code(), 'wrong audience was not denied' );

$wrong = $claims;
$wrong['scope'] = 'mad4b:read mad4b:write';
$rejected = MAD4B_SCP_Staging_OAuth_Bridge::verify_access_token( mad4b_oauth_sign( $wrong, $key, $kid ), $jwks, $now );
mad4b_oauth_smoke_assert( is_wp_error( $rejected ) && 'mad4b_oauth_scope_invalid' === $rejected->get_error_code(), 'write scope was not denied' );

$rejected = MAD4B_SCP_Staging_OAuth_Bridge::verify_access_token( mad4b_oauth_sign( $claims, $key, $kid, array( 'jku' => 'https://example.invalid/jwks' ) ), $jwks, $now );
mad4b_oauth_smoke_assert( is_wp_error( $rejected ) && 'mad4b_oauth_token_header_denied' === $rejected->get_error_code(), 'caller-controlled JWK URL was not denied' );

$unbound = $claims;
$unbound['sub'] = 'user:another-user';
$unbound['tenant_id'] = null;
$unbound['user_id'] = 'another-user';
$verified_unbound = MAD4B_SCP_Staging_OAuth_Bridge::verify_access_token( mad4b_oauth_sign( $unbound, $key, $kid ), $jwks, $now );
mad4b_oauth_smoke_assert( ! is_wp_error( $verified_unbound ), 'cryptographically valid unbound token rejected too early' );
$binding = MAD4B_SCP_Staging_OAuth_Bridge::resolve_bound_user_id( $verified_unbound );
mad4b_oauth_smoke_assert( is_wp_error( $binding ) && 'mad4b_oauth_subject_unbound' === $binding->get_error_code(), 'unbound subject did not fail closed' );

delete_user_meta( $user_id, MAD4B_SCP_Staging_OAuth_Bridge::USER_META_KEY );
echo "mad4b.wordpress-staging-mcp-resource-server-runtime.v1: PASS\n";
