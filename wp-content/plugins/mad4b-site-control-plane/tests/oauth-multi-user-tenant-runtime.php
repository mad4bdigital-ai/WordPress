<?php

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mad4b_test_current_user'] = 0;
$GLOBALS['mad4b_test_enrolled_users'] = array( 7, 8 );
$GLOBALS['mad4b_test_existing_users'] = array( 7 => true, 8 => true, 9 => true );
$GLOBALS['mad4b_test_user_caps'] = array( 7 => array( 'edit_posts' ), 8 => array( 'edit_posts', 'manage_options' ) );

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
function current_user_can( $cap ) {
    return in_array( $cap, $GLOBALS['mad4b_test_user_caps'][ $GLOBALS['mad4b_test_current_user'] ] ?? array(), true );
}
function get_userdata( $user_id ) {
	if (isset($GLOBALS['mad4b_test_userdata_override'])) return $GLOBALS['mad4b_test_userdata_override'];
	$user_id = absint( $user_id ); return ! empty( $GLOBALS['mad4b_test_existing_users'][ $user_id ] ) ? (object) array( 'ID' => $user_id ) : false;
}
function add_filter() {}

final class MAD4B_SCP_Site_Profile {
	public static $origin = true, $oauth = true, $enrollment = true;
	public static function origin_enrolled() { return self::$origin; }
	public static function oauth_enabled() { return self::$oauth; }
	public static function user_is_enrolled( $user_id ) {
		return self::$enrollment === true ? in_array( absint( $user_id ), $GLOBALS['mad4b_test_enrolled_users'], true ) : self::$enrollment;
	}
	public static function site_uuid() { return '11111111-2222-4333-8444-555555555555'; }
	public static function revision() { return 4; }
	public static function profile_digest() { return str_repeat( 'a', 64 ); }
}

final class MAD4B_SCP_Local_OAuth_Server {
	public static function issuer() { return 'https://client.test/oauth/mcp'; }
}

final class MAD4B_SCP_Policy {
	public static $override = 'default';
	public static function can_connect_user( $user_id ) {
		return 'default' === self::$override
			? MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) && false !== get_userdata( $user_id )
			: self::$override;
	}
}

final class MAD4B_SCP_OAuth_Resource_Bridge {
	public static $verified = true, $issuer_override = 'default', $subject_override = 'default';
	public static function verified_bearer_active() { return self::$verified; }
	public static function is_trusted_issuer( $issuer ) {
		return self::$issuer_override === 'default'
			? ( 'https://client.test/oauth/mcp' === $issuer ) : self::$issuer_override;
	}
	public static function subject_allowed( $issuer, $subject ) {
		if ('default' !== self::$subject_override) return self::$subject_override;
		return self::is_trusted_issuer( $issuer ) && in_array( $subject, array( 'user:7', 'user:8', 'user:9', 'tenant:agency' ), true );
	}
}

