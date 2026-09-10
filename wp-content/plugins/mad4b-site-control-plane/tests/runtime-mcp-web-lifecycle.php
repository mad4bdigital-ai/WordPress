<?php
/**
 * Non-WPCLI lifecycle acceptance for the canonical MCP Adapter web path.
 *
 * Run as a plain PHP process with MAD4B_TEST_WP_PATH pointing at a disposable
 * WordPress root. The process deliberately leaves WP_CLI undefined so the MCP
 * Adapter must arm and execute its rest_api_init lifecycle, matching HTTP/admin
 * requests rather than the WP-CLI init path.
 */

$wp_path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $wp_path ) || '' === trim( $wp_path ) ) {
	fwrite( STDERR, "FAIL mcp-web-lifecycle: MAD4B_TEST_WP_PATH is required\n" );
	exit( 1 );
}
$wp_path = rtrim( $wp_path, '/\\' );
if ( ! is_file( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "FAIL mcp-web-lifecycle: wp-load.php not found\n" );
	exit( 1 );
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	fwrite( STDERR, "FAIL mcp-web-lifecycle: WP_CLI must remain undefined/false\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-read';

require $wp_path . '/wp-load.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL mcp-web-lifecycle: ' . $message . PHP_EOL );
	exit( 1 );
};

if ( defined( 'WP_CLI' ) && WP_CLI ) $fail( 'process unexpectedly entered WP-CLI mode' );
if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) $fail( 'canonical MCP Adapter class unavailable' );
if ( ! class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) ) $fail( 'MAD4B registration bridge unavailable' );
if ( ! class_exists( 'MAD4B_SCP_Connection_Status' ) ) $fail( 'connection status unavailable' );
if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) $fail( 'MAD4B server registry unavailable' );

$mu = isset( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] ) && is_array( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] ) ? $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] : array();
if ( empty( $mu['executed'] ) ) $fail( 'managed MU bootstrap did not execute' );
if ( 'canonical_runtime_pinned_adapter_hook_armed' !== ( isset( $mu['state'] ) ? $mu['state'] : '' ) ) $fail( 'managed MU bootstrap did not arm canonical web lifecycle' );
if ( empty( $mu['runtime_from_official_plugin'] ) ) $fail( 'MU runtime owner is not official MCP Adapter' );
if ( empty( $mu['adapter_instance_armed'] ) ) $fail( 'MCP Adapter singleton was not armed' );
if ( 'rest_api_init' !== ( isset( $mu['adapter_init_hook'] ) ? $mu['adapter_init_hook'] : '' ) ) $fail( 'non-WPCLI process must arm rest_api_init' );
if ( empty( $mu['adapter_init_hook_bound'] ) ) $fail( 'MCP Adapter rest_api_init callback is not bound' );

$before = MAD4B_SCP_MCP_Registration_Bridge::status();
if ( ! empty( $before['adapter_init_seen_before_bridge_boot'] ) ) $fail( 'adapter initialized before MAD4B bridge binding' );

// Trigger WordPress' canonical lazy REST bootstrap. This must execute the MCP
// Adapter callback at priority 15 and then route registration at priority 16.
$rest_server = rest_get_server();
if ( ! is_object( $rest_server ) ) $fail( 'REST server unavailable' );

$after = MAD4B_SCP_MCP_Registration_Bridge::status();
if ( (int) ( isset( $after['rest_api_init_count'] ) ? $after['rest_api_init_count'] : 0 ) < 1 ) $fail( 'rest_api_init did not fire' );
if ( (int) ( isset( $after['mcp_adapter_init_count'] ) ? $after['mcp_adapter_init_count'] : 0 ) < 1 ) $fail( 'mcp_adapter_init did not fire on web lifecycle' );
if ( empty( $after['adapter_runtime_from_official_plugin'] ) ) $fail( 'runtime is not owned by official MCP Adapter' );
if ( '0.6.1' !== ( isset( $after['adapter_runtime_version'] ) ? (string) $after['adapter_runtime_version'] : '' ) ) $fail( 'unexpected MCP Adapter runtime version' );

$registrations = MAD4B_SCP_Servers::registration_status();
foreach ( MAD4B_SCP_Servers::expected_server_ids() as $server_id ) {
	if ( empty( $registrations[ $server_id ]['registered'] ) ) $fail( $server_id . ' not registered after web rest_api_init' );
	if ( ! empty( $registrations[ $server_id ]['error'] ) ) $fail( $server_id . ' registration error: ' . $registrations[ $server_id ]['error'] );
}

$status = MAD4B_SCP_Connection_Status::status();
if ( empty( $status['local_transport_ready'] ) ) $fail( 'connection status still reports local transport blocked after canonical web bootstrap: ' . wp_json_encode( $status['local_blockers'] ) );
if ( empty( $status['servers'] ) || ! is_array( $status['servers'] ) ) $fail( 'connection server inventory missing' );
foreach ( $status['servers'] as $server ) {
	$id = isset( $server['server_id'] ) ? (string) $server['server_id'] : 'unknown';
	if ( empty( $server['registered'] ) ) $fail( $id . ' connection snapshot says not registered' );
	if ( empty( $server['route_registered'] ) ) $fail( $id . ' REST route not registered' );
	if ( empty( $server['permission_callback_match'] ) ) $fail( $id . ' permission binding mismatch' );
}

echo 'mad4b.site-control-plane.mcp-web-lifecycle.v1: PASS' . PHP_EOL;
