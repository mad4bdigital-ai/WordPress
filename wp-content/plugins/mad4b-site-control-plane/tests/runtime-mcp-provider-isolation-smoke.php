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
	register_rest_route( 'jet-engine/v1', '/mcp-tools', array(
		'methods' => 'GET',
		'callback' => function () {
			return rest_ensure_response( array( 'tools' => array(
				array(
					'name' => 'resource-get-configuration',
					'title' => 'Get JetEngine Configuration',
					'description' => 'Retrieve the provider configuration.',
					'annotations' => array( 'readOnlyHint' => true ),
					'inputSchema' => array(),
				),
				array(
					'name' => 'resource-get-website-config',
					'title' => 'Get JetEngine Website Config',
					'description' => 'Get JetEngine website config through the isolated native provider bridge.',
					'annotations' => array( 'readOnlyHint' => true ),
					'inputSchema' => array(),
				),
				array(
					'name' => 'tool-add-meta-box',
					'title' => 'Add Meta Box',
					'description' => 'Create a meta box from configuration.',
					'annotations' => array( 'readOnlyHint' => false ),
					'inputSchema' => array( 'type' => 'object', 'additionalProperties' => true ),
				),
				array(
					'name' => 'tool-add-query',
					'title' => 'Add Query',
					'description' => 'Create a JetEngine query.',
					'annotations' => array( 'readOnlyHint' => false ),
					'inputSchema' => array( 'type' => 'object', 'additionalProperties' => true ),
				),
				array(
					'name' => 'tool-add-listing',
					'title' => 'Add Listing',
					'description' => 'Create a listing backed by a query.',
					'annotations' => array( 'readOnlyHint' => false ),
					'inputSchema' => array( 'type' => 'object', 'additionalProperties' => true ),
				),
			) ) );
		},
		'permission_callback' => $permission,
	) );
	register_rest_route( 'jet-engine/v1', '/mcp-tools/run/(?P<tool>[a-zA-Z0-9\-\/]+?)', array(
		'methods' => 'POST',
		'callback' => function ( $request ) {
			return rest_ensure_response( array(
				'ok' => true,
				'tool' => (string) $request->get_param( 'tool' ),
				'input' => (array) $request->get_param( 'input' ),
			) );
		},
		'permission_callback' => $permission,
		'args' => array(
			'input' => array( 'type' => 'object', 'required' => false, 'default' => array() ),
		),
	) );
	register_rest_route( 'hfe/v1', '/mcp-settings', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'elementskit', '/mcp', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'elementskit/v1', '/mcp-proxy', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );

	// Reviewed Hostinger UI/control endpoint. It must remain registered and be
	// classified as non-transport by peer governance, not deleted by isolation.
	register_rest_route( 'hostinger-easy-onboarding/v1', '/update-mcp-connector-banner-status', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );

	// Deliberately unknown MCP-looking route: isolation must NOT hide it.
	register_rest_route( 'unknown-provider/v1', '/mcp-unreviewed', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );
}, 1000 );

$pre_rest_projection_primed = false;
if ( function_exists( 'did_action' ) && 0 === did_action( 'rest_api_init' ) && class_exists( 'MAD4B_SCP_Servers' ) ) {
	MAD4B_SCP_Servers::blocked_write_tools();
	if ( 0 !== did_action( 'rest_api_init' ) ) {
		mad4b_isolation_fail( 'Pre-REST write projection bootstrap consumed rest_api_init.' );
	}
	$pre_rest_projection_primed = true;
}

$rest = rest_get_server();
$routes = $rest->get_routes();

