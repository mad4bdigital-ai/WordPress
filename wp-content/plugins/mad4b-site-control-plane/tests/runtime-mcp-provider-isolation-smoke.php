<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

// Historical evidence marker for Spec Kit migration tracking only:
// mad4b.site-control-plane.runtime-mcp-provider-isolation.v1

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
if ( false !== apply_filters( 'wpmedia_mcp_oauth_server_enabled', true ) ) {
	mad4b_isolation_fail( 'Official wp-media/mcp-oauth kill switch did not suppress mcp-oauth-server.' );
}

require_once __DIR__ . '/fixtures/provider-mcp-registration-callbacks.php';

$hostinger = new \Hostinger\AiAssistant\Mcp\McpServer();
$elementskit = new \ElementsKit_Lite\Mcp\Server();
$unknown = new MAD4B_Isolation_Unknown_Server_Callback();
add_action( 'mcp_adapter_init', array( $hostinger, 'create_server' ), 10 );
add_action( 'mcp_adapter_init', array( $elementskit, 'register_server' ), 10 );
add_action( 'mcp_adapter_init', array( $unknown, 'register_server' ), 10 );

MAD4B_SCP_MCP_Provider_Isolation::suppress_provider_server_registrations();
if ( false !== has_action( 'mcp_adapter_init', array( $hostinger, 'create_server' ) ) ) {
	mad4b_isolation_fail( 'Hostinger MCP server registration callback was not suppressed.' );
}
if ( false !== has_action( 'mcp_adapter_init', array( $elementskit, 'register_server' ) ) ) {
	mad4b_isolation_fail( 'ElementsKit MCP server registration callback was not suppressed.' );
}
if ( false === has_action( 'mcp_adapter_init', array( $unknown, 'register_server' ) ) ) {
	mad4b_isolation_fail( 'Unknown server callback was removed instead of remaining fail-closed.' );
}
remove_action( 'mcp_adapter_init', array( $unknown, 'register_server' ), 10 );

