<?php
$cases = array( 'authorized', 'transaction_contention', 'persistent_cache_stale', 'cli_generic_refresh', 'cron_generic_refresh', 'cli_direct_recovery_denied', 'cron_direct_recovery_denied', 'nonce', 'build', 'capability', 'post', 'production', 'protocol', 'diagnostic', 'integrity', 'audit_unavailable', 'audit_failed', 'lease_busy', 'stale_managed', 'unmanaged', 'update_schedule', 'profile_schedule' );
if ( ! isset( $argv[1] ) ) {
	foreach ( $cases as $case ) { passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $case ), $code ); if ( $code ) exit( $code ); }
	echo 'mad4b.mcp-runtime-recovery.v1: 18/18 PASS' . PHP_EOL; exit;
}
$case = $argv[1];
if ( in_array( $case, array( 'cli_generic_refresh', 'cli_direct_recovery_denied' ), true ) && ! defined( 'WP_CLI' ) ) define( 'WP_CLI', true );
$root = sys_get_temp_dir() . '/mad4b-recovery-' . getmypid();
$source = dirname( __DIR__ );
define( 'ABSPATH', $root . '/' ); define( 'WP_PLUGIN_DIR', $root . '/plugins' ); define( 'WPMU_PLUGIN_DIR', $root . '/mu' ); define( 'MAD4B_SCP_DIR', $source . '/' ); define( 'MAD4B_SCP_FILE', $source . '/mad4b-site-control-plane.php' );
function check_recovery( $v, $m ) { if ( ! $v ) throw new RuntimeException( $GLOBALS['case'] . ': ' . $m ); }
class WP_Error { private $code; function __construct( $c, $m = '' ) { $this->code=$c; } function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ); }
function sanitize_text_field( $v ) { return $v; }
function wp_unslash( $v ) { return $v; }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }
function wp_normalize_path( $v ) { return str_replace( '\\', '/', $v ); }
function home_url( $p = '' ) { return 'https://staging.fixture.test' . $p; }
function wp_parse_url( $v, $part = -1 ) { return parse_url( $v, $part ); }
function current_user_can( $c ) { return 'capability' !== $GLOBALS['case']; }
function is_admin() { return true; }
function wp_doing_cron() { return in_array( $GLOBALS['case'], array( 'cron_generic_refresh', 'cron_direct_recovery_denied' ), true ); }
function current_filter() { return 'cron_direct_recovery_denied' === $GLOBALS['case'] ? 'foreign_cron_hook' : ''; }
function wp_verify_nonce( $v, $action ) { return 'nonce' !== $GLOBALS['case'] && 'valid' === $v; }
function get_option( $k, $default = null ) {
	if ( isset( $GLOBALS['option_cache'] ) && array_key_exists( $k, $GLOBALS['option_cache'] ) ) return $GLOBALS['option_cache'][ $k ];
	return $GLOBALS['options'][ $k ] ?? $default;
}
function wp_cache_delete( $k, $group = '' ) {
	if ( 'options' === $group && isset( $GLOBALS['option_cache'] ) ) {
		if ( 'notoptions' === $k ) unset( $GLOBALS['option_cache']['notoptions'] );
		else unset( $GLOBALS['option_cache'][ $k ] );
	}
	$GLOBALS['cache_delete_calls'] = isset( $GLOBALS['cache_delete_calls'] ) ? $GLOBALS['cache_delete_calls'] + 1 : 1;
	return true;
}
function add_option( $k, $v, $deprecated = '', $autoload = 'yes' ) { if ( array_key_exists( $k, $GLOBALS['options'] ) ) return false; $GLOBALS['options'][ $k ]=$v; return true; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ]=$v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function wp_mkdir_p( $v ) { return mkdir( $v, 0777, true ); }
function plugin_basename( $v ) { return 'mad4b-site-control-plane/mad4b-site-control-plane.php'; }
function wp_next_scheduled( $hook ) { return $GLOBALS['scheduled'][ $hook ] ?? false; }
function wp_schedule_single_event( $when, $hook ) { $GLOBALS['scheduled'][ $hook ]=$when; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['scheduled'][ $hook ] ); }
class MAD4B_SCP_Site_Profile {
	static function current_environment() { return 'production' === $GLOBALS['case'] ? 'production' : 'staging'; }
	static function current_host() { return 'staging.fixture.test'; }
	static function nonproduction_governed( $feature ) { return 'production' !== $GLOBALS['case']; }
	static function origin_enrolled() { return true; }
	static function managed_runtime_enabled() { return true; }
}
class MAD4B_SCP_MCP_Request_Scope { static function current_request_is_protocol_hotpath() { return 'protocol' === $GLOBALS['case']; } static function current_request_is_endpoint_diagnostic_job() { return 'diagnostic' === $GLOBALS['case']; } }
class MAD4B_SCP_Dependency_Manager { static function mcp_adapter_disk_integrity() { return array( 'ready' => 'integrity' !== $GLOBALS['case'] ); } }
class MAD4B_SCP_Endpoint_Diagnostic { static function build_fingerprint() { return 'current-build'; } }
class MAD4B_SCP_Runtime_Maintenance_Lease {
	static function acquire( $owner ) { $GLOBALS['lease_acquired']=true; return 'lease_busy' === $GLOBALS['case'] ? new WP_Error( 'lease_busy' ) : 'fenced-token'; }
	static function refresh( $token, $owner ) { return true; }
	static function release( $token, $owner ) { $GLOBALS['lease_released']=true; }
}
class MAD4B_SCP_Audit {
	static function storage_status() { return array( 'ready' => 'audit_unavailable' !== $GLOBALS['case'] ); }
	static function record( $event, $data, $state ) { $GLOBALS['audit_events'][]=$event; return 'audit_failed' === $GLOBALS['case'] ? new WP_Error( 'audit_failed' ) : true; }
}
function recovery_remove( $p ) { if ( is_dir( $p ) && ! is_link( $p ) ) { foreach ( array_diff( scandir( $p ), array( '.', '..' ) ) as $f ) recovery_remove( $p . '/' . $f ); rmdir( $p ); } elseif ( file_exists( $p ) || is_link( $p ) ) unlink( $p ); }
register_shutdown_function( function () use ( $root ) { recovery_remove( $root ); } );
mkdir( WP_PLUGIN_DIR . '/mcp-adapter/includes/Core', 0777, true ); mkdir( WPMU_PLUGIN_DIR, 0777, true );
$adapter = WP_PLUGIN_DIR . '/mcp-adapter/includes/Core/McpAdapter.php';
file_put_contents( $adapter, '<?php namespace WP\\MCP\\Core; class McpAdapter {}' ); require $adapter;
$options=array( 'active_plugins' => array( 'mcp-adapter/mcp-adapter.php', 'mad4b-site-control-plane/mad4b-site-control-plane.php' ) );
$_SERVER['REQUEST_METHOD']='post' === $case ? 'GET' : 'POST';
$_POST=array( 'action'=>'mad4b_repair_mcp_runtime', 'nonce'=>'valid', 'build'=>'build' === $case ? 'old-build' : 'current-build' );
require $source . '/includes/class-mad4b-scp-mcp-mu-bootstrap-refresh.php';
require $source . '/includes/class-mad4b-scp-mcp-runtime-conflict-guard.php';
require $source . '/includes/class-mad4b-scp-mcp-runtime-recovery.php';
$destination = WPMU_PLUGIN_DIR . '/000-mad4b-mcp-adapter-bootstrap.php';
if ( 'cli_generic_refresh' === $case ) {
	$status = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::bootstrap();
	check_recovery( ! empty( $status['refresh_deferred'] ) && 'deferred_request_hotpath' === ( $status['state'] ?? '' ), 'generic WP-CLI entered MCP filesystem repair lifecycle' );
	check_recovery( ! file_exists( WPMU_PLUGIN_DIR . '/000-mad4b-mcp-adapter-bootstrap.php' ), 'generic WP-CLI mutated MU filesystem' );
	echo $case . ': PASS' . PHP_EOL;
	exit;
}

