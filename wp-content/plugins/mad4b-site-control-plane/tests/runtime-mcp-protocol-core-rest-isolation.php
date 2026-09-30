<?php
/**
 * Exact-Staging regression proof: a MAD4B MCP protocol request must not pay for
 * WordPress' full Core/settings REST route materialization, while provider/plugin
 * rest_api_init callbacks and the addressed MAD4B MCP transport stay available.
 */

$wp_path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $wp_path ) || '' === trim( $wp_path ) ) {
	fwrite( STDERR, "FAIL mcp-protocol-core-rest-isolation: MAD4B_TEST_WP_PATH is required\n" );
	exit( 1 );
}
$wp_path = rtrim( $wp_path, '/\\' );
if ( ! is_file( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "FAIL mcp-protocol-core-rest-isolation: wp-load.php not found\n" );
	exit( 1 );
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	fwrite( STDERR, "FAIL mcp-protocol-core-rest-isolation: WP_CLI must remain undefined/false\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = 'mad4b-web.test';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-chatgpt';

require $wp_path . '/wp-load.php';

$fail = static function ( $message, $data = null ) {
	fwrite( STDERR, 'FAIL mcp-protocol-core-rest-isolation: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
};

if ( ! class_exists( 'MAD4B_SCP_MCP_Request_Scope' ) ) $fail( 'request-scope class unavailable' );
if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) $fail( 'official MCP Adapter runtime unavailable' );

$scope_before = MAD4B_SCP_MCP_Request_Scope::status();
if ( empty( $scope_before['eligible'] ) ) $fail( 'request scope is not eligible on exact Staging', $scope_before );
if ( empty( $scope_before['current_request_requires_mcp_runtime'] ) || empty( $scope_before['current_request_is_http_mcp_transport'] ) ) {
	$fail( 'MCP transport request was not classified as managed protocol hotpath', $scope_before );
}

$fixture_dir = trailingslashit( WP_PLUGIN_DIR ) . 'mad4b-external-rest-fixture';
$fixture_file = trailingslashit( $fixture_dir ) . 'fixture.php';
if ( ! is_dir( $fixture_dir ) && ! wp_mkdir_p( $fixture_dir ) ) $fail( 'could not create external REST fixture directory' );
$fixture_source = <<<'PHP'
<?php
$GLOBALS['mad4b_test_external_rest_calls'] = 0;
add_action( 'rest_api_init', static function () {
	++$GLOBALS['mad4b_test_external_rest_calls'];
	register_rest_route( 'mad4b-external-fixture/v1', '/should-not-load', array(
		'methods' => 'GET',
		'permission_callback' => '__return_true',
		'callback' => static function () {
			return rest_ensure_response( array( 'external' => 'loaded' ) );
		},
	) );
}, 40 );
PHP;
if ( false === file_put_contents( $fixture_file, $fixture_source ) ) $fail( 'could not write external REST fixture' );
require $fixture_file;

$GLOBALS['mad4b_test_provider_rest_calls'] = 0;
$provider_callback = static function () {
	++$GLOBALS['mad4b_test_provider_rest_calls'];
	register_rest_route( 'mad4b-test/v1', '/provider-alive', array(
		'methods' => 'GET',
		'permission_callback' => '__return_true',
		'callback' => static function () {
			return rest_ensure_response( array( 'provider' => 'alive' ) );
		},
	) );
};
add_action( 'rest_api_init', $provider_callback, 40 );

$server = rest_get_server();
if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) ) $fail( 'REST server unavailable' );
$routes = $server->get_routes();

$scope = MAD4B_SCP_MCP_Request_Scope::status();
if ( empty( $scope['protocol_core_rest_isolation_evaluated'] ) ) $fail( 'protocol Core REST isolation was not evaluated', $scope );
$removed = isset( $scope['protocol_core_rest_callbacks_removed'] ) && is_array( $scope['protocol_core_rest_callbacks_removed'] )
	? $scope['protocol_core_rest_callbacks_removed']
	: array();
foreach ( array( 'register_initial_settings', 'create_initial_rest_routes' ) as $expected ) {
	if ( ! in_array( $expected, $removed, true ) ) $fail( 'expected Core REST callback was not removed request-locally: ' . $expected, $scope );
}

if ( false === has_action( 'rest_api_init', 'rest_api_default_filters' ) ) {
	$fail( 'REST default filters were removed from protocol request' );
}
if ( 1 !== (int) $GLOBALS['mad4b_test_provider_rest_calls'] ) {
	$fail( 'provider/plugin REST callback did not remain active', array( 'calls' => $GLOBALS['mad4b_test_provider_rest_calls'] ) );
}
if ( ! isset( $routes['/mad4b-test/v1/provider-alive'] ) ) $fail( 'MAD4B-owned REST callback disappeared' );
if ( ! empty( $GLOBALS['mad4b_test_external_rest_calls'] ) ) {
	$fail( 'external plugin REST callback executed on exact MCP protocol request', array( 'calls' => $GLOBALS['mad4b_test_external_rest_calls'] ) );
}
if ( isset( $routes['/mad4b-external-fixture/v1/should-not-load'] ) ) $fail( 'external plugin REST route materialized on exact MCP protocol request' );
if ( ! isset( $routes['/mcp/mad4b-chatgpt'] ) ) $fail( 'MAD4B ChatGPT MCP transport route disappeared' );
if ( isset( $routes['/wp/v2/types/post'] ) ) $fail( 'full Core REST routes were unexpectedly materialized on MCP protocol request' );
if ( did_action( 'mcp_adapter_init' ) < 1 ) $fail( 'official MCP Adapter did not initialize for MCP protocol request' );

if ( empty( $scope['protocol_core_rest_isolation_request_local_only'] )
	|| empty( $scope['protocol_external_rest_isolation_request_local_only'] )
	|| empty( $scope['protocol_external_rest_isolation_evaluated'] )
	|| empty( $scope['protocol_external_rest_callbacks_removed'] )
	|| ! empty( $scope['production_changed'] )
	|| ! empty( $scope['provider_settings_changed'] )
	|| ! empty( $scope['wordpress_rest_routes_changed'] ) ) {
	$fail( 'protocol isolation reported forbidden persistent side effects', $scope );
}

remove_action( 'rest_api_init', $provider_callback, 40 );
@unlink( $fixture_file );
@rmdir( $fixture_dir );
echo 'mad4b.site-control-plane.mcp-protocol-core-rest-isolation.v2: PASS' . PHP_EOL;
