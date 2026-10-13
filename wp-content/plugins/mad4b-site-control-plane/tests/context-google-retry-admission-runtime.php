<?php
/** Bounded native WP-admin Retry revoke preflight; no real Google calls. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_unslash( $text ) { return $text; }
function sanitize_text_field( $text ) { return trim( (string) $text ); }
function sanitize_key( $text ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $text ) ); }
function mad4b_check( $ok, $description ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: " . $description . "\n" ); exit( 1 ); }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-admin-ui.php';
$method = new ReflectionMethod( 'MAD4B_SCP_Context_Admin_UI', 'google_revocation_admission' );
$method->setAccessible( true );
$now = 1791885600;
$previous = gmdate( 'c', $now - 120 );
$pending = array(
    'connected' => true, 'revocation_pending' => true,
    'revocation_attempted_at' => $previous, 'revocation_error' => 'google_revocation_http_400',
    'read_available' => false, 'write_available' => false,
);
$request = array(
    'google_revocation_action' => 'retry_pending',
    'expected_revocation_attempted_at' => $previous,
    'confirm_pending_google_revoke' => '1',
);
$check = static function ( $connection, $input, $method_name, $at, $expected, $description ) use ( $method ) {
    $result = $method->invoke( null, $connection, $input, $method_name, $at );
    $code = is_wp_error( $result ) ? $result->get_error_code() : ( true === $result ? 'PASS' : 'UNEXPECTED' );
    mad4b_check( $expected === $code, $description . ' (observed: ' . $code . ')' );
};
$check( $pending, $request, 'POST', $now, 'PASS', 'Fresh pending grant with explicit confirmation' );
$check( $pending, $request, 'GET', $now, 'mad4b_google_revoke_post_required', 'Reject GET even with nonce' );
$check( $pending, array(), 'POST', $now, 'mad4b_google_revoke_action_state_mismatch', 'Old form cannot retry pending revoke' );
$check( $pending, array_merge( $request, array( 'confirm_pending_google_revoke' => '0' ) ),
    'POST', $now, 'mad4b_google_revoke_explicit_confirmation_required', 'Require conscious checkbox' );
$check( $pending, array_merge( $request, array( 'confirm_pending_google_revoke' => array( '1' ) ) ),
    'POST', $now, 'mad4b_google_revoke_explicit_confirmation_required', 'Malformed checkbox denied without PHP cast' );
$check( $pending, array_merge( $request, array( 'expected_revocation_attempted_at' => gmdate( 'c', $now - 130 ) ) ),
    'POST', $now, 'mad4b_google_revoke_snapshot_changed', 'Reject stale pending attempt snapshot' );
$check( $pending, $request, 'POST', $now - 100, 'mad4b_google_revoke_retry_cooldown', 'Bound repeated HTTP 400 retries' );
$check( array_merge( $pending, array( 'revocation_attempted_at' => 'invalid' ) ),
    array_merge( $request, array( 'expected_revocation_attempted_at' => 'invalid' ) ),
    'POST', $now, 'mad4b_google_revoke_retry_timestamp_invalid', 'Invalid pending timestamp never calls Google' );
$check( array( 'connected' => true, 'revocation_pending' => false ), $request,
    'POST', $now, 'mad4b_google_revoke_action_state_mismatch', 'Old retry cannot revoke a replacement grant' );
$check( array( 'connected' => true, 'revocation_pending' => false ), array(),
    'POST', $now, 'PASS', 'Normal live disconnect remains available' );
$check( array( 'connected' => false, 'revocation_pending' => false ), array(),
    'POST', $now, 'mad4b_google_revoke_already_disconnected', 'Already disconnected does not claim new work' );
$check( array( 'connected' => false, 'token_unreadable' => true ), array(),
    'POST', $now, 'PASS', 'Unreadable local-token cleanup remains available' );
echo "PASS: native Google retry POST, snapshot, checkbox, cooldown, no stale grant revoke\n";
