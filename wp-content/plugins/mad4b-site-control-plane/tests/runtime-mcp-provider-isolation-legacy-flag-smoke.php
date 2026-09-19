<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function mad4b_isolation_legacy_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

if ( ! defined( 'MAD4B_MCP_PROVIDER_ISOLATION_ENABLED' ) || true !== MAD4B_MCP_PROVIDER_ISOLATION_ENABLED ) {
	mad4b_isolation_legacy_fail( 'Legacy isolation intent flag must be enabled for this regression proof.' );
}
if ( defined( 'MAD4B_MCP_PROVIDER_ISOLATION_RUNTIME_SUPPRESSION_APPROVED' ) && true === MAD4B_MCP_PROVIDER_ISOLATION_RUNTIME_SUPPRESSION_APPROVED ) {
	mad4b_isolation_legacy_fail( 'Runtime suppression approval must remain absent for this regression proof.' );
}
if ( 'staging' !== wp_get_environment_type() ) {
	mad4b_isolation_legacy_fail( 'Runtime proof must execute in staging environment.', wp_get_environment_type() );
}
if ( MAD4B_SCP_MCP_Provider_Isolation::effective() ) {
	mad4b_isolation_legacy_fail( 'Legacy isolation intent flag alone must not make runtime suppression effective.' );
}
if ( true !== apply_filters( 'wpmedia_mcp_oauth_server_enabled', true ) ) {
	mad4b_isolation_legacy_fail( 'Legacy isolation intent flag alone suppressed the wp-media MCP OAuth server.' );
}
if ( true !== apply_filters( 'mcp_adapter_create_default_server', true ) ) {
	mad4b_isolation_legacy_fail( 'Legacy isolation intent flag alone suppressed the MCP Adapter default server.' );
}

require_once __DIR__ . '/fixtures/provider-mcp-registration-callbacks.php';

$hostinger = new \Hostinger\AiAssistant\Mcp\McpServer();
$elementskit = new \ElementsKit_Lite\Mcp\Server();
add_action( 'mcp_adapter_init', array( $hostinger, 'create_server' ), 10 );
add_action( 'mcp_adapter_init', array( $elementskit, 'register_server' ), 10 );

MAD4B_SCP_MCP_Provider_Isolation::suppress_provider_server_registrations();
if ( false === has_action( 'mcp_adapter_init', array( $hostinger, 'create_server' ) ) ) {
	mad4b_isolation_legacy_fail( 'Legacy isolation intent flag alone removed the Hostinger MCP registration callback.' );
}
if ( false === has_action( 'mcp_adapter_init', array( $elementskit, 'register_server' ) ) ) {
	mad4b_isolation_legacy_fail( 'Legacy isolation intent flag alone removed the ElementsKit MCP registration callback.' );
}
remove_action( 'mcp_adapter_init', array( $hostinger, 'create_server' ), 10 );
remove_action( 'mcp_adapter_init', array( $elementskit, 'register_server' ), 10 );

add_action( 'rest_api_init', function () {
	$permission = function () { return true; };
	$callback = function () { return rest_ensure_response( array( 'ok' => true ) ); };
	register_rest_route( 'hostinger-ai-assistant/v1', '/mcp', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'jet-engine/v1', '/mcp', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'elementskit', '/mcp', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
}, 1000 );

$routes = rest_get_server()->get_routes();
foreach ( array(
	'/hostinger-ai-assistant/v1/mcp',
	'/jet-engine/v1/mcp',
	'/elementskit/mcp',
) as $route ) {
	if ( ! isset( $routes[ $route ] ) ) {
		mad4b_isolation_legacy_fail( 'Legacy isolation intent flag alone hid a reviewed provider REST route.', $route );
	}
}

$status = MAD4B_SCP_MCP_Provider_Isolation::status();
if ( empty( $status['configured'] ) || ! empty( $status['runtime_suppression_approved'] ) || ! empty( $status['effective'] ) ) {
	mad4b_isolation_legacy_fail( 'Isolation status does not prove legacy intent-only fail-open behavior.', $status );
}
if ( empty( $status['legacy_enable_flag_alone_is_non_mutating'] ) || empty( $status['runtime_suppression_requires_second_gate'] ) ) {
	mad4b_isolation_legacy_fail( 'Isolation status is missing the second-gate safety evidence.', $status );
}
if ( ! empty( $status['default_server_suppressed'] ) || ! empty( $status['wpmedia_oauth_server_suppressed'] ) || ! empty( $status['server_registration_suppression_attempted'] ) || 0 !== (int) $status['suppressed_server_count'] || 0 !== (int) $status['removed_route_count'] ) {
	mad4b_isolation_legacy_fail( 'Legacy isolation intent flag alone produced suppression side effects.', $status );
}

fwrite( STDOUT, 'mad4b.site-control-plane.runtime-mcp-provider-isolation-legacy-flag.v1: PASS' . PHP_EOL );
