<?php
/**
 * Exact-Staging regression proof: unrelated REST requests must never enter the
 * MCP Adapter/peer registration lifecycle or expensive Skill reconciliation,
 * while their own routes remain fully available. The fixture mirrors WPML's
 * REST health endpoint and query contract.
 */

$wp_path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $wp_path ) || '' === trim( $wp_path ) ) {
	fwrite( STDERR, "FAIL non-mcp-rest-isolation: MAD4B_TEST_WP_PATH is required\n" );
	exit( 1 );
}
$wp_path = rtrim( $wp_path, '/\\' );
if ( ! is_file( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "FAIL non-mcp-rest-isolation: wp-load.php not found\n" );
	exit( 1 );
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	fwrite( STDERR, "FAIL non-mcp-rest-isolation: WP_CLI must remain undefined/false\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-json/wpml/v1/rest/status?test_get_parameter=1&cachebuster=ci';
$_GET['test_get_parameter'] = '1';
$_GET['cachebuster'] = 'ci';

require $wp_path . '/wp-load.php';

$fail = static function ( $message, $data = null ) {
	fwrite( STDERR, 'FAIL non-mcp-rest-isolation: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
};

if ( ! class_exists( 'MAD4B_SCP_MCP_Request_Scope' ) ) $fail( 'request-scope class unavailable' );
if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) $fail( 'MCP Adapter runtime unavailable after normal active-plugin bootstrap' );

$mu = isset( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] ) && is_array( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] )
	? $GLOBALS['mad4b_scp_mcp_mu_bootstrap']
	: array();
if ( empty( $mu['eligible'] ) ) $fail( 'managed MU bootstrap was not eligible on exact Staging', $mu );
if ( empty( $mu['request_scope_bypassed'] ) || ! empty( $mu['request_requires_mcp_runtime'] ) ) {
	$fail( 'unrelated REST request entered the managed MU MCP bootstrap', $mu );
}
if ( 'non_mad4b_request_bypassed' !== ( isset( $mu['state'] ) ? (string) $mu['state'] : '' ) ) {
	$fail( 'managed MU bootstrap did not record non-MAD4B bypass', $mu );
}
if ( ! empty( $mu['canonical_symbols_pinned'] ) || ! empty( $mu['canonical_autoloader_loaded'] ) || ! empty( $mu['adapter_instance_armed'] ) ) {
	$fail( 'managed MU bootstrap loaded canonical MCP runtime on unrelated REST request', $mu );
}

$scope = MAD4B_SCP_MCP_Request_Scope::status();
if ( empty( $scope['eligible'] ) ) $fail( 'request scope is not eligible on exact Staging', $scope );
if ( ! empty( $scope['current_request_requires_mcp_runtime'] ) ) $fail( 'WPML request was misclassified as MAD4B MCP', $scope );

$seed = class_exists( 'MAD4B_SCP_Skill_Seeder' ) ? MAD4B_SCP_Skill_Seeder::status() : array();
$provider = class_exists( 'MAD4B_SCP_Skill_Provider_Discovery' ) ? MAD4B_SCP_Skill_Provider_Discovery::status() : array();
if ( ! empty( $seed['current_request_observed'] ) ) $fail( 'Skill Seeder ran on unrelated REST request', $seed );
if ( ! empty( $provider['current_request_observed'] ) ) $fail( 'Provider Skill reconciliation ran on unrelated REST request', $provider );

$adapter = \WP\MCP\Core\McpAdapter::instance();
if ( false !== has_action( 'rest_api_init', array( $adapter, 'init' ) ) ) {
	$fail( 'MCP Adapter init remained armed on unrelated REST request' );
}
if ( did_action( 'mcp_adapter_init' ) > 0 ) $fail( 'MCP Adapter initialized before unrelated REST bootstrap' );

add_action( 'rest_api_init', static function () {
	register_rest_route( 'wpml/v1', '/rest/status', array(
		'methods' => 'GET',
		'permission_callback' => '__return_true',
		'callback' => static function ( WP_REST_Request $request ) {
			return rest_ensure_response( array(
				'status' => 'valid',
				'get_parameters' => '1' === (string) $request->get_param( 'test_get_parameter' ) ? 'valid' : 'invalid',
			) );
		},
	) );
}, 10 );

$server = rest_get_server();
if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) ) $fail( 'REST server unavailable' );
$routes = $server->get_routes();
if ( ! isset( $routes['/wpml/v1/rest/status'] ) ) $fail( 'WPML-compatible route disappeared from REST registry' );
if ( did_action( 'mcp_adapter_init' ) > 0 ) $fail( 'unrelated REST bootstrap entered MCP Adapter lifecycle' );

$request = new WP_REST_Request( 'GET', '/wpml/v1/rest/status' );
$request->set_query_params( array( 'test_get_parameter' => '1', 'cachebuster' => 'ci' ) );
$response = rest_do_request( $request );
$status = is_object( $response ) && method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 0;
$data = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : null;
if ( 200 !== $status ) $fail( 'WPML-compatible request did not return 200', array( 'status' => $status, 'data' => $data ) );
if ( ! is_array( $data ) || 'valid' !== ( isset( $data['status'] ) ? (string) $data['status'] : '' ) || 'valid' !== ( isset( $data['get_parameters'] ) ? (string) $data['get_parameters'] : '' ) ) {
	$fail( 'WPML-compatible response/query contract was not preserved', $data );
}
if ( did_action( 'mcp_adapter_init' ) > 0 ) $fail( 'REST dispatch initialized MCP Adapter after route registration' );

$scope = MAD4B_SCP_MCP_Request_Scope::status();
if ( empty( $scope['adapter_init_removed_for_unrelated_request'] ) ) $fail( 'request scope did not record Adapter suppression', $scope );
if ( ! empty( $scope['production_changed'] ) || ! empty( $scope['provider_settings_changed'] ) || ! empty( $scope['wordpress_rest_routes_changed'] ) ) {
	$fail( 'request scope reported forbidden side effects', $scope );
}

echo 'mad4b.site-control-plane.non-mcp-rest-isolation.v3: PASS' . PHP_EOL;
