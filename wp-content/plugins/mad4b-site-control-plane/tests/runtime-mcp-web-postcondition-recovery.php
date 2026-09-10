<?php
/**
 * Web lifecycle acceptance for the live Staging failure shape where REST boots
 * after MAD4B, but a host/provider rewrites the mutable lifecycle callbacks.
 *
 * The fixture removes the official Adapter REST callback, the legacy bridge REST
 * watchdog, the fresh rescue REST tail, and the bridge mcp_adapter_init server
 * binder. The fresh rescue must still recover from rest_pre_dispatch without a
 * global rest_api_init replay.
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
if ( ! class_exists( 'MAD4B_SCP_MCP_Registration_Rescue' ) ) $fail( 'fresh registration rescue unavailable' );
if ( ! class_exists( 'MAD4B_SCP_Connection_Status' ) ) $fail( 'connection status unavailable' );
if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) $fail( 'MAD4B server registry unavailable' );

$bridge_before = MAD4B_SCP_MCP_Registration_Bridge::status();
$rescue_before = MAD4B_SCP_MCP_Registration_Rescue::status();
if ( ! empty( $bridge_before['rest_init_seen_before_bridge_boot'] ) ) $fail( 'REST must not initialize before bridge boot in this fixture' );
if ( 0 !== (int) ( isset( $bridge_before['rest_api_init_count'] ) ? $bridge_before['rest_api_init_count'] : -1 ) ) $fail( 'REST unexpectedly initialized before explicit prime' );
if ( 0 !== (int) ( isset( $bridge_before['mcp_adapter_init_count'] ) ? $bridge_before['mcp_adapter_init_count'] : -1 ) ) $fail( 'Adapter unexpectedly initialized before explicit prime' );
if ( empty( $rescue_before['rest_tail_bound_initial'] ) ) $fail( 'fresh rescue REST tail was not initially bound' );
if ( empty( $rescue_before['pre_dispatch_bound_initial'] ) || empty( $rescue_before['pre_dispatch_bound_current'] ) ) $fail( 'fresh pre-dispatch rescue is not bound' );
if ( ! empty( $rescue_before['attempted'] ) ) $fail( 'rescue attempted before REST' );

$adapter = \WP\MCP\Core\McpAdapter::instance();
$priority = has_action( 'rest_api_init', array( $adapter, 'init' ) );
if ( false === $priority ) $fail( 'official Adapter rest_api_init callback was not armed before suppression' );
remove_action( 'rest_api_init', array( $adapter, 'init' ), (int) $priority );
remove_action( 'rest_api_init', array( 'MAD4B_SCP_MCP_Registration_Bridge', 'verify_adapter_init_after_rest' ), PHP_INT_MAX );
remove_action( 'rest_api_init', array( 'MAD4B_SCP_MCP_Registration_Rescue', 'after_rest_init' ), PHP_INT_MAX - 1 );
remove_action( 'mcp_adapter_init', array( 'MAD4B_SCP_MCP_Registration_Bridge', 'register_servers' ), 10 );

if ( false !== has_action( 'rest_api_init', array( $adapter, 'init' ) ) ) $fail( 'fixture failed to suppress official Adapter REST callback' );
if ( false !== has_action( 'rest_api_init', array( 'MAD4B_SCP_MCP_Registration_Bridge', 'verify_adapter_init_after_rest' ) ) ) $fail( 'fixture failed to suppress bridge REST watchdog' );
if ( false !== has_action( 'rest_api_init', array( 'MAD4B_SCP_MCP_Registration_Rescue', 'after_rest_init' ) ) ) $fail( 'fixture failed to suppress rescue REST tail' );
if ( false !== has_action( 'mcp_adapter_init', array( 'MAD4B_SCP_MCP_Registration_Bridge', 'register_servers' ) ) ) $fail( 'fixture failed to suppress bridge server binder' );
if ( false === has_filter( 'rest_pre_dispatch', array( 'MAD4B_SCP_MCP_Registration_Rescue', 'before_rest_dispatch' ) ) ) $fail( 'independent pre-dispatch rescue was lost' );

// WordPress 6.9 REST route creation expects this Core admin class to be loaded.
if ( ! class_exists( 'WP_Site_Health', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
}
rest_get_server();

$bridge_after_rest = MAD4B_SCP_MCP_Registration_Bridge::status();
$rescue_after_rest = MAD4B_SCP_MCP_Registration_Rescue::status();
if ( 1 !== (int) ( isset( $bridge_after_rest['rest_api_init_count'] ) ? $bridge_after_rest['rest_api_init_count'] : 0 ) ) $fail( 'rest_api_init must execute exactly once during REST bootstrap' );
if ( 0 !== (int) ( isset( $bridge_after_rest['mcp_adapter_init_count'] ) ? $bridge_after_rest['mcp_adapter_init_count'] : -1 ) ) $fail( 'Adapter initialized before independent fallback dispatch' );
if ( ! empty( $rescue_after_rest['attempted'] ) ) $fail( 'rescue should not run after REST tail callbacks were suppressed' );

// Dispatch an actual REST request. rest_pre_dispatch runs before route matching,
// so the fresh rescue can install the missing MCP routes for this same request.
$request = new WP_REST_Request( 'GET', '/mcp/mad4b-read' );
rest_do_request( $request );

$bridge = MAD4B_SCP_MCP_Registration_Bridge::status();
$rescue = MAD4B_SCP_MCP_Registration_Rescue::status();
if ( empty( $rescue['attempted'] ) ) $fail( 'pre-dispatch rescue was not attempted' );
if ( empty( $rescue['succeeded'] ) ) $fail( 'pre-dispatch rescue failed: ' . ( isset( $rescue['blocker'] ) ? $rescue['blocker'] : 'unknown' ) );
if ( 'rest_pre_dispatch_rescue' !== ( isset( $rescue['trigger'] ) ? $rescue['trigger'] : '' ) ) $fail( 'unexpected rescue trigger' );
if ( 'registration_lifecycle_recovered' !== ( isset( $rescue['state'] ) ? $rescue['state'] : '' ) ) $fail( 'unexpected rescue state' );
if ( ! empty( $rescue['blocker'] ) ) $fail( 'rescue blocker: ' . $rescue['blocker'] );
if ( 1 !== (int) ( isset( $bridge['rest_api_init_count'] ) ? $bridge['rest_api_init_count'] : 0 ) ) $fail( 'rest_api_init must remain exactly one; global replay is forbidden' );
if ( 1 !== (int) ( isset( $bridge['mcp_adapter_init_count'] ) ? $bridge['mcp_adapter_init_count'] : 0 ) ) $fail( 'official mcp_adapter_init must fire exactly once during rescue' );
if ( empty( $bridge['adapter_runtime_from_official_plugin'] ) ) $fail( 'runtime is not owned by official MCP Adapter' );
if ( '0.6.1' !== ( isset( $bridge['adapter_runtime_version'] ) ? (string) $bridge['adapter_runtime_version'] : '' ) ) $fail( 'unexpected MCP Adapter runtime version' );

$expected = MAD4B_SCP_Servers::expected_server_ids();
if ( count( $expected ) !== (int) ( isset( $rescue['route_count'] ) ? $rescue['route_count'] : 0 ) ) $fail( 'targeted route recovery count mismatch' );

$registrations = MAD4B_SCP_Servers::registration_status();
foreach ( $expected as $server_id ) {
	if ( empty( $registrations[ $server_id ]['registered'] ) ) $fail( $server_id . ' not registered after pre-dispatch rescue' );
	if ( ! empty( $registrations[ $server_id ]['error'] ) ) $fail( $server_id . ' registration error: ' . $registrations[ $server_id ]['error'] );
}

$status = MAD4B_SCP_Connection_Status::status();
if ( empty( $status['local_transport_ready'] ) ) $fail( 'local transport remains blocked: ' . wp_json_encode( $status['local_blockers'] ) );
if ( empty( $status['servers'] ) || ! is_array( $status['servers'] ) ) $fail( 'connection server inventory missing' );
foreach ( $status['servers'] as $server ) {
	$id = isset( $server['server_id'] ) ? (string) $server['server_id'] : 'unknown';
	if ( empty( $server['registered'] ) ) $fail( $id . ' connection snapshot says not registered' );
	if ( empty( $server['route_registered'] ) ) $fail( $id . ' REST route not registered' );
	if ( empty( $server['permission_callback_match'] ) ) $fail( $id . ' permission binding mismatch' );
}

echo 'mad4b.site-control-plane.mcp-web-postcondition-recovery.v2: PASS' . PHP_EOL;
