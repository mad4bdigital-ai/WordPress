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
function add_action( ...$args ) {}
function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-admin-route-registry.php';
// The real page definitions register without booting menus or the provider runtime.
require dirname( __DIR__ ) . '/includes/search/class-mad4b-scp-search-experience.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-dynamic-content-pipeline-admin.php';
$expected = 'https://fixture.test/wp-admin/admin.php?page=mad4b-search-intelligence';
check( $expected === MAD4B_SCP_Admin_Route_Registry::resolve( '/wp-admin/mad4b-search-intelligence', 'GET' ), 'Legacy Search URL was not recovered' );
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
