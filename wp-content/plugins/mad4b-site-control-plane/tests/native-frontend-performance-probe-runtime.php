<?php
/**
 * WordPress native SAVEQUERIES only for an exact Staging signed browser probe.
 * No external provider, raw SQL persistence or DB option mutation.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
define( 'REST_REQUEST', false );
$GLOBALS['frontend_probe_is_admin'] = false;
$GLOBALS['frontend_probe_env'] = 'staging';
$GLOBALS['frontend_probe_valid'] = true;
$GLOBALS['frontend_probe_filters'] = array();
$GLOBALS['frontend_probe_option_reads'] = 0;
function is_admin() { return $GLOBALS['frontend_probe_is_admin']; }
function wp_doing_cron() { return false; }
function wp_unslash( $v ) { return $v; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['frontend_probe_filters'][ $hook ] = $callback;
}
function remove_filter( $hook, $callback, $priority = 10 ) {
    unset( $GLOBALS['frontend_probe_filters'][ $hook ] );
}
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_option( $name, $default = false ) {
    ++$GLOBALS['frontend_probe_option_reads'];
    if ( 'test_mad4b_browser_request' !== $name ) return $default;
    return array( 'request_id' => '99999999-aaaa-4aaa-aaaa-999999999999',
        'probe_hash' => hash('sha256','99999999-aaaa-4aaa-aaaa-999999999999'),
        'expires_at_epoch' => time() + 300, 'status' => 'pending_external_executor' );
}
final class MAD4B_SCP_Site_Profile {
    public static function current_environment() { return $GLOBALS['frontend_probe_env']; }
}
final class MAD4B_SCP_Live_Acceptance_Observer {
    public static function staging_capture_allowed() { return $GLOBALS['frontend_probe_valid']; }
}
final class MAD4B_SCP_Remote_Operation_Parity {
    const BROWSER_REQUEST_OPTION = 'test_mad4b_browser_request';
}
final class WPDB_Native_Probe {
    public $save_queries = false;
    public $queries = array();
}
global $wpdb;
$wpdb = new WPDB_Native_Probe();
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-query-monitor-evidence-bridge.php';

$c='MAD4B_SCP_Query_Monitor_Evidence_Bridge';
function assert_native( $ok, $message ) {
    if ( !$ok ) { fwrite( STDERR, 'FAIL ' . $message . "\n" ); exit(1); }
}
assert_native( $c::passive_frontend_should_sample( str_repeat('0',64) )
    && !$c::passive_frontend_should_sample( '01' . str_repeat('a',62) ),
    'request-local one-in-eight sampling is deterministic and bounded' );
$_GET = array();
assert_native( $c::maybe_capture_native_wpdb_queries() === false
    && $GLOBALS['frontend_probe_option_reads']===0 && !$wpdb->save_queries,
    'normal visitors must have zero native probe work' );
$_GET = array( 'mad4b_frontend_probe' => 'malformed' );
assert_native( $c::maybe_capture_native_wpdb_queries() === false
    && $GLOBALS['frontend_probe_option_reads']===0,
    'invalid request is rejected before option reading' );
$_GET = array( 'mad4b_frontend_probe' => '99999999-aaaa-4aaa-aaaa-999999999999' );
$GLOBALS['frontend_probe_env'] = 'production';
assert_native( $c::maybe_capture_native_wpdb_queries() === false
    && $GLOBALS['frontend_probe_option_reads']===0, 'production never instruments' );
$GLOBALS['frontend_probe_env'] = 'staging';
$GLOBALS['frontend_probe_is_admin'] = true;
assert_native( $c::maybe_capture_native_wpdb_queries() === false, 'admin never instruments' );
$GLOBALS['frontend_probe_is_admin'] = false;
$GLOBALS['frontend_probe_valid'] = false;
assert_native( $c::maybe_capture_native_wpdb_queries() === false, 'missing signed acceptance denied' );
$GLOBALS['frontend_probe_valid'] = true;
assert_native( $c::maybe_capture_native_wpdb_queries() === true && $wpdb->save_queries === true
    && isset( $GLOBALS['frontend_probe_filters']['query'] ), 'valid Staging browser probe enables native query logging' );
$wpdb->queries = array_fill( 0, 400, array( 'SELECT 1', 0.000001, 'wp_core_stub' ) );
$sql = $c::limit_native_wpdb_query_capture( 'SELECT 2' );
assert_native( $sql === 'SELECT 2' && $wpdb->save_queries === false
    && !isset( $GLOBALS['frontend_probe_filters']['query'] ), 'bounded 400 row sample disables logging without query mutation' );
echo "NATIVE_FRONTEND_PERFORMANCE_PROBE_PASS\n";