add_action( 'rest_api_init', function () {
	$permission = function () { return true; };
	$callback = function () { return rest_ensure_response( array( 'ok' => true ) ); };
	register_rest_route( 'hostinger-ai-assistant/v1', '/mcp', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'hostinger-ai-assistant/v1', '/jwt/token', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'hostinger-ai-assistant/v1', '/jwt/revoke', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'fluentform/v1', '/mcp/status', array( 'methods' => 'GET', 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'jet-engine/v1', '/mcp', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'hfe/v1', '/mcp-settings', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'elementskit', '/mcp', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'elementskit/v1', '/mcp-proxy', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );

	// Reviewed Hostinger UI/control endpoint. It must remain registered and be
	// classified as non-transport by peer governance, not deleted by isolation.
	register_rest_route( 'hostinger-easy-onboarding/v1', '/update-mcp-connector-banner-status', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );

	// Deliberately unknown MCP-looking route: isolation must NOT hide it.
	register_rest_route( 'unknown-provider/v1', '/mcp-unreviewed', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );
}, 1000 );

$rest = rest_get_server();
$routes = $rest->get_routes();

foreach ( array(
	'/hostinger-ai-assistant/v1/mcp',
	'/hostinger-ai-assistant/v1/jwt/token',
	'/hostinger-ai-assistant/v1/jwt/revoke',
	'/fluentform/v1/mcp/status',
	'/jet-engine/v1/mcp',
	'/hfe/v1/mcp-settings',
	'/elementskit/mcp',
	'/elementskit/v1/mcp-proxy',
) as $route ) {
	if ( isset( $routes[ $route ] ) ) mad4b_isolation_fail( 'Certified provider MCP/control route remained exposed.', $route );
}
if ( ! isset( $routes['/hostinger-easy-onboarding/v1/update-mcp-connector-banner-status'] ) ) {
	mad4b_isolation_fail( 'Reviewed Hostinger banner-control route was hidden instead of preserved.' );
}
if ( ! isset( $routes['/unknown-provider/v1/mcp-unreviewed'] ) ) {
	mad4b_isolation_fail( 'Unknown MCP route was hidden instead of remaining fail-closed.' );
}

$status = MAD4B_SCP_MCP_Provider_Isolation::status();
if ( empty( $status['effective'] ) || empty( $status['default_server_suppressed'] ) || empty( $status['wpmedia_oauth_server_suppressed'] ) ) {
	mad4b_isolation_fail( 'Isolation status did not report effective/default/WP Media suppression.', $status );
}
if ( empty( $status['server_registration_suppression_attempted'] ) ) {
	mad4b_isolation_fail( 'Server-registration suppression was not attempted.', $status );
}
if ( (int) $status['suppressed_server_count'] < 2 ) {
	mad4b_isolation_fail( 'Expected exact provider server callbacks were not recorded as suppressed.', $status );
}
foreach ( array( 'hostinger-ai-assistant-mcp-server', 'elementskit-mcp-server' ) as $server_id ) {
	if ( ! in_array( $server_id, $status['suppressed_server_ids'], true ) ) {
		mad4b_isolation_fail( 'Expected provider server id missing from suppression evidence.', array( $server_id, $status ) );
	}
}
if ( (int) $status['removed_route_count'] < 8 ) {
	mad4b_isolation_fail( 'Expected provider routes were not recorded as isolated.', $status );
}
if ( empty( $status['unknown_routes_fail_closed'] ) || empty( $status['unknown_server_callbacks_fail_closed'] ) || ! empty( $status['changes_provider_settings'] ) || ! empty( $status['disables_provider_plugins'] ) || ! empty( $status['creates_authority'] ) ) {
	mad4b_isolation_fail( 'Isolation safety metadata is invalid.', $status );
}

if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
	$adapter = \WP\MCP\Core\McpAdapter::instance();
	$servers = method_exists( $adapter, 'get_servers' ) ? $adapter->get_servers() : array();
	foreach ( is_array( $servers ) ? $servers : array() as $server ) {
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) continue;
		$id = (string) $server->get_server_id();
		if ( in_array( $id, array( 'mcp-adapter-default-server', 'mcp-oauth-server', 'hostinger-ai-assistant-mcp-server', 'elementskit-mcp-server' ), true ) ) {
			mad4b_isolation_fail( 'Suppressed MCP server remained registered while isolation is effective.', $id );
		}
	}
}

$peer = MAD4B_SCP_MCP_Peer_Governance::status();
$foreign = isset( $peer['foreign_transport_inventory'] ) && is_array( $peer['foreign_transport_inventory'] ) ? $peer['foreign_transport_inventory'] : array();
$foreign_routes = isset( $foreign['foreign_routes'] ) && is_array( $foreign['foreign_routes'] ) ? $foreign['foreign_routes'] : array();
$reviewed_routes = isset( $foreign['reviewed_non_transport_routes'] ) && is_array( $foreign['reviewed_non_transport_routes'] ) ? $foreign['reviewed_non_transport_routes'] : array();
$hostinger_banner = '/hostinger-easy-onboarding/v1/update-mcp-connector-banner-status';
if ( ! in_array( $hostinger_banner, $reviewed_routes, true ) || in_array( $hostinger_banner, $foreign_routes, true ) ) {
	mad4b_isolation_fail( 'Hostinger banner-control route was not classified as reviewed non-transport.', $foreign );
}
if ( ! in_array( '/unknown-provider/v1/mcp-unreviewed', $foreign_routes, true ) ) {
	mad4b_isolation_fail( 'Unknown MCP route did not remain visible to peer governance.', $peer );
}
if ( empty( $peer['write_side_channel_detected'] ) || ! in_array( 'mcp_foreign_transport_unreviewed', isset( $peer['blockers'] ) ? $peer['blockers'] : array(), true ) ) {
	mad4b_isolation_fail( 'Unknown MCP route must continue to fail closed.', $peer );
}

fwrite( STDOUT, 'mad4b.site-control-plane.runtime-mcp-provider-isolation.v3: PASS' . PHP_EOL );