if ( 'cron_generic_refresh' === $case ) {
	$status = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::bootstrap();
	check_recovery( ! empty( $status['refresh_deferred'] ) && 'deferred_request_hotpath' === ( $status['state'] ?? '' ), 'generic wp-cron entered MCP filesystem repair lifecycle' );
	check_recovery( ! file_exists( WPMU_PLUGIN_DIR . '/000-mad4b-mcp-adapter-bootstrap.php' ), 'generic wp-cron mutated MU filesystem' );
	echo $case . ': PASS' . PHP_EOL;
	exit;
}

if ( in_array( $case, array( 'cli_direct_recovery_denied', 'cron_direct_recovery_denied' ), true ) ) {
	$result = MAD4B_SCP_MCP_Runtime_Recovery::run();
	check_recovery( is_wp_error( $result ) && 'mad4b_mcp_repair_lifecycle_required' === $result->get_error_code(), 'generic infrastructure context invoked direct MCP recovery' );
	check_recovery( ! file_exists( WPMU_PLUGIN_DIR . '/000-mad4b-mcp-adapter-bootstrap.php' ), 'denied direct recovery mutated MU filesystem' );
	echo $case . ': PASS' . PHP_EOL;
	exit;
}

if ( 'persistent_cache_stale' === $case ) {
	$first = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::begin_transaction( 'install', '', str_repeat( 'd', 64 ) );
	check_recovery( is_string( $first ) && 32 === strlen( $first ), 'persistent-cache fixture failed to acquire transaction' );
	$GLOBALS['option_cache'][ MAD4B_SCP_MCP_MU_Bootstrap_Refresh::TRANSACTION_OPTION ] = array();
	$before_cache_deletes = isset( $GLOBALS['cache_delete_calls'] ) ? $GLOBALS['cache_delete_calls'] : 0;
	$reconcile = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::reconcile_transaction( $destination );
	check_recovery( is_wp_error( $reconcile ) && 'mu_bootstrap_transaction_in_progress' === $reconcile->get_error_code(), 'stale persistent cache hid authoritative transaction row' );
	check_recovery( ( $GLOBALS['cache_delete_calls'] ?? 0 ) > $before_cache_deletes, 'transaction read did not invalidate persistent option cache' );
	check_recovery( MAD4B_SCP_MCP_MU_Bootstrap_Refresh::complete_transaction( $first ), 'owner could not complete cache regression transaction' );
	echo $case . ': PASS' . PHP_EOL;
	exit;
}
if ( 'transaction_contention' === $case ) {
	$first = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::begin_transaction( 'install', '', str_repeat( 'a', 64 ) );
	check_recovery( is_string( $first ) && 32 === strlen( $first ), 'first worker failed to acquire transaction' );
	$snapshot = get_option( MAD4B_SCP_MCP_MU_Bootstrap_Refresh::TRANSACTION_OPTION, array() );
	$second = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::begin_transaction( 'refresh', str_repeat( 'b', 64 ), str_repeat( 'c', 64 ) );
	check_recovery( is_wp_error( $second ) && 'mu_bootstrap_transaction_already_pending' === $second->get_error_code(), 'second worker acquired active transaction' );
	check_recovery( $snapshot === get_option( MAD4B_SCP_MCP_MU_Bootstrap_Refresh::TRANSACTION_OPTION, array() ), 'second worker mutated owner marker' );
	$reconcile = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::reconcile_transaction( $destination );
	check_recovery( is_wp_error( $reconcile ) && 'mu_bootstrap_transaction_in_progress' === $reconcile->get_error_code(), 'fresh owner transaction was reconciled by another worker' );
	check_recovery( MAD4B_SCP_MCP_MU_Bootstrap_Refresh::complete_transaction( $first ), 'owner could not complete transaction' );
	echo $case . ': PASS' . PHP_EOL;
	exit;
}
if ( 'stale_managed' === $case ) file_put_contents( $destination, '<?php // mad4b.mcp-adapter-mu-bootstrap.v4' );
if ( 'unmanaged' === $case ) file_put_contents( $destination, '<?php // foreign owner' );
if ( 'update_schedule' === $case ) {
	MAD4B_SCP_MCP_Runtime_Recovery::after_upgrade( null, array( 'type'=>'plugin', 'plugins'=>array( 'foreign/foreign.php' ) ) );
	check_recovery( empty( $GLOBALS['scheduled'] ), 'foreign update scheduled recovery' );
	MAD4B_SCP_MCP_Runtime_Recovery::after_upgrade( null, array( 'type'=>'plugin', 'plugins'=>array( 'mcp-adapter/mcp-adapter.php' ) ) );
	check_recovery( 1===count( $GLOBALS['scheduled'] ), 'exact adapter update did not schedule new-request recovery' );
	MAD4B_SCP_MCP_Runtime_Recovery::schedule(); check_recovery( 1===count( $GLOBALS['scheduled'] ), 'schedule duplicated' );
} elseif ( 'profile_schedule' === $case ) {
	MAD4B_SCP_MCP_Runtime_Recovery::profile_saved(); check_recovery( 1===count( $GLOBALS['scheduled'] ), 'staging profile did not schedule' );
	$GLOBALS['case']='production'; MAD4B_SCP_MCP_Runtime_Recovery::profile_saved(); check_recovery( empty( $GLOBALS['scheduled'] ), 'Production transition did not cancel recovery' );
} else {
	$result=MAD4B_SCP_MCP_Runtime_Recovery::run( '', true );
	if ( 'authorized' === $case ) {
		check_recovery( ! is_wp_error( $result ) && $result['next_request_required'] && ! $result['connection_certified'], 'recovery did not arm next request' );
		check_recovery( is_file( $destination ) && hash_file( 'sha256', $destination )===hash_file( 'sha256', $source . '/bootstrap/mad4b-mcp-adapter-mu-bootstrap.php' ), 'bootstrap readback mismatch' );
		check_recovery( ! empty( $GLOBALS['audit_events'] ), 'recovery lacked audit evidence' );
	} else {
		check_recovery( is_wp_error( $result ), 'negative boundary accepted' );
		if ( 'stale_managed' === $case ) check_recovery( '<?php // mad4b.mcp-adapter-mu-bootstrap.v4'===file_get_contents( $destination ), 'marker-only bootstrap was treated as historical MAD4B ownership' );
		elseif ( 'unmanaged' === $case ) check_recovery( '<?php // foreign owner'===file_get_contents( $destination ), 'unmanaged bootstrap overwritten' );
		else check_recovery( ! file_exists( $destination ), 'denied/rolled-back recovery left bootstrap bytes' );
	}
	check_recovery( ! MAD4B_SCP_MCP_Runtime_Recovery::active(), 'recovery privilege leaked beyond lifecycle' );
	if ( ! empty( $GLOBALS['lease_acquired'] ) && 'lease_busy' !== $case ) check_recovery( ! empty( $GLOBALS['lease_released'] ), 'shared lease leaked' );
}
echo $case . ': PASS' . PHP_EOL;
