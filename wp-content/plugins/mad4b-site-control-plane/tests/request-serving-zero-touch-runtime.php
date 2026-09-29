<?php

define( 'ABSPATH', '/srv/wordpress/' );

function wp_unslash( $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function rest_get_url_prefix() { return 'wp-json'; }
function is_admin() { return ! empty( $GLOBALS['mad4b_is_admin'] ); }
function current_user_can( $cap ) { return true; }
function wp_doing_ajax() { return false; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-diagnostic-policy.php';

function mad4b_zero_touch_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['mad4b_is_admin'] = false;
$_GET = array();
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/types/post?context=edit';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_rest(), 'Core Site Health REST route must be foreign REST.' );
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'Core Site Health REST route must be zero-touch.' );
mad4b_zero_touch_assert( 'foreign_rest' === MAD4B_SCP_Provider_Diagnostic_Policy::zero_touch_reason(), 'Core REST zero-touch reason mismatch.' );

$_SERVER['REQUEST_URI'] = '/wp-json/wpml/v1/rest/status?test_get_parameter=1';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_rest(), 'WPML REST route must be foreign REST.' );
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'WPML REST route must be zero-touch.' );

$_SERVER['REQUEST_URI'] = '/wp-cron.php?doing_wp_cron=123.456';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_wordpress_cron(), 'wp-cron.php loopback must be classified as WordPress cron.' );
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'wp-cron.php loopback must be zero-touch.' );
mad4b_zero_touch_assert( 'wordpress_cron' === MAD4B_SCP_Provider_Diagnostic_Policy::zero_touch_reason(), 'Cron zero-touch reason mismatch.' );

$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-read';
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_rest(), 'MAD4B MCP route must not be treated as foreign REST.' );
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'MAD4B MCP route must retain its own protocol lifecycle.' );

$_SERVER['REQUEST_URI'] = '/wp-json/mad4b/v1/oauth-protected-resource';
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_rest(), 'MAD4B OAuth resource route must not be treated as foreign REST.' );

$GLOBALS['mad4b_is_admin'] = true;
$_GET = array( 'page' => 'sitepress-multilingual-cms/menu/support.php' );
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=sitepress-multilingual-cms/menu/support.php';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_wp_admin(), 'WPML Support must be classified as foreign wp-admin.' );
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'WPML Support must be zero-touch.' );
mad4b_zero_touch_assert( 'foreign_wp_admin' === MAD4B_SCP_Provider_Diagnostic_Policy::zero_touch_reason(), 'WPML Support zero-touch reason mismatch.' );

$_GET = array( 'post_type' => 'tour' );
$_REQUEST = $_GET;
$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php?post_type=tour';
$GLOBALS['pagenow'] = 'edit.php';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_wp_admin(), 'Generic CPT edit.php must be zero-touch even without ?page=.' );

$_GET = array( 'post' => '123', 'action' => 'edit' );
$_REQUEST = $_GET;
$_SERVER['REQUEST_URI'] = '/wp-admin/post.php?post=123&action=edit';
$GLOBALS['pagenow'] = 'post.php';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_wp_admin(), 'post.php editing must stay outside MAD4B lifecycle.' );

$_GET = array();
$_REQUEST = array();
$_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';
$GLOBALS['pagenow'] = 'plugins.php';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_wp_admin(), 'plugins.php listing must be zero-touch.' );
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_wordpress_lifecycle_admin(), 'plugins.php listing must not be lifecycle.' );

$_GET = array( 'action' => 'activate', 'plugin' => 'example/example.php' );
$_REQUEST = $_GET;
$_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php?action=activate&plugin=example/example.php';
$GLOBALS['pagenow'] = 'plugins.php';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_wordpress_lifecycle_admin(), 'plugins.php activation must be lifecycle.' );
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_wp_admin(), 'Plugin activation must retain lifecycle plane.' );

$_GET = array( 'action' => 'upgrade-plugin', 'plugin' => 'example/example.php' );
$_REQUEST = $_GET;
$_SERVER['REQUEST_URI'] = '/wp-admin/update.php?action=upgrade-plugin&plugin=example/example.php';
$GLOBALS['pagenow'] = 'update.php';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_wordpress_lifecycle_admin(), 'update.php must retain lifecycle plane.' );

$_GET = array( 'page' => 'mad4b-control-plane-connection', 'tab' => 'endpoints' );
$_REQUEST = $_GET;
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=mad4b-control-plane-connection&tab=endpoints';
$GLOBALS['pagenow'] = 'admin.php';
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_wp_admin(), 'MAD4B operator surface must retain explicit diagnostic lifecycle.' );
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'MAD4B operator surface was incorrectly zero-touched.' );

$GLOBALS['mad4b_is_admin'] = false;
$_GET = array();
$_SERVER['REQUEST_URI'] = '/about/';
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'Normal frontend traffic must remain eligible for bounded acceptance sampling.' );

echo "mad4b.request-serving-zero-touch.runtime.v1: PASS\n";
