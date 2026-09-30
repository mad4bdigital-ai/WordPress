<?php
/**
 * Exact-Staging regression proof: a MAD4B MCP protocol request must not pay for
 * WordPress Core/settings or unrelated ordinary-plugin REST route registration.
 * Unknown/non-plugin callbacks, REST defaults and the addressed MAD4B MCP
 * transport remain available.
 */

$wp_path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $wp_path ) || '' === trim( $wp_path ) ) {
	fwrite( STDERR, "FAIL mcp-protocol-rest-isolation: MAD4B_TEST_WP_PATH is required\n" );
	exit( 1 );
}
$wp_path = rtrim( $wp_path, '/\\' );
if ( ! is_file( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "FAIL mcp-protocol-rest-isolation: wp-load.php not found\n" );
	exit( 1 );
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	fwrite( STDERR, "FAIL mcp-protocol-rest-isolation: WP_CLI must remain undefined/false\n" );
	exit( 1 );
}

/*
 * Reuse the active Hostinger collision fixture from the Web MCP workflow, but
 * give it one ordinary REST registrar. Reflection must classify this callback
 * from its real plugin source under WP_PLUGIN_DIR and suppress it only for the
 * current MAD4B protocol request.
 */
$third_party_fixture = $wp_path . '/wp-content/plugins/hostinger-ai-assistant/hostinger-ai-assistant.php';
if ( ! is_file( $third_party_fixture ) ) {
	fwrite( STDERR, "FAIL mcp-protocol-rest-isolation: Hostinger plugin fixture is missing\n" );
	exit( 1 );
}
$fixture_original = file_get_contents( $third_party_fixture );
if ( ! is_string( $fixture_original ) ) {
	fwrite( STDERR, "FAIL mcp-protocol-rest-isolation: unable to read Hostinger plugin fixture\n" );
	exit( 1 );
}
$fixture_marker = 'mad4b_test_hostinger_rest_registration';
if ( false === strpos( $fixture_original, $fixture_marker ) ) {
	$fixture_extra = <<<'PHP'

$GLOBALS['mad4b_test_hostinger_rest_calls'] = 0;
if ( ! function_exists( 'mad4b_test_hostinger_rest_registration' ) ) {
	function mad4b_test_hostinger_rest_registration() {
		++$GLOBALS['mad4b_test_hostinger_rest_calls'];
		register_rest_route( 'hostinger-ci/v1', '/fanout-alive', array(
			'methods' => 'GET',
			'permission_callback' => '__return_true',
			'callback' => static function () {
				return rest_ensure_response( array( 'hostinger_fixture' => 'alive' ) );
			},
		) );
	}
}
add_action( 'rest_api_init', 'mad4b_test_hostinger_rest_registration', 40 );
PHP;
	if ( false === file_put_contents( $third_party_fixture, $fixture_original . "\n" . $fixture_extra . "\n" ) ) {
		fwrite( STDERR, "FAIL mcp-protocol-rest-isolation: unable to extend Hostinger plugin fixture\n" );
		exit( 1 );
	}
}

$_SERVER['HTTP_HOST'] = 'mad4b-web.test';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-chatgpt';

require $wp_path . '/wp-load.php';

$fail = static function ( $message, $data = null ) use ( $third_party_fixture, $fixture_original ) {
	@file_put_contents( $third_party_fixture, $fixture_original );
	fwrite( STDERR, 'FAIL mcp-protocol-rest-isolation: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
};

if ( ! class_exists( 'MAD4B_SCP_MCP_Request_Scope' ) ) $fail( 'request-scope class unavailable' );
if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) $fail( 'official MCP Adapter runtime unavailable' );
if ( ! function_exists( 'mad4b_test_hostinger_rest_registration' ) ) $fail( 'third-party REST registrar fixture did not load' );

$scope_before = MAD4B_SCP_MCP_Request_Scope::status();
if ( empty( $scope_before['eligible'] ) ) $fail( 'request scope is not eligible on exact Staging', $scope_before );
if ( empty( $scope_before['current_request_requires_mcp_runtime'] ) || empty( $scope_before['current_request_is_http_mcp_transport'] ) ) {
	$fail( 'MCP transport request was not classified as managed protocol hotpath', $scope_before );
}

/*
 * This closure is intentionally defined outside WP_PLUGIN_DIR. The new
 * isolation must fail safe and preserve it because its source is not provably an
 * ordinary plugin callback.
 */
