<?php
/**
 * Native WordPress plugin-management hotpath smoke.
 * Isolated PHP 7.4+ test: no DB, WordPress boot, network, file write or mutation.
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function plugin_dir_path( $file ) { return dirname( (string) $file ) . '/'; }
function is_admin() { return true; }
$GLOBALS['mad4b_deactivation_hook_registered'] = false;
function register_deactivation_hook( $file, $callback ) {
    $GLOBALS['mad4b_deactivation_hook_registered'] = is_callable( $callback );
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array( 'tab' => 'upload' );
$_POST = array();
$_REQUEST = $_GET;
$GLOBALS['pagenow'] = 'plugin-install.php';

require dirname( __DIR__ ) . '/mad4b-site-control-plane.php';

function native_hotpath_expect( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
native_hotpath_expect( defined( 'MAD4B_SCP_EARLY_ZERO_TOUCH_REASON' )
    && 'wordpress_plugin_management_read' === MAD4B_SCP_EARLY_ZERO_TOUCH_REASON,
    'Plugin-install GET must terminate before full Control Plane bootstrap' );
native_hotpath_expect( ! class_exists( 'MAD4B_SCP_Plugin', false ),
    'Native plugin-install GET unexpectedly parsed the heavy main plugin class' );
native_hotpath_expect( $GLOBALS['mad4b_deactivation_hook_registered'],
    'Read-only fastpath must retain native deactivation hook' );
$source = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );
native_hotpath_expect( is_string( $source )
    && false !== strpos( $source, "in_array( \$mad4b_native_plugin_method, array( 'GET', 'HEAD' ), true )" )
    && false !== strpos( $source, "'' === \$mad4b_native_plugin_action" )
    && false !== strpos( $source, "array( 'plugin-install.php', 'plugins.php', 'update-core.php' )" )
    && false !== strpos( $source, "register_activation_hook( __FILE__, array( 'MAD4B_SCP_Plugin', 'activate' ) );" ),
    'Mutation request and native activation declarations must remain in full bootstrap' );
echo "PASS: plugin-install.php GET bypasses heavy boot; mutation routes and activation hooks remain preserved\n";
