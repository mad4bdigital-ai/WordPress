<?php

define( 'ABSPATH', '/tmp/mad4b-context-runtime/' );

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
function admin_url( $path = '' ) { return 'https://staging.example.test/wp-admin/' . ltrim( $path, '/' ); }
function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_email( $value ) { return (string) $value; }
function esc_url_raw( $value ) { return (string) $value; }
function add_query_arg( $args, $url ) { return $url; }
function current_user_can() { return true; }
function wp_generate_password() { return 'context-runtime-state'; }
function get_current_user_id() { return 1; }
function set_transient() { return true; }
function get_transient() { return false; }
function delete_transient() { return true; }
function get_option( $name, $default = false ) { return $default; }
function add_option() { return true; }
function update_option() { return true; }
function delete_option() { return true; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function home_url() { return 'https://staging.example.test/'; }
function wp_salt() { return 'context-runtime-test-salt'; }
function wp_check_invalid_utf8( $value ) { return (string) $value; }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-google-drive-context.php';

function mad4b_context_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$ref = new ReflectionClass( 'MAD4B_SCP_Google_Drive_Context' );
$allowed = $ref->getMethod( 'scope_is_allowed' );
$allowed->setAccessible( true );
$write = $ref->getMethod( 'scope_allows_write' );
$write->setAccessible( true );
$read = $ref->getMethod( 'scope_allows_read' );
$read->setAccessible( true );
$matches = $ref->getMethod( 'scope_matches_requested_mode' );
$matches->setAccessible( true );
$verify_parent = $ref->getMethod( 'verify_created_file_parent' );
$verify_parent->setAccessible( true );
$connection_blockers = $ref->getMethod( 'connection_blockers' );
$connection_blockers->setAccessible( true );

$ro = MAD4B_SCP_Google_Drive_Context::READ_SCOPE;
$rw = MAD4B_SCP_Google_Drive_Context::WRITE_SCOPE;
mad4b_context_assert( true === $allowed->invoke( null, $ro ), 'read-only Drive scope must be allowed' );
mad4b_context_assert( true === $read->invoke( null, $ro ), 'read-only Drive scope must provide read capability' );
mad4b_context_assert( false === $write->invoke( null, $ro ), 'read-only Drive scope must not provide write capability' );

mad4b_context_assert( true === $allowed->invoke( null, $rw ), 'full Drive scope must be allowed only as explicit read+write mode' );
mad4b_context_assert( true === $read->invoke( null, $rw ), 'full Drive scope must provide read capability' );
mad4b_context_assert( true === $write->invoke( null, $rw ), 'full Drive scope must provide write capability' );

mad4b_context_assert( true === $allowed->invoke( null, $ro . ' ' . $rw ), 'the generic allowlist may parse both governed Drive scopes' );
mad4b_context_assert( true === $matches->invoke( null, $ro, 'read_only' ), 'read-only mode must accept exactly drive.readonly' );
mad4b_context_assert( false === $matches->invoke( null, $rw, 'read_only' ), 'read-only mode must reject Drive write scope' );
mad4b_context_assert( true === $matches->invoke( null, $rw, 'read_write' ), 'read-write mode must accept exactly full Drive scope' );
mad4b_context_assert( false === $matches->invoke( null, $ro, 'read_write' ), 'read-write mode must reject read-only scope' );
mad4b_context_assert( false === $matches->invoke( null, $ro . ' ' . $rw, 'read_only' ), 'read-only mode must reject accumulated Drive scopes' );
mad4b_context_assert( false === $matches->invoke( null, $ro . ' ' . $rw, 'read_write' ), 'read-write mode must reject accumulated Drive scopes' );
mad4b_context_assert( false === $allowed->invoke( null, $rw . ' https://www.googleapis.com/auth/contacts.readonly' ), 'unsupported Google scopes must fail closed' );
mad4b_context_assert( false === $allowed->invoke( null, 'https://www.googleapis.com/auth/drive.file' ), 'drive.file is not accepted by the existing-asset repair contract' );

mad4b_context_assert(
	true === $verify_parent->invoke( null, array( 'id' => 'created-file', 'parents' => array( 'selected-folder' ) ), 'selected-folder' ),
	'provider-confirmed create parent must match the exact selected folder'
);
$wrong_parent = $verify_parent->invoke( null, array( 'id' => 'created-file', 'parents' => array( 'other-folder' ) ), 'selected-folder' );
mad4b_context_assert( is_wp_error( $wrong_parent ) && 'mad4b_google_drive_created_parent_mismatch' === $wrong_parent->get_error_code(), 'provider parent mismatch must fail closed' );
$multiple_parents = $verify_parent->invoke( null, array( 'id' => 'created-file', 'parents' => array( 'selected-folder', 'other-folder' ) ), 'selected-folder' );
mad4b_context_assert( is_wp_error( $multiple_parents ) && 'mad4b_google_drive_created_parent_mismatch' === $multiple_parents->get_error_code(), 'ambiguous provider parent binding must fail closed' );

$pending_blockers = $connection_blockers->invoke(
	null,
	array( 'configured' => true ),
	array( 'refresh_token' => 'encrypted-runtime-fixture', 'revocation_pending' => true )
);
mad4b_context_assert( in_array( 'google_drive_revocation_pending', $pending_blockers, true ), 'pending remote revocation must block Drive use even while a refresh token remains stored' );

$status_method = $ref->getMethod( 'connection_status' );
$status = $status_method->invoke( null );
$encoded = json_encode( $status );
mad4b_context_assert( false === strpos( $encoded, 'access_token' ), 'connection status must not expose access token' );
mad4b_context_assert( false === strpos( $encoded, 'refresh_token' ), 'connection status must not expose refresh token' );

echo "mad4b.site-control-plane.context-authority-runtime.v5: PASS\n";
