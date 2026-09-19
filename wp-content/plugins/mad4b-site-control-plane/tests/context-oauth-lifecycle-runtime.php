<?php

define( 'ABSPATH', '/tmp/mad4b-context-oauth-lifecycle/' );
define( 'MAD4B_GOOGLE_DRIVE_CLIENT_ID', 'client-id.apps.googleusercontent.com' );
define( 'MAD4B_GOOGLE_DRIVE_CLIENT_SECRET', 'client-secret-fixture' );
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['mad4b_context_options'] = array();
$GLOBALS['mad4b_context_transients'] = array();
$GLOBALS['mad4b_context_token_responses'] = array();
$GLOBALS['mad4b_context_revoke_status'] = 200;

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can() { return true; }
function get_current_user_id() { return 7; }
function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_email( $value ) { return (string) $value; }
function esc_url_raw( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
function admin_url( $path = '' ) { return 'https://staging.example.test/wp-admin/' . ltrim( $path, '/' ); }
function home_url( $path = '' ) { return 'https://staging.example.test/' . ltrim( $path, '/' ); }
function wp_salt() { return 'mad4b-context-oauth-runtime-salt'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_generate_password() { return 'context-oauth-state'; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ); }
function set_transient( $name, $value ) { $GLOBALS['mad4b_context_transients'][ $name ] = $value; return true; }
function get_transient( $name ) { return isset( $GLOBALS['mad4b_context_transients'][ $name ] ) ? $GLOBALS['mad4b_context_transients'][ $name ] : false; }
function delete_transient( $name ) { unset( $GLOBALS['mad4b_context_transients'][ $name ] ); return true; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ? $GLOBALS['mad4b_context_options'][ $name ] : $default; }
function add_option( $name, $value ) {
	if ( array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ) return false;
	$GLOBALS['mad4b_context_options'][ $name ] = $value;
	return true;
}
function update_option( $name, $value ) {
	$changed = ! array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) || $GLOBALS['mad4b_context_options'][ $name ] !== $value;
	$GLOBALS['mad4b_context_options'][ $name ] = $value;
	return $changed;
}
function delete_option( $name ) {
	if ( ! array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ) return false;
	unset( $GLOBALS['mad4b_context_options'][ $name ] );
	return true;
}
function wp_remote_retrieve_response_code( $response ) { return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0; }
function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? (string) $response['body'] : ''; }
function wp_remote_post( $url, $args = array() ) {
	if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
		if ( empty( $GLOBALS['mad4b_context_token_responses'] ) ) return new WP_Error( 'token_fixture_exhausted', 'No OAuth token fixture remains.' );
		$data = array_shift( $GLOBALS['mad4b_context_token_responses'] );
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $data ) );
	}
	if ( false !== strpos( $url, 'oauth2.googleapis.com/revoke' ) ) {
		return array( 'response' => array( 'code' => (int) $GLOBALS['mad4b_context_revoke_status'] ), 'body' => '' );
	}
	return new WP_Error( 'unexpected_post', 'Unexpected HTTP POST target.' );
}
function wp_remote_get( $url, $args = array() ) {
	if ( false !== strpos( $url, '/about?' ) ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode(
				array(
					'user' => array(
						'displayName' => 'Context Operator',
						'emailAddress' => 'operator@example.test',
						'permissionId' => 'permission-fixture',
					),
				)
			),
		);
	}
	return new WP_Error( 'unexpected_get', 'Unexpected HTTP GET target.' );
}
function seems_utf8( $value ) { return true; }
function wp_check_invalid_utf8( $value ) { return (string) $value; }

