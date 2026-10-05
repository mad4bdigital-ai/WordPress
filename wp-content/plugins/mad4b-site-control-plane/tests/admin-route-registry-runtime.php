<?php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['admin_base'] = 'https://fixture.test/wp-admin/';
function admin_url( $path = '' ) { return $GLOBALS['admin_base'] . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function add_query_arg( $args, $value, $url = null ) {
	if ( is_array( $args ) ) { $url = $value; } else { $args = array( $args => $value ); }
	$parts = parse_url( $url ); $query = array();
	if ( isset( $parts['query'] ) ) parse_str( $parts['query'], $query );
	return strtok( $url, '?' ) . '?' . http_build_query( array_merge( $query, $args ) );
}
$GLOBALS['fixture_actions'] = array();
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fixture_actions'][] = array(
		'hook' => (string) $hook,
		'callback' => $callback,
		'priority' => (int) $priority,
		'accepted_args' => (int) $accepted_args,
	);
}
function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-admin-route-registry.php';
// The real page definitions register without booting menus or the provider runtime.
require dirname( __DIR__ ) . '/includes/search/class-mad4b-scp-search-experience.php';
MAD4B_SCP_Search_Experience::boot();
$search_admin_menu = array_values( array_filter(
	$GLOBALS['fixture_actions'],
	static function ( $row ) {
		return 'admin_menu' === $row['hook']
			&& is_array( $row['callback'] )
			&& 'MAD4B_SCP_Search_Experience' === $row['callback'][0]
			&& 'menu' === $row['callback'][1];
	}
) );
check( 1 === count( $search_admin_menu ), 'Search Intelligence admin menu registration is not deterministic' );
check( 20 === $search_admin_menu[0]['priority'], 'Search Intelligence submenu must register after the MAD4B parent menu' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-dynamic-content-pipeline-admin.php';
$pipeline_admin_menu = array_values( array_filter( $GLOBALS['fixture_actions'], static function ( $row ) {
	return 'admin_menu' === $row['hook'] && is_array( $row['callback'] ) && 'MAD4B_SCP_Dynamic_Content_Pipeline_Admin' === $row['callback'][0];
} ) );
check( 1 === count( $pipeline_admin_menu ) && $pipeline_admin_menu[0]['priority'] > MAD4B_SCP_Admin_Route_Registry::PARENT_MENU_PRIORITY, 'Pipeline registered before its parent menu' );
MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( 'Future_Page', 'menu' ), 0 );
$future_menu = end( $GLOBALS['fixture_actions'] );
check( $future_menu['priority'] > MAD4B_SCP_Admin_Route_Registry::PARENT_MENU_PRIORITY, 'An early child priority bypassed the shared scheduler' );
// Use WordPress's real hook derivation and access checks, not a reimplementation.
function __( $text, $domain = '' ) { return $text; }
function plugin_basename( $file ) { return $file; }
function sanitize_title( $title ) { return strtolower( $title ); }
function current_user_can( $capability ) { return $GLOBALS['fixture_admin_allowed']; }
function has_action( $hook ) { foreach ( $GLOBALS['fixture_actions'] as $row ) if ( $row['hook'] === $hook ) return true; return false; }
function is_network_admin() { return false; }
function is_user_admin() { return false; }
$GLOBALS['fixture_admin_allowed'] = true;
$core_admin_plugin = dirname( __DIR__, 4 ) . '/wp-admin/includes/plugin.php';
if ( ! is_readable( $core_admin_plugin ) ) throw new RuntimeException( 'WordPress core admin functions are required for this regression' );
require $core_admin_plugin;
function reset_admin_globals() {
 foreach ( array( 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', '_wp_real_parent_file', '_wp_menu_nopriv', '_wp_submenu_nopriv' ) as $key ) $GLOBALS[ $key ] = array();
 $GLOBALS['pagenow'] = 'admin.php'; $GLOBALS['parent_file'] = 'mad4b-control-plane';
}
reset_admin_globals();
MAD4B_SCP_Dynamic_Content_Pipeline_Admin::menu();
add_menu_page( 'MAD4B', 'MAD4B', 'manage_options', 'mad4b-control-plane' );
$GLOBALS['plugin_page'] = 'mad4b-control-plane-content-pipeline';
check( ! user_can_access_admin_page(), 'Fixture did not reproduce the pre-parent submenu access failure' );
reset_admin_globals();
$menus = array_values( array_filter( $GLOBALS['fixture_actions'], static function ( $row ) {
 return 'admin_menu' === $row['hook'] && is_array( $row['callback'] ) && in_array( $row['callback'][0], array( 'MAD4B_SCP_Search_Experience', 'MAD4B_SCP_Dynamic_Content_Pipeline_Admin' ), true );
} ) );
$menus[] = array( 'priority' => MAD4B_SCP_Admin_Route_Registry::PARENT_MENU_PRIORITY, 'callback' => static function () { add_menu_page( 'MAD4B', 'MAD4B', 'manage_options', 'mad4b-control-plane' ); } );
usort( $menus, static function ( $a, $b ) { return $a['priority'] <=> $b['priority']; } );
foreach ( $menus as $row ) call_user_func( $row['callback'] );
foreach ( array( 'mad4b-search-intelligence', 'mad4b-control-plane-content-pipeline' ) as $slug ) {
 $GLOBALS['plugin_page'] = $slug;
 check( user_can_access_admin_page(), 'WordPress denied the registered administrator page: ' . $slug );
 $GLOBALS['fixture_admin_allowed'] = false;
 check( ! user_can_access_admin_page(), 'Menu order repair weakened administrator access: ' . $slug );
 $GLOBALS['fixture_admin_allowed'] = true;
}
$expected = 'https://fixture.test/wp-admin/admin.php?page=mad4b-search-intelligence';
check( $expected === MAD4B_SCP_Admin_Route_Registry::resolve( '/wp-admin/mad4b-search-intelligence', 'GET' ), 'Legacy Search URL was not recovered' );
check( 'manage_options' === MAD4B_SCP_Admin_Route_Registry::routes()['mad4b-search-intelligence']['required_capability'], 'Search Intelligence route permission was weakened while repairing menu order' );
check( 'https://fixture.test/wp-admin/admin.php?page=mad4b-control-plane-content-pipeline' === MAD4B_SCP_Admin_Route_Registry::resolve( '/wp-admin/mad4b-control-plane-content-pipeline/', 'HEAD' ), 'Pipeline URL was not recovered' );
check( MAD4B_SCP_Admin_Route_Registry::register( 'mad4b-future-page', 'edit_posts', array( 'mad4b-old-future-page' ) ), 'Future page declaration rejected' );
check( ! MAD4B_SCP_Admin_Route_Registry::register( 'mad4b-future-page', 'read' ), 'Duplicate definition weakened permission' );
check( ! MAD4B_SCP_Admin_Route_Registry::register( 'mad4b-conflict', 'read', array( 'mad4b-old-future-page' ) ), 'Alias hijack accepted' );
check( 'edit_posts' === MAD4B_SCP_Admin_Route_Registry::routes()['mad4b-future-page']['required_capability'], 'Required capability lost' );
$GLOBALS['admin_base'] = 'https://fixture.test/wordpress/wp-admin/';
check( 'https://fixture.test/wordpress/wp-admin/admin.php?page=mad4b-search-intelligence&tab=profiles' === MAD4B_SCP_Admin_Route_Registry::resolve( '/wordpress/wp-admin/mad4b-search-intelligence?tab=profiles&redirect_to=https://evil.test', 'GET' ), 'Subdirectory or safe query handling failed' );
check( 'https://fixture.test/wordpress/wp-admin/admin.php?page=mad4b-future-page' === MAD4B_SCP_Admin_Route_Registry::resolve( '/wordpress/wp-admin/mad4b-old-future-page', 'GET' ), 'Future page required a hardcoded redirect' );
foreach ( array(
	'/wp-admin/mad4b-search-intelligence', '/wordpress/wp-admin/unknown',
	'/wordpress/wp-admin/admin.php?page=mad4b-search-intelligence',
	'/wordpress/wp-admin/mad4b-search-intelligence?section[]=x',
	'/wordpress/wp-admin/mad4b-search-intelligence?action=delete',
	'/wordpress/wp-admin/mad4b-search-intelligence?page=another',
	'/wordpress/wp-admin/mad4b-search-intelligence?_wpnonce=x',
	'/wordpress/wp-admin/../wp-admin/mad4b-search-intelligence',
	'/wordpress/wp-admin/%6dad4b-search-intelligence',
	'//evil.test/wordpress/wp-admin/mad4b-search-intelligence',
	'/wordpress/wp-admin/mad4b-search-intelligence//',
) as $uri ) {
	// Array query values are ignored safely; they cannot be replayed to the target.
	if ( false !== strpos( $uri, 'section[]' ) ) { check( false === strpos( MAD4B_SCP_Admin_Route_Registry::resolve( $uri, 'GET' ), 'section' ), 'Array query replayed' ); continue; }
	check( '' === MAD4B_SCP_Admin_Route_Registry::resolve( $uri, 'GET' ), 'Unsafe or unrelated URL redirected: ' . $uri );
}
foreach ( array( 'POST', 'PUT', 'DELETE' ) as $method ) check( '' === MAD4B_SCP_Admin_Route_Registry::resolve( '/wordpress/wp-admin/mad4b-search-intelligence', $method ), 'Mutation request redirected' );
echo "Admin route registry runtime: PASS\n";
