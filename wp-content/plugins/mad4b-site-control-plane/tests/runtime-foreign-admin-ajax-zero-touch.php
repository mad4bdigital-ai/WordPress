<?php
/**
 * Exact-Staging regression proof: unrelated admin-AJAX must exit through the
 * early zero-touch kernel before the full Control Plane is parsed, while the
 * lightweight request scope still disarms the official MCP Adapter lifecycle.
 */

$wp_path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $wp_path ) || '' === trim( $wp_path ) ) {
	fwrite( STDERR, "FAIL foreign-admin-ajax-zero-touch: MAD4B_TEST_WP_PATH is required\n" );
	exit( 1 );
}
$wp_path = rtrim( $wp_path, '/\\' );
if ( ! is_file( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "FAIL foreign-admin-ajax-zero-touch: wp-load.php not found\n" );
	exit( 1 );
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	fwrite( STDERR, "FAIL foreign-admin-ajax-zero-touch: WP_CLI must remain undefined/false\n" );
	exit( 1 );
}

define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=foreign_provider_ping';
$_REQUEST['action'] = 'foreign_provider_ping';
$_POST['action'] = 'foreign_provider_ping';

require $wp_path . '/wp-load.php';

$fail = static function ( $message, $data = null ) {
	fwrite( STDERR, 'FAIL foreign-admin-ajax-zero-touch: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
};

if ( ! defined( 'MAD4B_SCP_EARLY_ZERO_TOUCH_REASON' ) || 'foreign_admin_ajax' !== MAD4B_SCP_EARLY_ZERO_TOUCH_REASON ) {
	$fail( 'foreign admin-AJAX did not enter early zero-touch kernel', defined( 'MAD4B_SCP_EARLY_ZERO_TOUCH_REASON' ) ? MAD4B_SCP_EARLY_ZERO_TOUCH_REASON : 'undefined' );
}
if ( ! class_exists( 'MAD4B_SCP_MCP_Request_Scope' ) ) $fail( 'request-scope class unavailable' );
foreach ( array( 'MAD4B_SCP_Schema', 'MAD4B_SCP_Plugin', 'MAD4B_SCP_Adapter_Registry', 'MAD4B_SCP_Self_Update' ) as $forbidden_class ) {
	if ( class_exists( $forbidden_class, false ) ) $fail( 'foreign admin-AJAX loaded full Control Plane class: ' . $forbidden_class );
}
if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) $fail( 'official MCP Adapter runtime unavailable' );

$scope = MAD4B_SCP_MCP_Request_Scope::status();
if ( empty( $scope['eligible'] ) ) $fail( 'request scope is not eligible on exact Staging', $scope );
if ( ! empty( $scope['current_request_requires_mcp_runtime'] ) ) $fail( 'foreign admin-AJAX was misclassified as MCP runtime', $scope );

$adapter = \WP\MCP\Core\McpAdapter::instance();
if ( ! empty( $scope['adapter_runtime_from_official_plugin'] )
	&& false !== has_action( 'rest_api_init', array( $adapter, 'init' ) ) ) {
	$fail( 'official MCP Adapter rest_api_init remained armed on foreign admin-AJAX', $scope );
}

echo "mad4b.site-control-plane.foreign-admin-ajax-zero-touch.v1: PASS\n";
