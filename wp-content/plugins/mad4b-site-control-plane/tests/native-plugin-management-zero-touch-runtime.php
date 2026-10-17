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
    && false !== strpos( $source, "! \$mad4b_native_plugin_has_action" )
    && false !== strpos( $source, "array_key_exists( 'action', \$_REQUEST )" )
    && false !== strpos( $source, "array( 'plugin-install.php', 'plugins.php', 'update-core.php' )" )
    && false !== strpos( $source, "register_activation_hook( __FILE__, array( 'MAD4B_SCP_Plugin', 'activate' ) );" ),
    'Mutation request and native activation declarations must remain in full bootstrap' );
// Schema lifecycle: ordinary GET/HEAD must return before admin capability,
// database table scans, dbDelta, maintenance locks or provider repair.
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-schema-lifecycle.php';
MAD4B_SCP_Schema_Lifecycle::maybe_reconcile_admin_lifecycle();
$_SERVER['REQUEST_METHOD'] = 'HEAD';
MAD4B_SCP_Schema_Lifecycle::maybe_reconcile_admin_lifecycle();
native_hotpath_expect( ! defined( 'MAD4B_SCP_SCHEMA_REPAIR_EXECUTED' ),
    'Native admin read must not initiate schema lifecycle repair' );
// Staging activation seed continuation: isolated native fixture, no real DB,
// network, WordPress install, cron dispatch or persistent mutation.
$GLOBALS['mad4b_activation_result'] = array( 'scheduled' => true, 'state' => 'pending_safe_phases' );
$GLOBALS['mad4b_seed_bootstrap_calls'] = 0;
function is_wp_error( $x ) { return false; }
function get_option( $key, $default = false ) { return $default; }
function update_option( $key, $value, $autoload = false ) { return true; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) { return true; }
class MAD4B_SCP_Staging_OAuth_Autoconfig { public static function bootstrap() {} }
class MAD4B_SCP_Skill_Autoconfig { public static function bootstrap() {} }
class MAD4B_SCP_Staging_Write_Authority { public static function bootstrap() {} }
class MAD4B_SCP_Schema {
    public static function install_or_upgrade() { return true; }
    public static function is_ready() { return false; } // No real DB; lifecycle exits before persistence.
}
class MAD4B_SCP_Audit {
    const LEGACY_OPTION = 'mad4b_test_audit_option';
    public static function ensure_head_initialized() { return true; }
}
class MAD4B_SCP_Skill_Seeder {
    public static function bootstrap() { $GLOBALS['mad4b_seed_bootstrap_calls']++; return array( 'state' => 'ready' ); }
}
class MAD4B_SCP_Runtime_Convergence {
    public static function mark_activation_pending() { return $GLOBALS['mad4b_activation_result']; }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-plugin.php';
MAD4B_SCP_Plugin::activate();
native_hotpath_expect( 0 === $GLOBALS['mad4b_seed_bootstrap_calls'],
    'Queued exact Staging convergence must defer expensive inline Skill seeding' );
$GLOBALS['mad4b_activation_result'] = array( 'scheduled' => false, 'state' => 'pending_manual_resume' );
MAD4B_SCP_Plugin::activate();
native_hotpath_expect( 1 === $GLOBALS['mad4b_seed_bootstrap_calls'],
    'Failed scheduled continuation must retain fail-closed inline Skill bootstrap' );
$GLOBALS['mad4b_activation_result'] = array( 'scheduled' => true, 'state' => 'self_update_checkpoint_preserved' );
MAD4B_SCP_Plugin::activate();
native_hotpath_expect( 2 === $GLOBALS['mad4b_seed_bootstrap_calls'],
    'Unrelated self-update checkpoint must not be treated as an activation seed receipt' );
$GLOBALS['mad4b_activation_result'] = array( 'scheduled' => false, 'state' => 'observe_only_non_staging' );
MAD4B_SCP_Plugin::activate();
native_hotpath_expect( 3 === $GLOBALS['mad4b_seed_bootstrap_calls'],
    'Non-Staging activation must preserve original inline Skill seeding' );
echo "PASS: native wp-admin zero-touch plus Staging scheduled-seed activation/fallback\n";

