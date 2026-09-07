<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function mad4b_isolation_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

if ( ! defined( 'MAD4B_MCP_PROVIDER_ISOLATION_ENABLED' ) || true !== MAD4B_MCP_PROVIDER_ISOLATION_ENABLED ) {
	mad4b_isolation_fail( 'Isolation flag must be enabled by wp-config for this runtime proof.' );
}
if ( 'staging' !== wp_get_environment_type() ) {
	mad4b_isolation_fail( 'Runtime proof must execute in staging environment.', wp_get_environment_type() );
}
if ( ! MAD4B_SCP_MCP_Provider_Isolation::effective() ) {
	mad4b_isolation_fail( 'Provider MCP isolation should be effective on staging.' );
}

add_action( 'rest_api_init', function () {
	$permission = function () { return true; };
	$callback = function () { return rest_ensure_response( array( 'ok' => true ) ); };
	register_rest_route( 'fluentform/v1', '/mcp/status', array( 'methods' => 'GET', 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'jet-engine/v1', '/mcp', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'hfe/v1', '/mcp-settings', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'elementskit/v1', '/mcp-proxy', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );
	// Deliberately unknown MCP-looking route: isolation must NOT hide it.
	register_rest_route( 'unknown-provider/v1', '/mcp-unreviewed', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );
}, 1000 );

$rest = rest_get_server();
$routes = $rest->get_routes();

foreach ( array(
	'/fluentform/v1/mcp/status',
	'/jet-engine/v1/mcp',
	'/hfe/v1/mcp-settings',
	'/elementskit/v1/mcp-proxy',
) as $route ) {
	if ( isset( $routes[ $route ] ) ) mad4b_isolation_fail( 'Certified provider MCP route remained exposed.', $route );
}
if ( ! isset( $routes['/unknown-provider/v1/mcp-unreviewed'] ) ) {
	mad4b_isolation_fail( 'Unknown MCP route was hidden instead of remaining fail-closed.' );
}

$status = MAD4B_SCP_MCP_Provider_Isolation::status();
if ( empty( $status['effective'] ) || empty( $status['default_server_suppressed'] ) ) {
	mad4b_isolation_fail( 'Isolation status did not report effective/default suppression.', $status );
}
if ( (int) $status['removed_route_count'] < 4 ) {
	mad4b_isolation_fail( 'Expected provider routes were not recorded as isolated.', $status );
}
if ( empty( $status['unknown_routes_fail_closed'] ) || ! empty( $status['changes_provider_settings'] ) || ! empty( $status['creates_authority'] ) ) {
	mad4b_isolation_fail( 'Isolation safety metadata is invalid.', $status );
}

if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
	$adapter = \WP\MCP\Core\McpAdapter::instance();
	$servers = method_exists( $adapter, 'get_servers' ) ? $adapter->get_servers() : array();
	foreach ( is_array( $servers ) ? $servers : array() as $server ) {
		if ( is_object( $server ) && method_exists( $server, 'get_server_id' ) && 'mcp-adapter-default-server' === $server->get_server_id() ) {
			mad4b_isolation_fail( 'Official default MCP server remained registered while isolation is effective.' );
		}
	}
}

$peer = MAD4B_SCP_MCP_Peer_Governance::status();
$foreign = isset( $peer['foreign_transport_inventory'] ) && is_array( $peer['foreign_transport_inventory'] ) ? $peer['foreign_transport_inventory'] : array();
$foreign_routes = isset( $foreign['foreign_routes'] ) && is_array( $foreign['foreign_routes'] ) ? $foreign['foreign_routes'] : array();
if ( ! in_array( '/unknown-provider/v1/mcp-unreviewed', $foreign_routes, true ) ) {
	mad4b_isolation_fail( 'Unknown MCP route did not remain visible to peer governance.', $peer );
}
if ( empty( $peer['write_side_channel_detected'] ) || ! in_array( 'mcp_foreign_transport_unreviewed', isset( $peer['blockers'] ) ? $peer['blockers'] : array(), true ) ) {
	mad4b_isolation_fail( 'Unknown MCP route must continue to fail closed.', $peer );
}

fwrite( STDOUT, 'mad4b.site-control-plane.runtime-mcp-provider-isolation.v1: PASS' . PHP_EOL );
