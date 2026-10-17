<?php
/* WordPress native plugins.php zero-touch UI route and role split. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
$GLOBALS['wp_environment'] = 'staging';
function wp_get_environment_type() { return $GLOBALS['wp_environment']; }
function is_admin() { return true; }
function __( $label, $domain = '' ) { return $label; }
function esc_html__( $label, $domain = '' ) { return $label; }
function esc_url( $value ) { return $value; }
function admin_url( $path ) { return 'https://staging.example.test/wp-admin/' . $path; }
function register_deactivation_hook( $file, $callback ) { return true; }
$GLOBALS['hook_filters'] = array();
$GLOBALS['can_manage'] = true;
$GLOBALS['can_activate'] = true;
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
    $GLOBALS['hook_filters'][ $hook ][] = $callback;
}
function current_user_can( $cap ) {
    if ( 'manage_options' === $cap ) return $GLOBALS['can_manage'];
    if ( 'activate_plugins' === $cap ) return $GLOBALS['can_activate'];
    return false;
}
function add_action( $hook, $callback, $priority = 10 ) {}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array(); $_REQUEST = array();
$GLOBALS['pagenow'] = 'plugins.php';
require dirname( __DIR__ ) . '/mad4b-site-control-plane.php';
function check_setup( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
check_setup( ! class_exists( 'MAD4B_SCP_Plugin', false ), 'heavy runtime loaded during plugin list' );
$hook = 'plugin_action_links_mad4b-site-control-plane/mad4b-site-control-plane.php';
check_setup( count( $GLOBALS['hook_filters'][ $hook ] ?? array() ) === 1, 'missing lightweight plugin row hook' );
$renderer = $GLOBALS['hook_filters'][ $hook ][0];
$out = call_user_func( $renderer, array( 'deactivate' => 'Existing WordPress link' ) );
check_setup( count( $out ) === 2 && isset( $out['mad4b_host_setup'] ), 'exact plugin row link absent' );
check_setup( strpos( $out['mad4b_host_setup'], 'admin.php?page=mad4b-operational-remediation' ) !== false,
    'must link to the fully bootstrapped remediation page' );
check_setup( strpos( $out['mad4b_host_setup'], '<form' ) === false
    && strpos( $out['mad4b_host_setup'], '<a href=' ) !== false,
    'WordPress plugin bulk form cannot contain a nested form' );
$GLOBALS['can_activate'] = false;
check_setup( count( call_user_func( $renderer, array() ) ) === 0, 'non-activating user sees setup action' );
$GLOBALS['can_activate'] = true; $GLOBALS['can_manage'] = false;
check_setup( count( call_user_func( $renderer, array() ) ) === 0, 'non-admin user sees setup action' );
$GLOBALS['can_manage'] = true;
$GLOBALS['wp_environment'] = 'production';
$prod = call_user_func( $renderer, array() );
check_setup( isset( $prod['mad4b_host_setup'] )
    && strpos( $prod['mad4b_host_setup'], 'Inspect Production host binding' ) !== false
    && strpos( $prod['mad4b_host_setup'], '#mad4b-production-deployment-identity' ) !== false
    && strpos( $prod['mad4b_host_setup'], 'Complete Staging host setup' ) === false,
    'Production must show only a read-only inspection entry point' );
$GLOBALS['wp_environment'] = 'development';
check_setup( !isset( call_user_func( $renderer, array() )['mad4b_host_setup'] ),
    'unknown/development environments must not advertise Staging setup' );
echo "NATIVE_PLUGIN_LIGHT_SETUP_ROUTE_AND_CAPABILITIES: PASS\n";
