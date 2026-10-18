<?php
/** Plugins.php popup remains lightweight, accessible and read-only. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.102' );
define( 'MAD4B_SCP_FILE', dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );
$GLOBALS['test_catalog_dir'] = sys_get_temp_dir() . '/mad4b-popup-fixture-' . getmypid() . '/';
if ( ! is_dir( $GLOBALS['test_catalog_dir'] . 'config' ) ) mkdir( $GLOBALS['test_catalog_dir'] . 'config', 0700, true );
define( 'MAD4B_SCP_DIR', $GLOBALS['test_catalog_dir'] );
$registry = array( 'contract' => 'mad4b.cross-plugin-sources.v1', 'sources' => array(
    array( 'id' => 'etg-dynamic-filter-seo-bridge',
        'label' => 'ETG Dynamic Filter SEO Bridge',
        'repository' => 'mad4bdigital-ai/WordPress',
        'plugin_file' => 'etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php',
        'review_state' => 'source_reviewed_artifact_pending', 'package_certification' => 'pending' ),
) );
file_put_contents( MAD4B_SCP_DIR . 'config/cross-plugin-sources.json', json_encode( $registry ) );
$GLOBALS['test_caps'] = array( 'manage_options' => true, 'update_plugins' => false );
$GLOBALS['test_hooks'] = array(); $GLOBALS['test_enqueue'] = array();
function is_admin() { return true; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['test_caps'][$cap] ); }
function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['test_hooks'][$hook][] = $callback; }
function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['test_hooks'][$hook][] = $callback; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function wp_get_environment_type() { return 'staging'; }
function admin_url( $path ) { return 'https://site.example.test/wp-admin/' . $path; }
function plugins_url( $path, $plugin ) { return 'https://site.example.test/wp-content/plugins/mad4b-site-control-plane/' . $path; }
function esc_url( $x ) { return htmlspecialchars( $x, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $x ) { return htmlspecialchars( $x, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $x, $domain = '' ) { return esc_html( $x ); }
function esc_attr( $x ) { return htmlspecialchars( $x, ENT_QUOTES, 'UTF-8' ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="test-only">'; }
function wp_enqueue_script( $name, $url, $deps, $version, $footer ) { $GLOBALS['test_enqueue'][] = $name; }
function wp_enqueue_style( $name, $url, $deps, $version ) { $GLOBALS['test_enqueue'][] = $name; }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-plugin-update-popup.php';
function verify_popup( $pass, $label ) {
    if ( ! $pass ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
MAD4B_SCP_Plugin_Update_Popup::boot();
MAD4B_SCP_Plugin_Update_Popup::boot();
verify_popup( count( $GLOBALS['test_hooks']['plugin_action_links'] ) === 1, 'idempotent registration' );
verify_popup( isset( $GLOBALS['test_hooks']['admin_footer-plugins.php'] ), 'native footer dialog hook absent' );
verify_popup( isset( $GLOBALS['test_hooks']['admin_enqueue_scripts'] ), 'native assets hook absent' );
$class = 'MAD4B_SCP_Plugin_Update_Popup';
$core = $class::plugin_action_links( array( 'deactivate' => '<a>Keep WordPress action</a>' ),
    'mad4b-site-control-plane/mad4b-site-control-plane.php', array( 'Version' => '0.4.0-rc.102' ) );
verify_popup( isset( $core['deactivate'], $core['mad4b_governed_update'] ), 'core update popup action missing' );
verify_popup( strpos( $core['mad4b_governed_update'], '<form' ) === false
    && strpos( $core['mad4b_governed_update'], 'aria-haspopup="dialog"' ) !== false,
    'link must be a valid accessible control, not nested form' );
$cross = $class::plugin_action_links( array(),
    'etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php',
    array( 'Version' => '0.4.0-alpha.13' ) );
verify_popup( isset( $cross['mad4b_governed_update'] ), 'reviewed installed external plugin not surfaced' );
verify_popup( $class::plugin_action_links( array(), 'unknown/unsafe.php' ) === array(),
    'unreviewed plugin received source-driven action' );
$class::enqueue( 'plugins.php' );
verify_popup( count( $GLOBALS['test_enqueue'] ) === 2, 'JS/CSS asset loading missing' );
$class::enqueue( 'post.php' );
verify_popup( count( $GLOBALS['test_enqueue'] ) === 2, 'popup assets loaded outside Plugins page' );
ob_start(); $class::render(); $html = ob_get_clean();
verify_popup( substr_count( $html, '<dialog ' ) === 2
    && substr_count( $html, '</dialog>' ) === 2, 'dialog markup failed' );
verify_popup( strpos( $html, 'mad4b_control_plane_refresh_update' ) !== false
    && strpos( $html, 'mad4b_cross_plugin_preview' ) !== false
    && strpos( $html, 'mad4b_staging_source_preview' ) !== false,
    'separate certified and cross-plugin read-only handlers missing' );
verify_popup( strpos( $html, 'mad4b_cross_plugin_apply' ) === false
    && strpos( $html, 'mad4b_control_plane_native_update' ) === false,
    'UI exposed direct plugin mutation from passive modal' );
verify_popup( strpos( $html, '0.4.0-alpha.13' ) !== false
    && strpos( $html, 'source_reviewed_artifact_pending' ) !== false,
    'existing plugin version/certification labels inaccurate' );
verify_popup( strpos( $html, 'data-mad4b-source-select' ) !== false
    && strpos( $html, 'data-mad4b-source-field="branch"' ) !== false,
    'Staging exact branch/commit preview fields missing' );
verify_popup( strpos( $html, 'admin-post.php' ) !== false
    && substr_count( $html, 'name="_wpnonce"' ) === 3,
    'forms must be separate and protected by WordPress nonces' );
$GLOBALS['test_caps']['manage_options'] = false;
verify_popup( $class::plugin_action_links( array(), 'unknown/unsafe.php' ) === array()
    && $class::plugin_action_links( array(), 'mad4b-site-control-plane/mad4b-site-control-plane.php' ) === array(),
    'unprivileged user sees popup entry' );
ob_start(); $class::render(); $denied = ob_get_clean();
verify_popup( '' === $denied, 'unprivileged user can render dialogs' );
$GLOBALS['test_caps']['update_plugins'] = true;
$inspect = $class::plugin_action_links( array(), 'mad4b-site-control-plane/mad4b-site-control-plane.php' );
verify_popup( isset( $inspect['mad4b_governed_update'] ), 'plugin updater cannot inspect MAD4B' );
verify_popup( $class::plugin_action_links( array(), 'etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php' ) === array(),
    'cross plugin should retain admin-only inspection' );
unlink( MAD4B_SCP_DIR . 'config/cross-plugin-sources.json' );
rmdir( MAD4B_SCP_DIR . 'config' ); rmdir( MAD4B_SCP_DIR );
echo "MAD4B_PLUGIN_UPDATE_POPUP: PASS\n";