$GLOBALS['mad4b_test_unclassified_rest_calls'] = 0;
$unclassified_callback = static function () {
	++$GLOBALS['mad4b_test_unclassified_rest_calls'];
	register_rest_route( 'mad4b-test/v1', '/unclassified-alive', array(
		'methods' => 'GET',
		'permission_callback' => '__return_true',
		'callback' => static function () {
			return rest_ensure_response( array( 'unclassified' => 'alive' ) );
		},
	) );
};
add_action( 'rest_api_init', $unclassified_callback, 40 );

$server = rest_get_server();
if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) ) $fail( 'REST server unavailable' );
$routes = $server->get_routes();

$scope = MAD4B_SCP_MCP_Request_Scope::status();
if ( empty( $scope['protocol_core_rest_isolation_evaluated'] ) ) $fail( 'protocol Core REST isolation was not evaluated', $scope );
$core_removed = isset( $scope['protocol_core_rest_callbacks_removed'] ) && is_array( $scope['protocol_core_rest_callbacks_removed'] )
	? $scope['protocol_core_rest_callbacks_removed']
	: array();
foreach ( array( 'register_initial_settings', 'create_initial_rest_routes' ) as $expected ) {
	if ( ! in_array( $expected, $core_removed, true ) ) $fail( 'expected Core REST callback was not removed request-locally: ' . $expected, $scope );
}

if ( empty( $scope['protocol_plugin_rest_isolation_evaluated'] ) ) $fail( 'protocol ordinary-plugin REST isolation was not evaluated', $scope );
if ( (int) ( $scope['protocol_plugin_rest_callbacks_removed_count'] ?? 0 ) < 1 ) $fail( 'no ordinary-plugin REST callback was suppressed', $scope );
if ( empty( $scope['protocol_plugin_rest_unknown_callbacks_preserved'] ) || empty( $scope['protocol_plugin_rest_mu_plugin_callbacks_preserved'] ) ) {
	$fail( 'protocol plugin isolation lost its fail-safe preservation contract', $scope );
}
$plugin_removed = isset( $scope['protocol_plugin_rest_callbacks_removed'] ) && is_array( $scope['protocol_plugin_rest_callbacks_removed'] )
	? $scope['protocol_plugin_rest_callbacks_removed']
	: array();
$hostinger_suppressed = false;
foreach ( $plugin_removed as $row ) {
	if ( 'hostinger-ai-assistant' === ( isset( $row['source_plugin'] ) ? (string) $row['source_plugin'] : '' ) ) {
		$hostinger_suppressed = true;
		break;
	}
}
if ( ! $hostinger_suppressed ) $fail( 'Hostinger ordinary-plugin REST registrar was not identified in bounded suppression evidence', $scope );

if ( false === has_action( 'rest_api_init', 'rest_api_default_filters' ) ) {
	$fail( 'REST default filters were removed from protocol request' );
}
if ( 0 !== (int) ( $GLOBALS['mad4b_test_hostinger_rest_calls'] ?? 0 ) ) {
	$fail( 'third-party plugin REST callback executed on MAD4B protocol request', array( 'calls' => $GLOBALS['mad4b_test_hostinger_rest_calls'] ) );
}
if ( isset( $routes['/hostinger-ci/v1/fanout-alive'] ) ) $fail( 'third-party plugin REST route was materialized on MAD4B protocol request' );

if ( 1 !== (int) $GLOBALS['mad4b_test_unclassified_rest_calls'] ) {
	$fail( 'unclassified non-plugin REST callback was not preserved', array( 'calls' => $GLOBALS['mad4b_test_unclassified_rest_calls'] ) );
}
if ( ! isset( $routes['/mad4b-test/v1/unclassified-alive'] ) ) $fail( 'unclassified fail-safe REST route disappeared' );
if ( ! isset( $routes['/mcp/mad4b-chatgpt'] ) ) $fail( 'MAD4B ChatGPT MCP transport route disappeared' );
if ( isset( $routes['/wp/v2/types/post'] ) ) $fail( 'full Core REST routes were unexpectedly materialized on MCP protocol request' );
if ( did_action( 'mcp_adapter_init' ) < 1 ) $fail( 'official MCP Adapter did not initialize for MCP protocol request' );

if ( empty( $scope['protocol_core_rest_isolation_request_local_only'] )
	|| empty( $scope['protocol_plugin_rest_isolation_request_local_only'] )
	|| ! empty( $scope['production_changed'] )
	|| ! empty( $scope['provider_settings_changed'] )
	|| ! empty( $scope['wordpress_rest_routes_changed'] ) ) {
	$fail( 'protocol isolation reported forbidden persistent side effects', $scope );
}

remove_action( 'rest_api_init', $unclassified_callback, 40 );
@file_put_contents( $third_party_fixture, $fixture_original );
echo 'mad4b.site-control-plane.mcp-protocol-rest-isolation.v2: PASS' . PHP_EOL;
