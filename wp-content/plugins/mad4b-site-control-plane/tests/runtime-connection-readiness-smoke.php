<?php
/** Runtime proof for read-only local connection truth and admin rendering. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

$check( current_user_can( 'manage_options' ), 'Connection readiness smoke requires an administrator.' );
$check( class_exists( 'MAD4B_SCP_Connection_Status' ), 'Connection status class unavailable.' );
$check( class_exists( 'MAD4B_SCP_Connection_Admin_UI' ), 'Connection admin UI class unavailable.' );
$check( class_exists( 'MAD4B_SCP_Transport_Context' ), 'Transport context class unavailable.' );
$check( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/connection-status' ), 'Connection status ability is not registered.' );
$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', 'mad4b/connection-status' ), 'Connection status ability is not mounted on mad4b-read.' );

$status = MAD4B_SCP_Connection_Status::status();
$check( isset( $status['contract'] ) && 'mad4b.connection-readiness.v2' === $status['contract'], 'Unexpected connection readiness contract.' );
$check( ! empty( $status['local_transport_ready'] ), 'Clean local transport should be ready: ' . wp_json_encode( $status['local_blockers'] ) );
$check( empty( $status['remote_endpoint_preflight_ready'] ), 'HTTP CI target must not claim remote endpoint preflight readiness.' );
$check( in_array( 'https_required_for_remote_mcp', $status['remote_preflight_blockers'], true ), 'HTTP CI target did not report HTTPS remote blocker.' );
$check( empty( $status['connection_certified'] ), 'Local inspection must never self-certify the external connection.' );
$check( empty( $status['external_handshake']['verified'] ), 'External handshake was incorrectly marked verified.' );
$check( in_array( 'external_handshake_unverified', $status['certification_blockers'], true ), 'External handshake blocker missing.' );
$check( empty( $status['authentication']['credential_material_exposed'] ), 'Connection status claims credential material is exposed.' );
$check( empty( $status['authentication']['credential_creation_supported_here'] ), 'Connection status claims credential creation in read-only surface.' );

$expected = MAD4B_SCP_Servers::expected_server_ids();
$check( count( $status['servers'] ) === count( $expected ), 'MAD4B server count drifted from the server registry.' );
$check( in_array( 'mad4b-write', $expected, true ), 'mad4b-write is missing from the governed server registry.' );
$seen = array();
foreach ( $status['servers'] as $server ) {
    $seen[] = $server['server_id'];
    $check( ! empty( $server['registered'] ), 'MAD4B server not registered: ' . $server['server_id'] );
    $check( ! empty( $server['route_registered'] ), 'MAD4B REST route not registered: ' . $server['server_id'] );
    $check( ! empty( $server['permission_callback_match'] ), 'MAD4B transport permission callback mismatch: ' . $server['server_id'] );
    $path = wp_parse_url( $server['endpoint'], PHP_URL_PATH );
    $check( '/wp-json/mcp/' . $server['server_id'] === $path, 'Unexpected endpoint path for ' . $server['server_id'] . ': ' . $path );
}
sort( $seen ); sort( $expected );
$check( $seen === $expected, 'MAD4B endpoint inventory mismatch.' );
$check( ! empty( $status['write_surface']['registered'] ), 'Write surface is not registered.' );
$check( ! empty( $status['write_surface']['route_registered'] ), 'Write surface REST route is not registered.' );
$check( ! empty( $status['write_surface']['permission_callback_match'] ), 'Write surface transport permission callback is not exact.' );
$check( ! empty( $status['write_surface']['exact_transport_grant_required'] ), 'Write surface does not report exact transport grant binding.' );
$check( empty( $status['write_surface']['generic_dispatcher_exposed'] ), 'Write surface unexpectedly reports a generic dispatcher.' );
$check( (int) $status['write_surface']['mounted_write_tool_count'] > 0, 'Write surface mounted no explicitly non-readonly tools.' );
$check( empty( $status['mcp_peer_governance']['foreign_transport']['detected'] ), 'Clean CI unexpectedly detected a foreign MCP transport.' );
$check( has_action( 'admin_menu', array( 'MAD4B_SCP_Connection_Admin_UI', 'register_menu' ) ) !== false, 'Connection admin submenu hook missing.' );

$tables = MAD4B_SCP_Schema::tables();
global $wpdb;
$before = array(
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['agents']}" ),
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['approvals']}" ),
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['mutations']}" ),
);
ob_start();
MAD4B_SCP_Connection_Admin_UI::render_page();
$html = ob_get_clean();
$check( false !== strpos( $html, 'MAD4B Connection' ), 'Connection admin page did not render.' );
$check( false !== strpos( $html, '/wp-json/mcp/mad4b-read' ), 'Connection admin page omitted the read endpoint.' );
$check( false !== strpos( $html, '/wp-json/mcp/mad4b-write' ), 'Connection admin page omitted the write endpoint.' );
$check( false !== strpos( $html, 'external_handshake_unverified' ), 'Connection admin page omitted external-handshake truth.' );
foreach ( array( 'client_secret', 'access_token', 'refresh_token', 'authorization_header', 'rollback_payload' ) as $secret ) $check( false === stripos( $html, $secret ), 'Connection admin page exposed forbidden material: ' . $secret );
$after = array(
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['agents']}" ),
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['approvals']}" ),
    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['mutations']}" ),
);
$check( $before === $after, 'Read-only connection rendering changed governance state.' );

echo "mad4b.site-control-plane.runtime-connection-readiness.v2: PASS\n";
