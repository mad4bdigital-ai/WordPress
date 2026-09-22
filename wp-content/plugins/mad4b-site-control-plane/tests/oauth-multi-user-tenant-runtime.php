<?php

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mad4b_test_current_user'] = 0;
$GLOBALS['mad4b_test_enrolled_users'] = array( 7, 8 );
$GLOBALS['mad4b_test_existing_users'] = array( 7 => true, 8 => true, 9 => true );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_set_current_user( $user_id ) { $GLOBALS['mad4b_test_current_user'] = absint( $user_id ); return $GLOBALS['mad4b_test_current_user']; }
function get_userdata( $user_id ) { $user_id = absint( $user_id ); return ! empty( $GLOBALS['mad4b_test_existing_users'][ $user_id ] ) ? (object) array( 'ID' => $user_id ) : false; }
function add_filter() {}

final class MAD4B_SCP_Site_Profile {
	public static function origin_enrolled() { return true; }
	public static function oauth_enabled() { return true; }
	public static function user_is_enrolled( $user_id ) { return in_array( absint( $user_id ), $GLOBALS['mad4b_test_enrolled_users'], true ); }
	public static function site_uuid() { return '11111111-2222-4333-8444-555555555555'; }
	public static function revision() { return 4; }
	public static function profile_digest() { return str_repeat( 'a', 64 ); }
}

final class MAD4B_SCP_Policy {
	public static function can_connect_user( $user_id ) { return MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) && false !== get_userdata( $user_id ); }
}

final class MAD4B_SCP_OAuth_Resource_Bridge {
	public static $verified = true;
	public static function verified_bearer_active() { return self::$verified; }
	public static function is_trusted_issuer( $issuer ) { return 'https://client.test/oauth/mcp' === $issuer; }
	public static function subject_allowed( $issuer, $subject ) {
		return self::is_trusted_issuer( $issuer ) && in_array( $subject, array( 'user:7', 'user:8', 'user:9', 'tenant:agency' ), true );
	}
}

final class MAD4B_Test_Request {
	private $authorization;
	public function __construct( $authorization ) { $this->authorization = $authorization; }
	public function get_route() { return '/mcp/mad4b-chatgpt'; }
	public function get_header( $name ) { return 'authorization' === strtolower( (string) $name ) ? $this->authorization : ''; }
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-oauth-subject-user-bridge.php';

function mad4b_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
function mad4b_b64url( $value ) { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
function mad4b_bearer( $subject ) {
	$header = mad4b_b64url( json_encode( array( 'alg' => 'RS256', 'kid' => 'test' ) ) );
	$payload = mad4b_b64url( json_encode( array( 'iss' => 'https://client.test/oauth/mcp', 'sub' => $subject ) ) );
	return 'Bearer ' . $header . '.' . $payload . '.c2ln';
}
function mad4b_map( $subject ) {
	$GLOBALS['mad4b_test_current_user'] = 1; // Compatibility/trust owner selected by the resource bridge first.
	return MAD4B_SCP_OAuth_Subject_User_Bridge::map_verified_subject( null, null, new MAD4B_Test_Request( mad4b_bearer( $subject ) ) );
}

// An already-verified user:7 bearer must act as user 7, not the compatibility owner.
$result = mad4b_map( 'user:7' );
mad4b_assert( null === $result, 'user:7 mapping preserves dispatch' );
mad4b_assert( 7 === $GLOBALS['mad4b_test_current_user'], 'user:7 maps to exact WordPress user 7' );
$context = MAD4B_SCP_OAuth_Subject_User_Bridge::mapped_context();
mad4b_assert( 7 === $context['wp_user_id'], 'identity context records exact user 7' );
mad4b_assert( hash( 'sha256', "oauth\0https://client.test/oauth/mcp\0user:7" ) === $context['subject_fingerprint'], 'identity fingerprint remains exact issuer+subject' );
mad4b_assert( 4 === $context['site_profile_revision'], 'identity context binds Site Profile revision' );

// A second enrolled delegated user must not collapse to the trust owner or user 7.
$result = mad4b_map( 'user:8' );
mad4b_assert( null === $result, 'user:8 mapping preserves dispatch' );
mad4b_assert( 8 === $GLOBALS['mad4b_test_current_user'], 'user:8 maps to exact WordPress user 8' );

// An issuer-allowed but non-enrolled user must fail closed.
$result = mad4b_map( 'user:9' );
mad4b_assert( is_wp_error( $result ) && 'mad4b_oauth_subject_user_not_enrolled' === $result->get_error_code(), 'non-enrolled user is denied after verification' );
mad4b_assert( 1 === $GLOBALS['mad4b_test_current_user'], 'denied subject never inherits another delegated user identity' );

// Non-user subjects are never silently promoted to the compatibility administrator.
$result = mad4b_map( 'tenant:agency' );
mad4b_assert( is_wp_error( $result ) && 'mad4b_oauth_subject_user_mapping_required' === $result->get_error_code(), 'tenant subject cannot become an admin implicitly' );
mad4b_assert( 1 === $GLOBALS['mad4b_test_current_user'], 'tenant subject leaves compatibility owner untouched and denied' );

// Removing an enrolled user immediately invalidates the subject at the mapping boundary.
$GLOBALS['mad4b_test_enrolled_users'] = array( 7 );
$result = mad4b_map( 'user:8' );
mad4b_assert( is_wp_error( $result ) && 'mad4b_oauth_subject_user_not_enrolled' === $result->get_error_code(), 'deprovisioned user is denied without token re-interpretation' );

// Remapping never runs unless the cryptographic resource bridge already verified the bearer.
MAD4B_SCP_OAuth_Resource_Bridge::$verified = false;
$GLOBALS['mad4b_test_current_user'] = 1;
$result = MAD4B_SCP_OAuth_Subject_User_Bridge::map_verified_subject( null, null, new MAD4B_Test_Request( mad4b_bearer( 'user:7' ) ) );
mad4b_assert( null === $result && 1 === $GLOBALS['mad4b_test_current_user'], 'unverified bearer is never trusted by the mapper' );

fwrite( STDOUT, "MAD4B tenant-bound multi-user OAuth mapping: PASS\n" );
