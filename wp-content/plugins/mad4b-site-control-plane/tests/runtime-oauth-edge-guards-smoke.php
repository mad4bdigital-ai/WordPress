<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function mad4b_edge_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}
function mad4b_edge_b64url( $value ) { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
function mad4b_edge_token( array $header ) {
	$claims = array( 'iss' => 'https://example.invalid', 'sub' => 'user:test' );
	return mad4b_edge_b64url( wp_json_encode( $header ) ) . '.' . mad4b_edge_b64url( wp_json_encode( $claims ) ) . '.' . mad4b_edge_b64url( 'signature' );
}
function mad4b_edge_request( $token ) {
	$request = new WP_REST_Request( 'POST', '/mcp/mad4b-read' );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	return $request;
}

foreach ( array( 'MAD4B_SCP_OAuth_JWT_Header_Guard', 'MAD4B_SCP_OAuth_Outbound_Budget_Guard' ) as $class ) if ( ! class_exists( $class ) ) mad4b_edge_fail( 'OAuth edge guard unavailable.', $class );

$jwt_status = MAD4B_SCP_OAuth_JWT_Header_Guard::status();
if ( 'at+jwt' !== $jwt_status['expected_typ'] || 'RS256' !== $jwt_status['expected_alg'] || ! empty( $jwt_status['creates_authority'] ) ) mad4b_edge_fail( 'JWT header guard truth is invalid.', $jwt_status );

$missing_typ = MAD4B_SCP_OAuth_JWT_Header_Guard::enforce( null, null, mad4b_edge_request( mad4b_edge_token( array( 'alg' => 'RS256' ) ) ) );
if ( ! ( $missing_typ instanceof WP_REST_Response ) || 401 !== $missing_typ->get_status() ) mad4b_edge_fail( 'JWT without at+jwt typ must be rejected.', $missing_typ );

$wrong_typ = MAD4B_SCP_OAuth_JWT_Header_Guard::enforce( null, null, mad4b_edge_request( mad4b_edge_token( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ) );
if ( ! ( $wrong_typ instanceof WP_REST_Response ) || 401 !== $wrong_typ->get_status() ) mad4b_edge_fail( 'Generic JWT typ must not be accepted as an access-token JWT.', $wrong_typ );

$wrong_alg = MAD4B_SCP_OAuth_JWT_Header_Guard::enforce( null, null, mad4b_edge_request( mad4b_edge_token( array( 'alg' => 'HS256', 'typ' => 'at+jwt' ) ) ) );
if ( ! ( $wrong_alg instanceof WP_REST_Response ) || 401 !== $wrong_alg->get_status() ) mad4b_edge_fail( 'Non-RS256 JWT must be rejected by the protected-header gate.', $wrong_alg );

$valid_header = MAD4B_SCP_OAuth_JWT_Header_Guard::enforce( null, null, mad4b_edge_request( mad4b_edge_token( array( 'alg' => 'RS256', 'typ' => 'at+jwt' ) ) ) );
if ( null !== $valid_header ) mad4b_edge_fail( 'Expected access-token header should pass deny-only header policy.', $valid_header );

$budget_status = MAD4B_SCP_OAuth_Outbound_Budget_Guard::status();
if ( 30 !== (int) $budget_status['window_seconds'] || 2 !== (int) $budget_status['max_requests_per_url'] || empty( $budget_status['cross_process_atomic_slots'] ) || ! empty( $budget_status['creates_authority'] ) ) mad4b_edge_fail( 'Outbound OAuth budget truth is invalid.', $budget_status );

$url = 'https://budget.example.invalid/.well-known/jwks.json?case=' . rawurlencode( wp_generate_uuid4() );
$now = time();
if ( true !== MAD4B_SCP_OAuth_Outbound_Budget_Guard::claim_url_budget( $url, $now ) ) mad4b_edge_fail( 'First outbound budget slot was not acquired.' );
if ( true !== MAD4B_SCP_OAuth_Outbound_Budget_Guard::claim_url_budget( $url, $now ) ) mad4b_edge_fail( 'Second outbound budget slot was not acquired.' );
if ( false !== MAD4B_SCP_OAuth_Outbound_Budget_Guard::claim_url_budget( $url, $now ) ) mad4b_edge_fail( 'Third concurrent outbound request exceeded the atomic URL budget.' );
if ( true !== MAD4B_SCP_OAuth_Outbound_Budget_Guard::claim_url_budget( $url, $now + 31 ) ) mad4b_edge_fail( 'Outbound URL budget did not reopen after its window.' );

$prefix = 'mad4b_oauth_http_budget_' . substr( hash( 'sha256', esc_url_raw( $url ) ), 0, 32 ) . '_';
for ( $slot = 1; $slot <= 2; ++$slot ) delete_option( $prefix . $slot );

echo 'mad4b.site-control-plane.runtime-oauth-edge-guards.v1: PASS' . PHP_EOL;
