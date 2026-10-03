<?php
$cases = array( 'authorized', 'nonce', 'build', 'capability', 'post', 'production', 'protocol', 'diagnostic', 'integrity', 'audit_unavailable', 'audit_failed', 'lease_busy', 'stale_managed', 'unmanaged', 'update_schedule', 'profile_schedule' );
if ( ! isset( $argv[1] ) ) {
	foreach ( $cases as $case ) { passthru( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $case ), $code ); if ( $code ) exit( $code ); }
	echo 'mad4b.mcp-runtime-recovery.v1: 16/16 PASS' . PHP_EOL; exit;
}
$case = $argv[1];
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
function wp_doing_cron() { return false; }
function wp_verify_nonce( $v, $action ) { return 'nonce' !== $GLOBALS['case'] && 'valid' === $v; }
function get_option( $k, $default = null ) { return $GLOBALS['options'][ $k ] ?? $default; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ]=$v; return true; }
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
	if ( in_array( $case, array( 'authorized', 'stale_managed' ), true ) ) {
		check_recovery( ! is_wp_error( $result ) && $result['next_request_required'] && ! $result['connection_certified'], 'recovery did not arm next request' );
		check_recovery( is_file( $destination ) && hash_file( 'sha256', $destination )===hash_file( 'sha256', $source . '/bootstrap/mad4b-mcp-adapter-mu-bootstrap.php' ), 'bootstrap readback mismatch' );
		check_recovery( ! empty( $GLOBALS['audit_events'] ), 'recovery lacked audit evidence' );
	} else {
		check_recovery( is_wp_error( $result ), 'negative boundary accepted' );
		if ( 'unmanaged' === $case ) check_recovery( '<?php // foreign owner'===file_get_contents( $destination ), 'unmanaged bootstrap overwritten' );
		else check_recovery( ! file_exists( $destination ), 'denied/rolled-back recovery left bootstrap bytes' );
	}
	check_recovery( ! MAD4B_SCP_MCP_Runtime_Recovery::active(), 'recovery privilege leaked beyond lifecycle' );
	if ( ! empty( $GLOBALS['lease_acquired'] ) && 'lease_busy' !== $case ) check_recovery( ! empty( $GLOBALS['lease_released'] ), 'shared lease leaked' );
}
echo $case . ': PASS' . PHP_EOL;
