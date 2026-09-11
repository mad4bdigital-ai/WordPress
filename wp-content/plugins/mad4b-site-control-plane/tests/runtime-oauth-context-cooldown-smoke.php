<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function mad4b_oauth_context_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) mad4b_oauth_context_fail( 'OAuth resource bridge unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_OAuth_Request_Context_Guard' ) ) mad4b_oauth_context_fail( 'OAuth request context guard unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_OAuth_Subject_Gate' ) ) mad4b_oauth_context_fail( 'OAuth subject gate unavailable.' );

$guard_priority = has_filter( 'rest_pre_dispatch', array( 'MAD4B_SCP_OAuth_Request_Context_Guard', 'reset_request_context' ) );
$subject_priority = has_filter( 'rest_pre_dispatch', array( 'MAD4B_SCP_OAuth_Subject_Gate', 'enforce' ) );
$bridge_priority = has_filter( 'rest_pre_dispatch', array( 'MAD4B_SCP_OAuth_Resource_Bridge', 'authenticate_rest_request' ) );
if ( -1 !== $guard_priority || 0 !== $subject_priority || 1 !== $bridge_priority ) mad4b_oauth_context_fail( 'OAuth request ordering must be context reset -> deny-only subject gate -> cryptographic bridge.', array( 'guard' => $guard_priority, 'subject' => $subject_priority, 'bridge' => $bridge_priority ) );

$status = MAD4B_SCP_OAuth_Resource_Bridge::status();
if ( 30 !== (int) $status['jwks_refresh_cooldown_seconds'] ) mad4b_oauth_context_fail( 'JWKS refresh cooldown truth drifted.', $status );
if ( 'mad4b-chatgpt' !== $status['protected_transport_server'] ) mad4b_oauth_context_fail( 'OAuth request context is not bound to the ChatGPT gateway.', $status );
if ( empty( $status['jwks_cache_bound_to_issuer'] ) || empty( $status['bearer_request_resets_identity_before_verification'] ) ) mad4b_oauth_context_fail( 'OAuth context/cache hardening truth is incomplete.', $status );
$guard_status = MAD4B_SCP_OAuth_Request_Context_Guard::status();
if ( empty( $guard_status['clears_stale_oauth_service_user'] ) || empty( $guard_status['preserves_clean_local_admin_session'] ) || ! empty( $guard_status['creates_authority'] ) ) mad4b_oauth_context_fail( 'OAuth request context guard truth is invalid.', $guard_status );

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

// The priority -1 guard must clear stale authority before Subject Gate can
// return a denial that prevents the bridge from running at priority 1.
$context_property->setValue( null, $fake_context );
wp_set_current_user( 1 );
$denied_request = new WP_REST_Request( 'POST', '/mcp/mad4b-chatgpt' );
$denied_request->set_header( 'Authorization', 'Bearer definitely-not-a-jwt' );
$preexisting_result = new WP_REST_Response( array( 'error' => 'earlier_filter' ), 401 );
$guard_result = MAD4B_SCP_OAuth_Request_Context_Guard::reset_request_context( $preexisting_result, null, $denied_request );
if ( $preexisting_result !== $guard_result ) mad4b_oauth_context_fail( 'Context guard must preserve prior filter result while clearing authority.' );
if ( 0 !== get_current_user_id() || MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) mad4b_oauth_context_fail( 'Pre-gate denial path retained stale OAuth service identity.' );

// A malformed bearer also starts anonymous before bridge parsing/verification.
$context_property->setValue( null, $fake_context );
wp_set_current_user( 1 );
$request = new WP_REST_Request( 'POST', '/mcp/mad4b-chatgpt' );
$request->set_header( 'Authorization', 'Bearer definitely-not-a-jwt' );
MAD4B_SCP_OAuth_Request_Context_Guard::reset_request_context( null, null, $request );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, $request );
if ( ! ( $response instanceof WP_REST_Response ) || 401 !== $response->get_status() ) mad4b_oauth_context_fail( 'Malformed bearer should fail 401.', $response );
if ( 0 !== get_current_user_id() ) mad4b_oauth_context_fail( 'Bearer verification failure retained stale WordPress service-user identity.', get_current_user_id() );
if ( MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) mad4b_oauth_context_fail( 'Bearer verification failure retained stale OAuth context.' );

// A no-bearer subrequest after OAuth must not inherit the previous service user.
$context_property->setValue( null, $fake_context );
wp_set_current_user( 1 );
$stale_local_request = new WP_REST_Request( 'POST', '/mcp/mad4b-chatgpt' );
MAD4B_SCP_OAuth_Request_Context_Guard::reset_request_context( null, null, $stale_local_request );
if ( 0 !== get_current_user_id() || MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) mad4b_oauth_context_fail( 'No-bearer subrequest inherited stale OAuth service identity.' );
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, $stale_local_request );
if ( ! ( $response instanceof WP_REST_Response ) || 401 !== $response->get_status() ) mad4b_oauth_context_fail( 'Demoted stale no-bearer subrequest must require authentication.', $response );

// A genuine clean local WordPress admin session remains supported on the
// gateway transport without gaining an OAuth overlay.
$context_property->setValue( null, null );
wp_set_current_user( 1 );
$clean_local_request = new WP_REST_Request( 'POST', '/mcp/mad4b-chatgpt' );
MAD4B_SCP_OAuth_Request_Context_Guard::reset_request_context( null, null, $clean_local_request );
if ( 1 !== get_current_user_id() ) mad4b_oauth_context_fail( 'Clean no-bearer local admin session was incorrectly demoted.' );
$result = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, $clean_local_request );
if ( null !== $result ) mad4b_oauth_context_fail( 'Authenticated clean local admin should continue through the gateway permission path.', $result );
if ( MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) mad4b_oauth_context_fail( 'Clean local session unexpectedly gained OAuth overlay.' );

wp_set_current_user( 0 );
echo 'mad4b.site-control-plane.runtime-oauth-context-cooldown.v3: PASS' . PHP_EOL;
