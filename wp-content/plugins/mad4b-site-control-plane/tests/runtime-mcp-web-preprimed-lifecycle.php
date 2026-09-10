<?php
/**
 * Web lifecycle acceptance when another earlier MU component has already
 * created the WordPress REST server before the managed MAD4B MU bootstrap.
 */

$wp_path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $wp_path ) || '' === trim( $wp_path ) ) {
	fwrite( STDERR, "FAIL mcp-web-preprimed-lifecycle: MAD4B_TEST_WP_PATH is required\n" );
	exit( 1 );
}
$wp_path = rtrim( $wp_path, '/\\' );
if ( ! is_file( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "FAIL mcp-web-preprimed-lifecycle: wp-load.php not found\n" );
	exit( 1 );
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	fwrite( STDERR, "FAIL mcp-web-preprimed-lifecycle: WP_CLI must remain undefined/false\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-read';

require $wp_path . '/wp-load.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL mcp-web-preprimed-lifecycle: ' . $message . PHP_EOL );
	exit( 1 );
};

if ( defined( 'WP_CLI' ) && WP_CLI ) $fail( 'process unexpectedly entered WP-CLI mode' );
if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) $fail( 'canonical MCP Adapter class unavailable' );
if ( ! class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) ) $fail( 'MAD4B registration bridge unavailable' );
if ( ! class_exists( 'MAD4B_SCP_Connection_Status' ) ) $fail( 'connection status unavailable' );
if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) $fail( 'MAD4B server registry unavailable' );

$bridge = MAD4B_SCP_MCP_Registration_Bridge::status();
if ( empty( $bridge['rest_init_seen_before_bridge_boot'] ) ) $fail( 'REST prime was not observed before bridge boot' );
if ( ! empty( $bridge['adapter_init_seen_before_bridge_boot'] ) ) $fail( 'adapter must not initialize before bridge boot' );
if ( empty( $bridge['missed_rest_recovery_scheduled'] ) ) $fail( 'missed REST recovery was not scheduled' );
if ( empty( $bridge['missed_rest_recovery_attempted'] ) ) $fail( 'missed REST recovery was not attempted' );
if ( empty( $bridge['missed_rest_recovery_succeeded'] ) ) $fail( 'missed REST recovery did not succeed: ' . ( isset( $bridge['missed_rest_recovery_blocker'] ) ? $bridge['missed_rest_recovery_blocker'] : 'unknown' ) );
if ( 'missed_rest_lifecycle_recovered' !== ( isset( $bridge['missed_rest_recovery_state'] ) ? $bridge['missed_rest_recovery_state'] : '' ) ) $fail( 'unexpected recovery state' );
if ( ! empty( $bridge['missed_rest_recovery_blocker'] ) ) $fail( 'recovery blocker: ' . $bridge['missed_rest_recovery_blocker'] );

// The recovery must not replay WordPress' global REST initialization action.
if ( 1 !== (int) ( isset( $bridge['rest_api_init_count'] ) ? $bridge['rest_api_init_count'] : 0 ) ) $fail( 'rest_api_init must remain exactly one; global replay is forbidden' );
if ( 1 !== (int) ( isset( $bridge['mcp_adapter_init_count'] ) ? $bridge['mcp_adapter_init_count'] : 0 ) ) $fail( 'official mcp_adapter_init must fire exactly once' );
if ( (int) ( isset( $bridge['abilities_init_count'] ) ? $bridge['abilities_init_count'] : 0 ) < 1 ) $fail( 'Abilities API did not initialize' );
if ( empty( $bridge['adapter_runtime_from_official_plugin'] ) ) $fail( 'runtime is not owned by official MCP Adapter' );
if ( '0.6.1' !== ( isset( $bridge['adapter_runtime_version'] ) ? (string) $bridge['adapter_runtime_version'] : '' ) ) $fail( 'unexpected MCP Adapter runtime version' );

$expected = MAD4B_SCP_Servers::expected_server_ids();
if ( count( $expected ) !== (int) ( isset( $bridge['missed_rest_recovery_route_count'] ) ? $bridge['missed_rest_recovery_route_count'] : 0 ) ) {
	$fail( 'targeted recovered route count does not match expected server count' );
}
if ( ! is_object( wp_get_ability( 'mad4b/site-info' ) ) ) $fail( 'read ability sentinel missing' );
if ( ! is_object( wp_get_ability( 'mad4b/content-update-post' ) ) $fail( 'write ability sentinel missing' );

$registrations = MAD4B_SCP_Servers::registration_status();
foreach ( $expected as $server_id ) {
	if ( empty( $registrations[ $server_id ]['registered'] ) ) $fail( $server_id . ' not registered after bounded missed-REST recovery' );
	if ( ! empty( $registrations[ $server_id ]['error'] ) ) $fail( $server_id . ' registration error: ' . $registrations[ $server_id ]['error'] );
}

$status = MAD4B_SCP_Connection_Status::status();
if ( empty( $status['local_transport_ready'] ) ) $fail( 'connection status still reports local transport blocked: ' . wp_json_encode( $status['local_blockers'] ) );
if ( empty( $status['servers'] ) || ! is_array( $status['servers'] ) ) $fail( 'connection server inventory missing' );
foreach ( $status['servers'] as $server ) {
	$id = isset( $server['server_id'] ) ? (string) $server['server_id'] : 'unknown';
	if ( empty( $server['registered'] ) ) $fail( $id . ' connection snapshot says not registered' );
	if ( empty( $server['route_registered'] ) ) $fail( $id . ' REST route not registered' );
	if ( empty( $server['permission_callback_match'] ) ) $fail( $id . ' permission binding mismatch' );
}

echo 'mad4b.site-control-plane.mcp-web-preprimed-lifecycle.v1: PASS' . PHP_EOL;