final class MAD4B_Test_Request {
	private $authorization;
	private $route;
	public function __construct( $authorization, $route = '/mcp/mad4b-chatgpt' ) { $this->authorization = $authorization; $this->route = $route; }
	public function get_route() { return $this->route; }
	public function get_header( $name ) { return 'authorization' === strtolower( (string) $name ) ? $this->authorization : ''; }
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-oauth-subject-user-bridge.php';

function mad4b_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
function mad4b_b64url( $value ) { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
function mad4b_bearer( $subject, $site_uuid = null ) {
	$header = mad4b_b64url( json_encode( array( 'alg' => 'RS256', 'kid' => 'test' ) ) );
	if ( null === $site_uuid ) $site_uuid = MAD4B_SCP_Site_Profile::site_uuid();
	$payload = mad4b_b64url( json_encode( array( 'iss' => 'https://client.test/oauth/mcp', 'sub' => $subject, 'mad4b_site_uuid' => $site_uuid ) ) );
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
mad4b_assert( current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ),
    'delegated editor cannot inherit compatibility owner administrator capability' );
$context = MAD4B_SCP_OAuth_Subject_User_Bridge::mapped_context();
mad4b_assert( 7 === $context['wp_user_id'], 'identity context records exact user 7' );
mad4b_assert( hash( 'sha256', "oauth\0https://client.test/oauth/mcp\0user:7" ) === $context['subject_fingerprint'], 'identity fingerprint remains exact issuer+subject' );
mad4b_assert( 4 === $context['site_profile_revision'], 'identity context binds Site Profile revision' );

// A cryptographically verified local token from the previous Site UUID is denied.
$GLOBALS['mad4b_test_current_user'] = 1;
$result = MAD4B_SCP_OAuth_Subject_User_Bridge::map_verified_subject( null, null, new MAD4B_Test_Request( mad4b_bearer( 'user:7', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' ) ) );
mad4b_assert( is_wp_error( $result ) && 'mad4b_oauth_site_identity_mismatch' === $result->get_error_code(), 'old local Site UUID token survived identity rebind' );
mad4b_assert( 1 === $GLOBALS['mad4b_test_current_user'], 'mismatched local token changed WordPress user' );

// A second enrolled delegated user must not collapse to the trust owner or user 7.
$result = mad4b_map( 'user:8' );
mad4b_assert( null === $result, 'user:8 mapping preserves dispatch' );
mad4b_assert( 8 === $GLOBALS['mad4b_test_current_user'], 'user:8 maps to exact WordPress user 8' );
mad4b_assert( current_user_can( 'manage_options' ), 'delegated owner has actual user capability' );
$GLOBALS['mad4b_test_user_caps'][8] = array( 'edit_posts' );
mad4b_assert( ! current_user_can( 'manage_options' ), 'revoked WordPress capability is effective immediately' );

// All protected MCP resources map the verified OAuth subject, not a default administrator.
foreach ( array( '/mcp/mad4b-enrollment', '/mcp/mad4b-developer', '/mcp/mad4b-developer-breakglass' ) as $route ) {
    $GLOBALS['mad4b_test_current_user'] = 1;
    $result = MAD4B_SCP_OAuth_Subject_User_Bridge::map_verified_subject( null, null,
        new MAD4B_Test_Request( mad4b_bearer( 'user:7' ), $route ) );
    mad4b_assert( null === $result && 7 === $GLOBALS['mad4b_test_current_user'],
        'verified delegated user must bind identically on protected route ' . $route );
    mad4b_assert( ! current_user_can( 'manage_options' ), 'a delegated editor cannot acquire Developer administration' );
}

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

// Original verified bearer and enrollment are exact booleans, not PHP truthy
// WP_Error or a scalar. A failed check must not map to any WP account.
$GLOBALS['mad4b_test_enrolled_users'] = array(7,8);
MAD4B_SCP_OAuth_Resource_Bridge::$verified = true;
foreach(array(new WP_Error('bearer_unverified'),1,'true',null) as $value) {
 MAD4B_SCP_OAuth_Resource_Bridge::$verified=$value;
 $GLOBALS['mad4b_test_current_user']=1;
 $r=mad4b_map('user:7');
 mad4b_assert(1===$GLOBALS['mad4b_test_current_user'] && empty(MAD4B_SCP_OAuth_Subject_User_Bridge::mapped_context()),
  'unverified bearer cannot map current WP principal from a truthy nonboolean');
}
MAD4B_SCP_OAuth_Resource_Bridge::$verified=true;
foreach(array(new WP_Error('origin_unknown'),1,'true',null) as $value) {
 MAD4B_SCP_Site_Profile::$origin=$value;
 $r=mad4b_map('user:7');
 mad4b_assert(is_wp_error($r)&&1===$GLOBALS['mad4b_test_current_user'],
  'unverified enrolled Site Profile must deny verified bearer remapping');
}
MAD4B_SCP_Site_Profile::$origin=true;
foreach(array(new WP_Error('oauth_unknown'),1,'true',null) as $value) {
 MAD4B_SCP_Site_Profile::$oauth=$value;
 $r=mad4b_map('user:7');
 mad4b_assert(is_wp_error($r)&&1===$GLOBALS['mad4b_test_current_user'],
  'Site OAuth disabled/unverified must not authorize a user mapping');
}
MAD4B_SCP_Site_Profile::$oauth=true;
foreach(array(new WP_Error('issuer_unknown'),1,'true',null) as $value) {
 MAD4B_SCP_OAuth_Resource_Bridge::$issuer_override=$value;
 $r=mad4b_map('user:7');
 mad4b_assert(is_wp_error($r)&&1===$GLOBALS['mad4b_test_current_user'],
  'original OAuth issuer policy must be exactly true');
}
MAD4B_SCP_OAuth_Resource_Bridge::$issuer_override='default';
foreach(array(new WP_Error('subject_unknown'),1,'true',null) as $value) {
 MAD4B_SCP_OAuth_Resource_Bridge::$subject_override=$value;
 $r=mad4b_map('user:7');
 mad4b_assert(is_wp_error($r)&&1===$GLOBALS['mad4b_test_current_user'],
  'original OAuth subject policy must be exactly true');
}
MAD4B_SCP_OAuth_Resource_Bridge::$subject_override='default';
foreach(array(new WP_Error('enrollment_unknown'),1,'true',null) as $value) {
 MAD4B_SCP_Site_Profile::$enrollment=$value;
 $r=mad4b_map('user:7');
 mad4b_assert(is_wp_error($r)&&1===$GLOBALS['mad4b_test_current_user'],
  'original OAuth enrolled WP user must be exactly verified');
}
MAD4B_SCP_Site_Profile::$enrollment=true;
foreach(array(new WP_Error('connection_unknown'),1,'true',null) as $value) {
 MAD4B_SCP_Policy::$override=$value;
 $r=mad4b_map('user:7');
 mad4b_assert(is_wp_error($r)&&1===$GLOBALS['mad4b_test_current_user'],
  'original WP account connection capability must be exactly true');
}
MAD4B_SCP_Policy::$override='default';
foreach(array(new WP_Error('wp_user_missing'),(object)array('ID'=>8)) as $value) {
 $GLOBALS['mad4b_test_userdata_override']=$value;
 $r=mad4b_map('user:7');
 mad4b_assert(is_wp_error($r)&&1===$GLOBALS['mad4b_test_current_user'],
  'WP_Error/mismatched user object may not be mapped as verified subject');
}
unset($GLOBALS['mad4b_test_userdata_override']);
$r=mad4b_map('user:7');
mad4b_assert(null===$r && 7===$GLOBALS['mad4b_test_current_user'],
 'original verified multiuser WordPress OAuth mapping remains available');
fwrite( STDOUT, "MAD4B tenant-bound multi-user OAuth mapping: PASS\n" );
