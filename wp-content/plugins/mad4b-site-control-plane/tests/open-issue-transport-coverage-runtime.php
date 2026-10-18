<?php
/** Hermetic #409/#378 regression; no host or WordPress acceptance implied. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message = '', $data = null ) { $this->code=$code; $this->message=$message; $this->data=$data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return strip_tags( (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_option( $key, $default = false ) { return $default; }
function add_action( $hook, $callback, $priority = 10 ) {}
function apply_filters( $tag, $rows ) {
    if ( $GLOBALS['catalog_mode'] === 'empty' ) return array();
    if ( $GLOBALS['catalog_mode'] === 'overflow' ) return array_fill( 0, 501, false );
    return array( 'untrusted' => array( 'remote_ability' => 'unsafe/callback' ) );
}
class MAD4B_SCP_Read_Consistency {
    public static function snapshot_header( $input ) { return array( 'runtime_generation' => str_repeat( 'a', 64 ), 'snapshot_id' => 'fixture' ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-connector-resilience.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-remote-operation-parity.php';
function issue_assert( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); }
}
$attempts = 0;
$read = MAD4B_SCP_Connector_Resilience::safe_read( 'bounded_read', static function () use ( &$attempts ) {
    ++$attempts;
    return new WP_Error( 'http_request_failed', 'timeout SECRET-DO-NOT-EXPOSE' );
} );
issue_assert( $attempts === 2 && ! $read['ok'], 'Bounded read retries exactly once' );
issue_assert( preg_match( '/^[a-f0-9]{32}$/D', $read['correlation_id'] ) === 1, 'Invocation correlation generated locally' );
issue_assert( $read['stage'] === 'bounded_read' && $read['timing_scope'] === 'origin_callback_attempts_only', 'Timing does not claim queue/proxy or client wall latency' );
issue_assert( strpos( json_encode( $read ), 'SECRET' ) === false, 'Raw exception/error text not exposed' );
$next = MAD4B_SCP_Connector_Resilience::safe_read( 'bounded_read', static function () { return array( 'ok' => true ); } );
issue_assert( $next['ok'] && $next['correlation_id'] !== $read['correlation_id'], 'Success has distinct invocation identity' );
$dispatch = MAD4B_SCP_Connector_Resilience::execute_read( 'fixture/read', static function () { throw new RuntimeException( 'SECRET failure' ); } );
issue_assert( is_wp_error( $dispatch ) && ! empty( $dispatch->get_error_data()['correlation_id'] ), 'Read dispatcher preserves correlation through WP_Error' );
$compact = MAD4B_SCP_Connector_Resilience::generation_fenced_compact_read( 'compact', static function () { return array(); }, static function () { return array( 'read_only' => true ); } );
issue_assert( is_array( $compact ) && ! empty( $compact['correlation_id'] ) && isset( $compact['callback_elapsed_ms'] ), 'Compact read includes correlation and separately scoped timing' );
$projections = 0;
$broken = MAD4B_SCP_Connector_Resilience::generation_fenced_compact_read( 'compact', static function () { return array(); }, static function () use ( &$projections ) { ++$projections; throw new RuntimeException( 'SECRET projection failure' ); } );
issue_assert( is_wp_error( $broken ) && $broken->get_error_code() === 'mad4b_generation_fenced_read_projection_failed' && $projections === 1, 'Projection exceptions are bounded and never blindly retried' );
issue_assert( ! empty( $broken->get_error_data()['correlation_id'] ) && strpos( json_encode( $broken->get_error_data() ), 'SECRET' ) === false, 'Projection error exposes correlation without raw details' );
foreach ( array( 'invalid', 'empty', 'overflow' ) as $mode ) {
    $GLOBALS['catalog_mode'] = $mode;
    $status = MAD4B_SCP_Remote_Operation_Parity::status();
    issue_assert( ! $status['ready'] && ! $status['registered_parity_ready'], 'Rejected, empty or truncated catalog cannot claim ready: ' . $mode );
    issue_assert( $status['total_discovered_automation_coverage'] === null && ! $status['complete_automation_coverage_proven'], 'Registered readiness is not whole-site automation coverage' );
    if ( $mode === 'overflow' ) issue_assert( $status['rejected_registrations'][0]['reason'] === 'catalog_registration_limit_exceeded', 'Silent 500-operation truncation is visible' );
}
echo "OPEN_ISSUE_TRANSPORT_COVERAGE: PASS\n";
