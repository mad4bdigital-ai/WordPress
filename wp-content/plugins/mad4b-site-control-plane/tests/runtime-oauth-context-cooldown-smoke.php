<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function mad4b_oauth_context_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) mad4b_oauth_context_fail( 'OAuth resource bridge unavailable.' );
$status = MAD4B_SCP_OAuth_Resource_Bridge::status();
if ( 30 !== (int) $status['jwks_refresh_cooldown_seconds'] ) mad4b_oauth_context_fail( 'JWKS refresh cooldown truth drifted.', $status );
if ( empty( $status['jwks_cache_bound_to_issuer'] ) || empty( $status['bearer_request_resets_identity_before_verification'] ) ) mad4b_oauth_context_fail( 'OAuth context/cache hardening truth is incomplete.', $status );

$issuer = isset( $status['issuer'] ) ? (string) $status['issuer'] : '';
if ( '' === $issuer ) mad4b_oauth_context_fail( 'Fixture issuer unavailable.', $status );
$jwks_uri = preg_replace( '#/auth/mcp$#', '', $issuer ) . '/auth/mcp/.well-known/jwks.json';
$slot_key = 'mad4b_oauth_jwks_refresh_' . substr( hash( 'sha256', $issuer . "\0" . $jwks_uri ), 0, 32 );
delete_transient( $slot_key );

$claim_slot = new ReflectionMethod( 'MAD4B_SCP_OAuth_Resource_Bridge', 'claim_jwks_refresh_slot' );
$claim_slot->setAccessible( true );
if ( true !== $claim_slot->invoke( null, $issuer, $jwks_uri ) ) mad4b_oauth_context_fail( 'First unknown-kid refresh slot should be granted.' );
if ( false !== $claim_slot->invoke( null, $issuer, $jwks_uri ) ) mad4b_oauth_context_fail( 'Second unknown-kid refresh attempt must be suppressed during cooldown.' );
if ( false === get_transient( $slot_key ) ) mad4b_oauth_context_fail( 'JWKS refresh cooldown transient was not persisted.' );
delete_transient( $slot_key );
if ( true !== $claim_slot->invoke( null, $issuer, $jwks_uri ) ) mad4b_oauth_context_fail( 'Refresh slot should reopen after cooldown state is cleared.' );
delete_transient( $slot_key );

$context_property = new ReflectionProperty( 'MAD4B_SCP_OAuth_Resource_Bridge', 'verified_context' );
$context_property->setAccessible( true );
$fake_context = array(
	'authenticated' => true,
	'subject_type' => 'oauth',
	'auth_method' => 'oauth2_bearer',
	'wp_user_id' => 1,
);

// A bearer-shaped request must clear both stale OAuth context and a previously
// selected WordPress service user before parsing or verifying the new token.
$context_property->setValue( null, $fake_context );
wp_set_current_user( 1 );
$request = new WP_REST_Request( 'POST', '/mcp/mad4b-read' );
$request->set_header( 'Authorization', 'Bearer definitely-not-a-jwt' );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, $request );
if ( ! ( $response instanceof WP_REST_Response ) || 401 !== $response->get_status() ) mad4b_oauth_context_fail( 'Malformed bearer should fail 401.', $response );
if ( 0 !== get_current_user_id() ) mad4b_oauth_context_fail( 'Bearer verification failure retained stale WordPress service-user identity.', get_current_user_id() );
if ( MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) mad4b_oauth_context_fail( 'Bearer verification failure retained stale OAuth context.' );

// A local authenticated WordPress admin with no bearer remains a local session,
// but any stale OAuth overlay must still be removed.
$context_property->setValue( null, $fake_context );
wp_set_current_user( 1 );
$local_request = new WP_REST_Request( 'POST', '/mcp/mad4b-read' );
$result = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, $local_request );
if ( null !== $result ) mad4b_oauth_context_fail( 'Authenticated local admin should continue through the existing local permission path.', $result );
if ( 1 !== get_current_user_id() ) mad4b_oauth_context_fail( 'No-bearer local session should not be demoted.' );
if ( MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) mad4b_oauth_context_fail( 'No-bearer local session retained stale OAuth overlay.' );

wp_set_current_user( 0 );
echo 'mad4b.site-control-plane.runtime-oauth-context-cooldown.v1: PASS' . PHP_EOL;
