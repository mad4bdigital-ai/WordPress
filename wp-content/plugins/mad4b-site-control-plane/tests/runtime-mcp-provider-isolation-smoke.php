<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

// Historical evidence marker for Spec Kit migration tracking only:
// mad4b.site-control-plane.runtime-mcp-provider-isolation.v1
// mad4b.site-control-plane.runtime-mcp-provider-isolation.v4

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

$hostinger_callback = array( 'Hostinger\\AiAssistant\\Mcp\\McpServer', 'create_server' );
$elementskit_callback = array( 'ElementsKit_Lite\\Mcp\\Server', 'register_server' );
$jetengine_rest = new \Jet_Engine\MCP_Tools\MAD4B_Test_REST_Registration();
$unknown = new MAD4B_Isolation_Unknown_Server_Callback();
add_action( 'rest_api_init', array( $jetengine_rest, 'register_features_api' ), 10, 1 );
// WordPress hooks accept callback identities before provider classes are loaded.
// This proves exact deny-only matching without requiring unrelated provider
// implementations in the disposable Connection runtime.
add_action( 'mcp_adapter_init', $hostinger_callback, 10 );
add_action( 'mcp_adapter_init', $elementskit_callback, 10 );
add_action( 'mcp_adapter_init', array( $unknown, 'register_server' ), 10 );

MAD4B_SCP_MCP_Provider_Isolation::suppress_provider_server_registrations();
if ( false !== has_action( 'mcp_adapter_init', $hostinger_callback ) ) {
	mad4b_isolation_fail( 'Hostinger MCP server registration callback was not suppressed.' );
}
if ( false !== has_action( 'mcp_adapter_init', $elementskit_callback ) ) {
	mad4b_isolation_fail( 'ElementsKit MCP server registration callback was not suppressed.' );
}
if ( false === has_action( 'mcp_adapter_init', array( $unknown, 'register_server' ) ) ) {
	mad4b_isolation_fail( 'Unknown server callback was removed instead of remaining fail-closed.' );
}
remove_action( 'mcp_adapter_init', array( $unknown, 'register_server' ), 10 );