class MAD4B_SCP_Site_Profile {
	public static function origin_enrolled() { return true; }
	public static function site_uuid() { return '11111111-1111-4111-8111-111111111111'; }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-google-drive-context.php';

function mad4b_oauth_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$GLOBALS['mad4b_context_token_responses'][] = array(
	'access_token' => 'access-read-fixture',
	'refresh_token' => 'refresh-read-fixture',
	'expires_in' => 3600,
	'scope' => MAD4B_SCP_Google_Drive_Context::READ_SCOPE,
);
$url = MAD4B_SCP_Google_Drive_Context::authorization_url( 'read_only' );
mad4b_oauth_assert( ! is_wp_error( $url ), 'Read-only OAuth authorization URL must be created.', $url );
$read = MAD4B_SCP_Google_Drive_Context::complete_oauth( 'code-read', 'context-oauth-state' );
mad4b_oauth_assert( ! is_wp_error( $read ), 'Read-only OAuth exchange must succeed.', $read );
mad4b_oauth_assert( ! empty( $read['connected'] ) && ! empty( $read['read_available'] ), 'Read-only connection must expose read capability.', $read );
mad4b_oauth_assert( empty( $read['write_available'] ) && 'read_only' === $read['access_mode'], 'Read-only connection must not expose Drive writes.', $read );

$GLOBALS['mad4b_context_token_responses'][] = array(
	'access_token' => 'access-write-fixture',
	'refresh_token' => 'refresh-write-fixture',
	'expires_in' => 3600,
	'scope' => MAD4B_SCP_Google_Drive_Context::WRITE_SCOPE,
);
$url = MAD4B_SCP_Google_Drive_Context::authorization_url( 'read_write' );
mad4b_oauth_assert( ! is_wp_error( $url ), 'Read+Write OAuth authorization URL must be created.', $url );
$write = MAD4B_SCP_Google_Drive_Context::complete_oauth( 'code-write', 'context-oauth-state' );
mad4b_oauth_assert( ! is_wp_error( $write ), 'Explicit Read+Write OAuth exchange must succeed.', $write );
mad4b_oauth_assert( ! empty( $write['write_available'] ) && 'read_write' === $write['access_mode'], 'Read+Write connection must expose provider write capability.', $write );
mad4b_oauth_assert( MAD4B_SCP_Google_Drive_Context::WRITE_SCOPE === $write['scope'], 'Read+Write connection must retain exact governed full Drive scope only.', $write );

$public_write = MAD4B_SCP_Google_Drive_Context::public_connection_status();
mad4b_oauth_assert( 'mad4b.google-drive-public-connection.v1' === $public_write['contract'], 'Public Drive status contract mismatch.', $public_write );
mad4b_oauth_assert( ! empty( $public_write['write_available'] ) && 'read_write' === $public_write['access_mode'], 'Public Drive status must retain capability truth.', $public_write );
foreach ( array( 'scope', 'account_email', 'account_name', 'permission_id', 'access_token', 'refresh_token' ) as $sensitive_key ) {
	mad4b_oauth_assert( ! array_key_exists( $sensitive_key, $public_write ), 'Public Drive status leaked account/token identity field: ' . $sensitive_key, $public_write );
}

$GLOBALS['mad4b_context_token_responses'][] = array(
	'access_token' => 'access-downgrade-fixture',
	'expires_in' => 3600,
	'scope' => MAD4B_SCP_Google_Drive_Context::READ_SCOPE,
);
$url = MAD4B_SCP_Google_Drive_Context::authorization_url( 'read_only' );
mad4b_oauth_assert( ! is_wp_error( $url ), 'Read-only reconnect attempt may start before downgrade proof is evaluated.', $url );
$downgrade = MAD4B_SCP_Google_Drive_Context::complete_oauth( 'code-downgrade', 'context-oauth-state' );
mad4b_oauth_assert( is_wp_error( $downgrade ), 'Silent downgrade must fail when the prior broad refresh grant would be reused.', $downgrade );
mad4b_oauth_assert( 'mad4b_google_drive_readonly_downgrade_requires_revoke' === $downgrade->get_error_code(), 'Silent broad-refresh downgrade must have a stable blocker.', $downgrade->get_error_code() );

$still_write = MAD4B_SCP_Google_Drive_Context::connection_status();
mad4b_oauth_assert( ! empty( $still_write['write_available'] ) && 'read_write' === $still_write['access_mode'], 'Failed downgrade must leave the prior governed connection unchanged until revoke.', $still_write );

$disconnected = MAD4B_SCP_Google_Drive_Context::disconnect();
mad4b_oauth_assert( ! is_wp_error( $disconnected ), 'Confirmed Google revoke must disconnect cleanly.', $disconnected );
mad4b_oauth_assert( empty( $disconnected['connected'] ), 'Confirmed revoke must remove the local encrypted token.', $disconnected );
mad4b_oauth_assert( ! empty( $disconnected['remote_revocation_attempted'] ) && ! empty( $disconnected['remote_revocation_confirmed'] ), 'Disconnect must expose confirmed remote revocation evidence.', $disconnected );
mad4b_oauth_assert( false === get_option( MAD4B_SCP_Google_Drive_Context::TOKEN_OPTION, false ), 'Confirmed revoke must leave no local token option.' );

$encoded = json_encode( $disconnected );
foreach ( array( 'access-read-fixture', 'refresh-read-fixture', 'access-write-fixture', 'refresh-write-fixture' ) as $secret ) {
	mad4b_oauth_assert( false === strpos( $encoded, $secret ), 'OAuth status must never expose token material.' );
}

echo "mad4b.site-control-plane.context-oauth-lifecycle.runtime.v2: PASS\n";
