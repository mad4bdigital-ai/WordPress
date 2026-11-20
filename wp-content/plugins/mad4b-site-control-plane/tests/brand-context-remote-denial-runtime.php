<?php
// Hermetic, no WordPress bootstrap: audit metadata is request-local and never authorizing.
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
final class MAD4B_SCP_Transport_Context {
    public static function current_server_id() { return 'mad4b-chatgpt'; }
}
final class MAD4B_SCP_Audit {
    public static $records = array();
    public static function record( $key, $summary, $status ) {
        self::$records[] = array( $key, $summary, $status );
        return true;
    }
}
require_once __DIR__ . '/../includes/class-mad4b-scp-authorization.php';
function expect_denial( $condition, $reason ) {
    if ( ! $condition ) { fwrite( STDERR, 'BRAND_CONTEXT_REMOTE_DENIAL: ' . $reason . "\n" ); exit( 1 ); }
}
$target = 'context/source-scan-apply';
$other  = 'context/remote-owner-review-apply';
$traits = ( new ReflectionClass( 'MAD4B_SCP_Authorization' ) )->getTraitNames();
expect_denial( in_array( 'MAD4B_SCP_Authorization_Observation', $traits, true ), 'Observation trait extraction missing' );
expect_denial( in_array( 'MAD4B_SCP_Authorization_Remote_Denial', $traits, true ), 'Remote denial trait extraction missing' );
// The extracted observer remains request-local. An active provider callback
// still blocks a cache reset until the original callback boundary is closed.
MAD4B_SCP_Authorization::begin_execution_callback_observation( $target );
MAD4B_SCP_Authorization::mark_execution_callback_started( $target );
$active = MAD4B_SCP_Authorization::request_scope_state();
expect_denial( 1 === $active['observation_count'] && in_array( $target, $active['active_execution_observations'], true ), 'Request-local active execution was lost' );
expect_denial( is_wp_error( MAD4B_SCP_Authorization::reset_request_cache() ), 'Active execution must prevent reset' );
MAD4B_SCP_Authorization::clear_execution_callback_observation( $target );

// Neither a previous request nor a different ability may bleed into diagnostics.
expect_denial( '' === MAD4B_SCP_Authorization::remote_permission_denial_code( $target ), 'leaked before observation' );
MAD4B_SCP_Authorization::begin_execution_callback_observation( $target );
MAD4B_SCP_Authorization::audit_remote_permission_denial(
    $target, new WP_Error( 'mad4b_context_exact_permission_denied' ) );
expect_denial( 'mad4b_context_exact_permission_denied' === MAD4B_SCP_Authorization::remote_permission_denial_code( $target ), 'permission reason lost' );
expect_denial( '' === MAD4B_SCP_Authorization::remote_permission_denial_code( $other ), 'cross-ability leak' );
MAD4B_SCP_Authorization::clear_execution_callback_observation( $target );
expect_denial( '' === MAD4B_SCP_Authorization::remote_permission_denial_code( $target ), 'clear did not remove reason' );
// Fresh request starts clean; reset cannot preserve an old denial.
MAD4B_SCP_Authorization::begin_execution_callback_observation( $target );
expect_denial( '' === MAD4B_SCP_Authorization::remote_permission_denial_code( $target ), 'old reason reused' );
MAD4B_SCP_Authorization::audit_remote_permission_denial( $target, new WP_Error( 'MAD4B_GOOGLE_DRIVE_NOT_CONNECTED' ) );
expect_denial( 'mad4b_google_drive_not_connected' === MAD4B_SCP_Authorization::remote_permission_denial_code( $target ), 'safe code normalization failed' );
expect_denial( true === MAD4B_SCP_Authorization::reset_request_cache(), 'request reset failed' );
expect_denial( '' === MAD4B_SCP_Authorization::remote_permission_denial_code( $target ), 'reset did not erase reason' );
echo "BRAND_CONTEXT_REMOTE_DENIAL: PASS\n";