add_action( 'rest_api_init', function () {
	$permission = function () { return true; };
	$external_run_permission = function () {
		return new WP_Error( 'jetengine_external_auth_required', 'External JetEngine run transport requires provider authentication.', array( 'status' => 401 ) );
	};
	$callback = function () { return rest_ensure_response( array( 'ok' => true ) ); };
	$GLOBALS['mad4b_ci_jetengine_run_callback_reached'] = false;
	register_rest_route( 'hostinger-ai-assistant/v1', '/mcp', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'hostinger-ai-assistant/v1', '/jwt/token', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'hostinger-ai-assistant/v1', '/jwt/revoke', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'fluentform/v1', '/mcp/status', array( 'methods' => 'GET', 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'hfe/v1', '/mcp-settings', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'elementskit', '/mcp', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'elementskit/v1', '/mcp-proxy', array( 'methods' => 'POST', 'callback' => $callback, 'permission_callback' => $permission ) );
	// Elementor 4.x MCP Composer surfaces. The composer namespace is versioned,
	// so the registry contract must suppress the family without pinning 1.0.17.
	register_rest_route( 'elementor-mcp-composer/v1.0.17', '/mcp-settings', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'elementor-mcp-composer/v1.0.17', '/mcp-credentials', array( 'methods' => array( 'GET', 'POST', 'DELETE' ), 'callback' => $callback, 'permission_callback' => $permission ) );
	register_rest_route( 'elementor/v1', '/mcp-proxy', array( 'methods' => array( 'GET', 'POST' ), 'callback' => $callback, 'permission_callback' => $permission ) );

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
if ( ! empty( $GLOBALS['mad4b_jetengine_rest_registration_callback_hit'] ) ) {
	mad4b_isolation_fail( 'Reviewed JetEngine MCP REST registration callback executed before dispatch.' );
}
if ( false !== has_action( 'rest_api_init', array( $jetengine_rest, 'register_features_api' ) ) ) {
	mad4b_isolation_fail( 'Reviewed JetEngine MCP REST registration callback remained armed.' );
}
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
	'/elementor-mcp-composer/v1.0.17',
	'/elementor-mcp-composer/v1.0.17/mcp-settings',
	'/elementor-mcp-composer/v1.0.17/mcp-credentials',
	'/elementor/v1/mcp-proxy',
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
if ( ! empty( $internal_transport['registry_available'] ) || ! empty( $internal_transport['run_available'] ) || ! empty( $internal_transport['materialization_attempted'] ) ) {
	mad4b_isolation_fail( 'JetEngine retained routes were materialized before discovery requested them.', $internal_transport );
}
if ( ! class_exists( 'MAD4B_SCP_JetEngine_MCP_Client' ) ) {
	mad4b_isolation_fail( 'JetEngine native client is unavailable for isolation handoff proof.' );
}
$pre_discovery_transport = MAD4B_SCP_JetEngine_MCP_Client::transport_status();
if ( ! empty( $pre_discovery_transport['available'] ) || ! empty( $pre_discovery_transport['isolated_materialization_attempted'] ) ) {
	mad4b_isolation_fail( 'Passive JetEngine transport status unexpectedly materialized provider routes.', $pre_discovery_transport );
}

$native_bridge = class_exists( 'MAD4B_SCP_Adapter_Registry' ) ? MAD4B_SCP_Adapter_Registry::instance()->get( 'native-provider-bridge' ) : null;
if ( ! ( $native_bridge instanceof MAD4B_SCP_Native_Provider_Bridge_Adapter ) ) {
	mad4b_isolation_fail( 'Native Provider Bridge adapter is unavailable.' );
}
// Inventory is the first active discovery call. It must break the bootstrap
// deadlock by materializing only the reviewed suppressed JetEngine routes.
$inventory = $native_bridge->jetengine_inventory();
$internal_transport = MAD4B_SCP_MCP_Provider_Isolation::internal_provider_transport_status( 'jetengine' );
if ( empty( $internal_transport['registry_available'] ) || empty( $internal_transport['run_available'] ) || empty( $internal_transport['materialization_attempted'] ) || 'materialized_internal_only' !== ( isset( $internal_transport['materialization_state'] ) ? (string) $internal_transport['materialization_state'] : '' ) || ! empty( $internal_transport['raw_routes_exposed'] ) ) {
	mad4b_isolation_fail( 'Discovery did not materialize the reviewed JetEngine registry/run routes internally.', $internal_transport );
}
if ( empty( $GLOBALS['mad4b_jetengine_rest_registration_callback_hit'] ) ) {
	mad4b_isolation_fail( 'Discovery did not execute the captured reviewed JetEngine registration callback.' );
}
$transport = MAD4B_SCP_JetEngine_MCP_Client::transport_status();
if ( ! empty( $transport['native_rest_registry_available'] ) || ! empty( $transport['native_rest_run_available'] ) ) {
	mad4b_isolation_fail( 'Raw JetEngine native REST transport remained externally visible.', $transport );
}
if ( empty( $transport['isolated_native_rest_registry_available'] ) || empty( $transport['isolated_native_rest_run_available'] ) || 'isolated-native-rest-tools' !== $transport['preferred_transport'] || empty( $transport['available'] ) ) {
	mad4b_isolation_fail( 'JetEngine isolated native transport is not available to the internal bridge after discovery materialization.', $transport );
}
if ( empty( $inventory['transport_status']['available'] ) || 'isolated-native-rest-tools' !== ( isset( $inventory['transport_status']['preferred_transport'] ) ? (string) $inventory['transport_status']['preferred_transport'] : '' ) ) {
	mad4b_isolation_fail( 'JetEngine inventory returned stale pre-materialization transport status.', $inventory['transport_status'] ?? array() );
}
$routes_after_discovery = $rest->get_routes();
foreach ( array(
	'/jet-engine/v1/mcp',
	'/jet-engine/v1/mcp-tools',
	'/jet-engine/v1/mcp-tools/run/(?P<tool>[a-zA-Z0-9\\-\\/]+?)',
) as $route ) {
	if ( isset( $routes_after_discovery[ $route ] ) ) mad4b_isolation_fail( 'Internally materialized JetEngine route leaked back onto the public REST route map.', $route );
}

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
	mad4b_isolation_fail( 'Resolved JetEngine create-query native operation did not become structurally discoverable.', $query_eligibility );
}
if ( class_exists( 'MAD4B_SCP_Servers' ) && in_array( 'jetengine/create-query', MAD4B_SCP_Servers::write_tools(), true ) ) {
	mad4b_isolation_fail( 'Discovered high-risk JetEngine create-query bypassed capability certification into the write surface.' );
}
$blocked_query = null;
foreach ( class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::blocked_write_tools() : array() as $blocked_item ) {
	if ( is_array( $blocked_item ) && 'jetengine/create-query' === ( isset( $blocked_item['ability'] ) ? (string) $blocked_item['ability'] : '' ) ) {
		$blocked_query = $blocked_item;
		break;
	}
}
if ( ! is_array( $blocked_query ) || 'jetengine' !== ( isset( $blocked_query['provider'] ) ? (string) $blocked_query['provider'] : '' ) || 'provider_capability_not_write_eligible' !== ( isset( $blocked_query['reason'] ) ? (string) $blocked_query['reason'] : '' ) ) {
	mad4b_isolation_fail( 'JetEngine native write was not fail-closed by its exact provider capability gate.', $blocked_query );
}
$mutation_guard = MAD4B_SCP_Provider_Compatibility_Certification::mutation_guard( 'jetengine', 'jetengine/create-query', true, $native_bridge );
if ( ! is_wp_error( $mutation_guard ) || 'mad4b_provider_capability_mutation_not_certified' !== $mutation_guard->get_error_code() ) {
	mad4b_isolation_fail( 'Direct JetEngine native mutation guard did not remain fail-closed after discovery.', $mutation_guard );
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
	mad4b_isolation_fail( 'Governed Native Provider Bridge could not execute the retained read-only JetEngine handler when external provider auth rejects the raw run route.', $read_result );
}
if ( empty( $GLOBALS['mad4b_ci_jetengine_run_callback_reached'] ) ) {
	mad4b_isolation_fail( 'Internal governed handoff did not reach the retained JetEngine provider callback.' );
}

$diagnostic_schema = array();
$diagnostic_hash = hash( 'sha256', wp_json_encode( $diagnostic_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
$diagnostic_error = MAD4B_SCP_JetEngine_MCP_Client::call_tool( 'resource-provider-error', array(), $diagnostic_hash, 'get_configuration' );
if ( ! is_wp_error( $diagnostic_error ) || 'mad4b_jetengine_rest_tool_http_error' !== $diagnostic_error->get_error_code() ) {
	mad4b_isolation_fail( 'Bounded native REST execution diagnostic did not preserve the provider non-success status.', $diagnostic_error );
}
$diagnostic = $diagnostic_error->get_error_data();
if (
	! is_array( $diagnostic )
	|| 'mad4b.jetengine-native-rest-execution-diagnostic.v1' !== ( isset( $diagnostic['contract'] ) ? (string) $diagnostic['contract'] : '' )
	|| 409 !== ( isset( $diagnostic['http_status'] ) ? (int) $diagnostic['http_status'] : 0 )
	|| 'jetengine_fixture_provider_failure' !== ( isset( $diagnostic['provider_response_error_code'] ) ? (string) $diagnostic['provider_response_error_code'] : '' )
	|| 'bypassed_external_provider_permission' !== ( isset( $diagnostic['permission_result'] ) ? (string) $diagnostic['permission_result'] : '' )
	|| empty( $diagnostic['provider_permission_callback_present'] )
	|| empty( $diagnostic['callback_reached'] )
	|| 'resource-provider-error' !== ( isset( $diagnostic['native_tool_name'] ) ? (string) $diagnostic['native_tool_name'] : '' )
	|| '/jet-engine/v1/mcp-tools/run/resource-provider-error' !== ( isset( $diagnostic['internal_route'] ) ? (string) $diagnostic['internal_route'] : '' )
	|| 'POST' !== ( isset( $diagnostic['http_method'] ) ? (string) $diagnostic['http_method'] : '' )
	|| ! preg_match( '/^[a-f0-9]{64}$/', isset( $diagnostic['request_envelope_digest'] ) ? (string) $diagnostic['request_envelope_digest'] : '' )
) {
	mad4b_isolation_fail( 'Native REST execution diagnostic is incomplete or leaked out of its bounded contract.', $diagnostic );
}

$status = MAD4B_SCP_MCP_Provider_Isolation::status();
if ( empty( $status['effective'] ) || empty( $status['default_server_suppressed'] ) || empty( $status['wpmedia_oauth_server_suppressed'] ) ) {
	mad4b_isolation_fail( 'Isolation status did not report effective/default/WP Media suppression.', $status );
}
if ( empty( $status['server_registration_suppression_attempted'] ) ) {
	mad4b_isolation_fail( 'Server-registration suppression was not attempted.', $status );
}
if ( empty( $status['rest_registration_suppression_attempted'] ) || (int) $status['suppressed_rest_callback_count'] < 1 ) {
	mad4b_isolation_fail( 'Reviewed provider REST registration suppression was not applied.', $status );
}
$rest_callback_providers = array();
foreach ( isset( $status['suppressed_rest_callbacks'] ) && is_array( $status['suppressed_rest_callbacks'] ) ? $status['suppressed_rest_callbacks'] : array() as $item ) {
	if ( isset( $item['provider'] ) ) $rest_callback_providers[] = (string) $item['provider'];
}
if ( ! in_array( 'jetengine', $rest_callback_providers, true ) ) {
	mad4b_isolation_fail( 'JetEngine REST callback suppression evidence is missing.', $status );
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
foreach ( array(
	'/elementor-mcp-composer/v1.0.17',
	'/elementor-mcp-composer/v1.0.17/mcp-settings',
	'/elementor-mcp-composer/v1.0.17/mcp-credentials',
	'/elementor/v1/mcp-proxy',
) as $elementor_route ) {
	if ( in_array( $elementor_route, $foreign_routes, true ) ) {
		mad4b_isolation_fail( 'Elementor MCP route remained visible after cataloged isolation.', array( 'route' => $elementor_route, 'foreign' => $foreign ) );
	}
}

if ( ! in_array( '/unknown-provider/v1/mcp-unreviewed', $foreign_routes, true ) ) {
	mad4b_isolation_fail( 'Unknown MCP route did not remain visible to peer governance.', $peer );
}
if ( ! empty( $peer['write_side_channel_detected'] ) || empty( $peer['foreign_transport_unreviewed'] ) || ! in_array( 'mcp_foreign_transport_unreviewed', isset( $peer['blockers'] ) ? $peer['blockers'] : array(), true ) ) {
	mad4b_isolation_fail( 'Unknown MCP route must remain fail-closed without being mislabeled as proven write-capable.', $peer );
}
$guard = MAD4B_SCP_MCP_Peer_Governance::mutation_guard();
if ( ! is_wp_error( $guard ) || 'mcp_foreign_transport_unreviewed' !== $guard->get_error_code() ) {
	mad4b_isolation_fail( 'Unknown MCP route did not preserve exact fail-closed mutation semantics.', $guard );
}

fwrite( STDOUT, 'mad4b.site-control-plane.runtime-mcp-provider-isolation.v5: PASS' . PHP_EOL );
