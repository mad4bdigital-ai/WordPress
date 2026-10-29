<?php
/** Isolated native WordPress plugin-management menu regression (no WP/database). */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function plugin_dir_path( $file ) { return dirname( (string) $file ) . '/'; }
function is_admin() { return true; }
function __( $text, $domain = '' ) { return $text; }
function register_deactivation_hook( $file, $callback ) { return true; }
$GLOBALS['native_nav_hooks'] = array();
$GLOBALS['native_nav_menus'] = array();
$GLOBALS['native_nav_submenus'] = array();
function add_action( $hook, $callback, $priority = 10 ) {
    $GLOBALS['native_nav_hooks'][ $hook ][] = array( $callback, $priority );
}
function add_menu_page( $title, $label, $cap, $slug, $callback, $icon = '', $position = null ) {
    $GLOBALS['native_nav_menus'][] = compact( 'title', 'label', 'cap', 'slug', 'callback' );
}
function add_submenu_page( $parent, $title, $label, $cap, $slug, $callback ) {
    $GLOBALS['native_nav_submenus'][] = compact( 'parent', 'title', 'label', 'cap', 'slug', 'callback' );
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array();
$_REQUEST = array();
$GLOBALS['pagenow'] = 'plugins.php';
require dirname( __DIR__ ) . '/mad4b-site-control-plane.php';
function native_nav_assert( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
// The native early-admin fast path loads QA30's contract before full WordPress
// hooks exist in this hermetic fixture. Loading must remain nonfatal without
// weakening the hook registration performed under an actual WordPress runtime.
native_nav_assert( ! function_exists( 'add_filter' ),
    'Fixture must model native early-admin lifecycle without WP filter hooks' );
native_nav_assert( class_exists( 'MAD4B_SCP_Native_General_Settings_Contract', false ),
    'Scoped General Settings contract must load without requiring WP hook APIs' );
native_nav_assert( defined( 'MAD4B_SCP_EARLY_ZERO_TOUCH_REASON' )
    && 'wordpress_plugin_management_read' === MAD4B_SCP_EARLY_ZERO_TOUCH_REASON,
    'Native plugins.php must remain zero-touch' );
native_nav_assert( ! class_exists( 'MAD4B_SCP_Plugin', false ),
    'Native plugin-management request must not load heavy plugin class' );
native_nav_assert( count( $GLOBALS['native_nav_hooks']['admin_menu'] ?? array() ) === 1,
    'Native admin menu registration hook missing' );
foreach ( $GLOBALS['native_nav_hooks']['admin_menu'] as $hook ) call_user_func( $hook[0] );
native_nav_assert( count( $GLOBALS['native_nav_menus'] ) === 1,
    'Exactly one MAD4B parent menu expected' );
native_nav_assert( $GLOBALS['native_nav_menus'][0]['slug'] === 'mad4b-control-plane'
    && $GLOBALS['native_nav_menus'][0]['cap'] === 'manage_options',
    'Parent slug/capability must match canonical admin UI' );
$expected = array_keys( MAD4B_SCP_Admin_Navigation_Manifest::menu_rows() );
native_nav_assert( count( $expected ) >= 20, 'Native shell must reflect the complete presentation-only workspace inventory' );
$actual = array_column( $GLOBALS['native_nav_submenus'], 'slug' );
native_nav_assert( $expected === $actual, 'Child routes mismatch canonical MAD4B admin slugs' );
native_nav_assert( count( array_filter( $GLOBALS['native_nav_submenus'],
    static function ( $row ) { return $row['cap'] !== 'manage_options'; } ) ) === 0,
    'Native shell must not expose weaker capability' );
echo "PASS: native plugin list retains MAD4B sidebar without heavy lifecycle\n";