foreach ( array(
	'/hostinger-ai-assistant/v1/mcp',
	'/hostinger-ai-assistant/v1/jwt/token',
	'/hostinger-ai-assistant/v1/jwt/revoke',
	'/fluentform/v1/mcp/status',
	'/jet-engine/v1/mcp',
	'/jet-engine/v1/mcp-tools',
	'/jet-engine/v1/mcp-tools/run/(?P<tool>[a-zA-Z0-9\\-\\/]+?)',
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

$internal_transport = MAD4B_SCP_MCP_Provider_Isolation::internal_provider_transport_status( 'jetengine' );
if ( empty( $internal_transport['registry_available'] ) || empty( $internal_transport['run_available'] ) || ! empty( $internal_transport['raw_routes_exposed'] ) ) {
	mad4b_isolation_fail( 'JetEngine isolated provider handoff did not retain registry/run internally while keeping raw routes hidden.', $internal_transport );
}
if ( ! class_exists( 'MAD4B_SCP_JetEngine_MCP_Client' ) ) {
	mad4b_isolation_fail( 'JetEngine native client is unavailable for isolation handoff proof.' );
}
$transport = MAD4B_SCP_JetEngine_MCP_Client::transport_status();
if ( ! empty( $transport['native_rest_registry_available'] ) || ! empty( $transport['native_rest_run_available'] ) ) {
	mad4b_isolation_fail( 'Raw JetEngine native REST transport remained externally visible.', $transport );
}
if ( empty( $transport['isolated_native_rest_registry_available'] ) || empty( $transport['isolated_native_rest_run_available'] ) || 'isolated-native-rest-tools' !== $transport['preferred_transport'] || empty( $transport['available'] ) ) {
	mad4b_isolation_fail( 'JetEngine isolated native transport is not available to the internal bridge.', $transport );
}

$native_bridge = class_exists( 'MAD4B_SCP_Adapter_Registry' ) ? MAD4B_SCP_Adapter_Registry::instance()->get( 'native-provider-bridge' ) : null;
if ( ! ( $native_bridge instanceof MAD4B_SCP_Native_Provider_Bridge_Adapter ) ) {
	mad4b_isolation_fail( 'Native Provider Bridge adapter is unavailable.' );
}
$inventory = $native_bridge->jetengine_inventory();
$operation_rows = array();
foreach ( isset( $inventory['operations'] ) && is_array( $inventory['operations'] ) ? $inventory['operations'] : array() as $item ) {
	if ( isset( $item['operation'] ) ) $operation_rows[ (string) $item['operation'] ] = $item;
}
foreach ( array(
	'get_configuration' => 'resource-get-configuration',
	'create_query' => 'tool-add-query',
) as $operation => $expected_native_name ) {
	$row = isset( $operation_rows[ $operation ] ) ? $operation_rows[ $operation ] : null;
	if ( ! is_array( $row ) || empty( $row['available'] ) || $expected_native_name !== ( isset( $row['name'] ) ? (string) $row['name'] : '' ) || 'isolated-native-rest-tools' !== ( isset( $row['provider_native_channel'] ) ? (string) $row['provider_native_channel'] : '' ) ) {
		mad4b_isolation_fail( 'Exact JetEngine native operation mapping did not win over ambiguous semantic matches.', array( 'operation' => $operation, 'row' => $row ) );
	}
}
foreach ( array( 'import_configuration', 'export_configuration' ) as $operation ) {
	$row = isset( $operation_rows[ $operation ] ) ? $operation_rows[ $operation ] : null;
	if ( ! is_array( $row ) || ! empty( $row['available'] ) || 'mad4b_native_provider_operation_unavailable' !== ( isset( $row['error'] ) ? (string) $row['error'] : '' ) ) {
		mad4b_isolation_fail( 'Unsupported JetEngine native operation must remain explicitly fail-closed.', array( 'operation' => $operation, 'row' => $row ) );
	}
}
$query_eligibility = $native_bridge->mutation_ability_runtime_eligibility( 'jetengine/create-query' );
if ( true !== $query_eligibility ) {
	mad4b_isolation_fail( 'Resolved JetEngine create-query operation did not become runtime-eligible.', $query_eligibility );
}
if ( $pre_rest_projection_primed && class_exists( 'MAD4B_SCP_Servers' ) && ! in_array( 'jetengine/create-query', MAD4B_SCP_Servers::write_tools(), true ) ) {
	mad4b_isolation_fail( 'Adapter write projection remained stale after isolated JetEngine discovery became available.' );
}

$website_row = null;
foreach ( isset( $inventory['operations'] ) && is_array( $inventory['operations'] ) ? $inventory['operations'] : array() as $item ) {
	if ( isset( $item['operation'] ) && 'get_website_config' === $item['operation'] ) { $website_row = $item; break; }
}
if ( ! is_array( $website_row ) || empty( $website_row['available'] ) || 'isolated-native-rest-tools' !== ( isset( $website_row['provider_native_channel'] ) ? $website_row['provider_native_channel'] : '' ) ) {
	mad4b_isolation_fail( 'Native Provider Bridge did not discover the isolated JetEngine tool internally.', array( 'inventory' => $inventory, 'row' => $website_row ) );
}
$read_result = $native_bridge->jetengine_get_website_config( array(
	'expected_native_ability' => (string) $website_row['name'],
	'expected_schema_sha256' => (string) $website_row['schema_sha256'],
	'input' => array(),
) );
if ( is_wp_error( $read_result ) || empty( $read_result['ok'] ) || 'resource-get-website-config' !== ( isset( $read_result['tool'] ) ? $read_result['tool'] : '' ) ) {
	mad4b_isolation_fail( 'Governed Native Provider Bridge could not execute the retained read-only JetEngine handler.', $read_result );
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
