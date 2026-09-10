<?php
/**
 * Web lifecycle acceptance when MAD4B boots before REST, the official MCP
 * Adapter is pinned, but another component removes the Adapter's REST init
 * callback before the first REST bootstrap.
 */

$wp_path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $wp_path ) || '' === trim( $wp_path ) ) {
	fwrite( STDERR, "FAIL mcp-web-postcondition-recovery: MAD4B_TEST_WP_PATH is required\n" );
	exit( 1 );
}
$wp_path = rtrim( $wp_path, '/\\' );
if ( ! is_file( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "FAIL mcp-web-postcondition-recovery: wp-load.php not found\n" );
	exit( 1 );
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	fwrite( STDERR, "FAIL mcp-web-postcondition-recovery: WP_CLI must remain undefined/false\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-read';

require $wp_path . '/wp-load.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL mcp-web-postcondition-recovery: ' . $message . PHP_EOL );
	exit( 1 );
};

if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) $fail( 'canonical MCP Adapter class unavailable' );
if ( ! class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) ) $fail( 'MAD4B registration bridge unavailable' );
if ( ! class_exists( 'MAD4B_SCP_Connection_Status' ) ) $fail( 'connection status unavailable' );
if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) $fail( 'MAD4B server registry unavailable' );

$before = MAD4B_SCP_MCP_Registration_Bridge::status();
if ( ! empty( $before['rest_init_seen_before_bridge_boot'] ) ) $fail( 'REST must not initialize before bridge boot in this fixture' );
if ( 0 !== (int) ( isset( $before['rest_api_init_count'] ) ? $before['rest_api_init_count'] : -1 ) ) $fail( 'REST unexpectedly initialized before explicit prime' );
if ( 0 !== (int) ( isset( $before['mcp_adapter_init_count'] ) ? $before['mcp_adapter_init_count'] : -1 ) ) $fail( 'Adapter unexpectedly initialized before explicit prime' );
if ( empty( $before['rest_postcondition_watchdog_bound'] ) ) $fail( 'REST postcondition watchdog is not bound' );
if ( ! empty( $before['rest_postcondition_recovery_triggered'] ) ) $fail( 'postcondition recovery triggered before REST' );

$adapter = \WP\MCP\Core\McpAdapter::instance();
$priority = has_action( 'rest_api_init', array( $adapter, 'init' ) );
if ( false === $priority ) $fail( 'official Adapter rest_api_init callback was not armed before suppression' );
remove_action( 'rest_api_init', array( $adapter, 'init' ), (int) $priority );
if ( false !== has_action( 'rest_api_init', array( $adapter, 'init' ) ) ) $fail( 'fixture failed to suppress official Adapter REST callback' );

// WordPress 6.9 REST route creation expects this Core admin class to be loaded.
if ( ! class_exists( 'WP_Site_Health', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
}
rest_get_server();

$bridge = MAD4B_SCP_MCP_Registration_Bridge::status();
if ( empty( $bridge['rest_postcondition_watchdog_bound'] ) ) $fail( 'REST postcondition watchdog lost its binding' );
if ( empty( $bridge['rest_postcondition_recovery_triggered'] ) ) $fail( 'missing Adapter init was not detected after REST' );
if ( empty( $bridge['missed_rest_recovery_attempted'] ) ) $fail( 'bounded recovery was not attempted' );
if ( empty( $bridge['missed_rest_recovery_succeeded'] ) ) $fail( 'bounded recovery failed: ' . ( isset( $bridge['missed_rest_recovery_blocker'] ) ? $bridge['missed_rest_recovery_blocker'] : 'unknown' ) );
if ( 'missed_rest_lifecycle_recovered' !== ( isset( $bridge['missed_rest_recovery_state'] ) ? $bridge['missed_rest_recovery_state'] : '' ) ) $fail( 'unexpected recovery state' );
if ( ! empty( $bridge['missed_rest_recovery_blocker'] ) ) $fail( 'recovery blocker: ' . $bridge['missed_rest_recovery_blocker'] );
if ( 1 !== (int) ( isset( $bridge['rest_api_init_count'] ) ? $bridge['rest_api_init_count'] : 0 ) ) $fail( 'rest_api_init must remain exactly one; global replay is forbidden' );
if ( 1 !== (int) ( isset( $bridge['mcp_adapter_init_count'] ) ? $bridge['mcp_adapter_init_count'] : 0 ) ) $fail( 'official mcp_adapter_init must fire exactly once during recovery' );
if ( empty( $bridge['adapter_runtime_from_official_plugin'] ) ) $fail( 'runtime is not owned by official MCP Adapter' );
if ( '0.6.1' !== ( isset( $bridge['adapter_runtime_version'] ) ? (string) $bridge['adapter_runtime_version'] : '' ) ) $fail( 'unexpected MCP Adapter runtime version' );

$expected = MAD4B_SCP_Servers::expected_server_ids();
if ( count( $expected ) !== (int) ( isset( $bridge['missed_rest_recovery_route_count'] ) ? $bridge['missed_rest_recovery_route_count'] : 0 ) ) $fail( 'targeted route recovery count mismatch' );

$registrations = MAD4B_SCP_Servers::registration_status();
foreach ( $expected as $server_id ) {
	if ( empty( $registrations[ $server_id ]['registered'] ) ) $fail( $server_id . ' not registered after postcondition recovery' );
	if ( ! empty( $registrations[ $server_id ]['error'] ) ) $fail( $server_id . ' registration error: ' . $registrations[ $server_id ]['error'] );
}

$status = MAD4B_SCP_Connection_Status::status();
if ( empty( $status['local_transport_ready'] ) ) $fail( 'local transport remains blocked: ' . wp_json_encode( $status['local_blockers'] ) );
foreach ( isset( $status['servers'] ) && is_array( $status['servers'] ) ? $status['servers'] : array() as $server ) {
	$id = isset( $server['server_id'] ) ? (string) $server['server_id'] : 'unknown';
	if ( empty( $server['registered'] ) ) $fail( $id . ' connection snapshot says not registered' );
	if ( empty( $server['route_registered'] ) ) $fail( $id . ' REST route not registered' );
	if ( empty( $server['permission_callback_match'] ) ) $fail( $id . ' permission binding mismatch' );
}

echo 'mad4b.site-control-plane.mcp-web-postcondition-recovery.v1: PASS' . PHP_EOL;
