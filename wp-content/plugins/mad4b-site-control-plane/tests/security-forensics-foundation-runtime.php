<?php
/**
 * Standalone PHP 7.4/8.3 smoke and boundary tests: no WordPress, DB or network.
 * Run: php wp-content/plugins/mad4b-site-control-plane/tests/security-forensics-foundation-runtime.php
 */
error_reporting( E_ALL );
$root = sys_get_temp_dir() . '/mad4b-security-fixture-' . bin2hex( random_bytes( 5 ) );
mkdir( $root . '/wp-content/uploads/2026/10', 0700, true );
mkdir( $root . '/wp-content/plugins', 0700, true );
file_put_contents( $root . '/wp-content/uploads/2026/10/fixture.php', '<?php echo "fixture";' );
define( 'ABSPATH', $root . '/' );
$GLOBALS['actions'] = array();
$GLOBALS['abilities'] = array();
$GLOBALS['audit_rows'] = array();
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['actions'][ $hook ][] = $cb; }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function get_current_user_id() { return 5; }
function is_admin() { return true; }
function wp_get_environment_type() { return 'staging'; }
function get_current_blog_id() { return 17; }
function wp_generate_uuid4() { return 'fixture-request'; }
function wp_has_ability( $key ) { return isset( $GLOBALS['abilities'][ $key ] ); }
function wp_register_ability( $key, $config ) { $GLOBALS['abilities'][ $key ] = $config; return (object) $config; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
    public $code;
    public function __construct( $code, $message ) { $this->code = $code; }
}
class MAD4B_SCP_Security_Diagnostics { public static function can_read() { return true; } }
class MAD4B_SCP_Site_Profile {
    public static function site_uuid() { return 'registered-staging-site-uuid'; }
    public static function current_environment() { return 'staging'; }
}
class MAD4B_SCP_Admin_Route_Registry {
    public static $routes = array();
    public static function register( $slug, $cap ) {
        self::$routes[ $slug ] = $cap;
        return true;
    }
    public static function schedule_submenu( $callback, $priority ) {
        $GLOBALS['actions']['admin_menu'][] = array( 'callback' => $callback, 'priority' => $priority );
    }
}
class MAD4B_SCP_Audit {
    public static function record( $ability, $summary, $status ) {
        $GLOBALS['audit_rows'][] = compact( 'ability', 'summary', 'status' );
        return array( 'sequence' => count( $GLOBALS['audit_rows'] ) );
    }
    public static function storage_status() { return array( 'ready' => true ); }
    public static function tail( $limit ) {
        $rows = array();
        foreach ( $GLOBALS['audit_rows'] as $i => $row )
            $rows[] = array( 'ability' => $row['ability'], 'sequence' => $i + 1, 'entry_hash' => str_repeat( 'a', 64 ) );
        return array_slice( $rows, -$limit );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-security-forensics.php';
function ok( $state, $description ) { if ( ! $state ) { fwrite( STDERR, "FAIL: $description\n" ); exit( 1 ); } }
MAD4B_SCP_Security_Forensics::boot();
MAD4B_SCP_Security_Forensics::boot();
ok( count( $GLOBALS['actions']['activated_plugin'] ) === 1, 'idempotent hooks' );
ok( MAD4B_SCP_Admin_Route_Registry::$routes['mad4b-control-plane-security'] === 'manage_options', 'uses central route registry' );
ok( $GLOBALS['actions']['admin_menu'][0]['priority'] >= 20, 'parent menu order respected' );
MAD4B_SCP_Security_Forensics::register_abilities();
ok( count( $GLOBALS['abilities'] ) === 8, 'exactly eight read/plan abilities' );
foreach ( $GLOBALS['abilities'] as $ability => $config ) {
    ok( $config['meta']['mcp']['non_authorizing'] === true, 'no authorizing ability' );
    ok( $config['meta']['annotations']['destructive'] === false, 'no destructive ability' );
}
ok( ! wp_has_ability( 'mad4b/security-quarantine-apply' ), 'no quarantine apply' );
$bad = MAD4B_SCP_Security_Forensics::integrity_scan( array( 'scope' => '../../etc' ) );
ok( is_wp_error( $bad ), 'reject traversal scope' );
$scan = MAD4B_SCP_Security_Forensics::integrity_scan( array( 'scope' => 'uploads' ) );
ok( $scan['site_clean_certified'] === false && $scan['baseline_approved'] === false, 'no premature certification' );
ok( $scan['coverage_complete'] === false && $scan['file_changes_certified'] === false, 'no false completeness' );
ok( $scan['site_id'] === 'registered-staging-site-uuid' && $scan['environment'] === 'staging', 'governed site scope and environment' );
ok( $scan['files'][0]['classification'] === 'suspicious_placement_unconfirmed', 'PHP in uploads triage' );
$link = $root . '/wp-content/uploads/pivot';
if ( @symlink( sys_get_temp_dir(), $link ) ) {
    $scan = MAD4B_SCP_Security_Forensics::integrity_scan( array( 'scope' => 'uploads' ) );
    ok( strpos( json_encode( $scan ), '"kind":"symlink_unfollowed"' ) !== false, 'never follow symbolic links' );
}
file_put_contents( $root . '/wp-config.php', '<?php define("DB_PASSWORD", "DO_NOT_READ_ME");' );
$root_scan = MAD4B_SCP_Security_Forensics::integrity_scan( array( 'scope' => 'root' ) );
ok( strpos( json_encode( $root_scan ), 'wp-config.php' ) === false, 'no secret configuration path or digest read' );
ok( $root_scan['coverage'] === 'partial', 'excluded secrets yield honest partial coverage' );
unlink( $root . '/wp-config.php' );
$guardian = MAD4B_SCP_Security_Forensics::plugin_guardian();
ok( $guardian['confirmed_malicious'] === false && $guardian['trusted_package_verification'] === 'not_configured', 'unknown plugin never auto-malicious' );
MAD4B_SCP_Security_Forensics::activated( 'plugin-editor-pro/plugin-editor-pro.php', false );
MAD4B_SCP_Security_Forensics::role_changed( 11, 'administrator' );
MAD4B_SCP_Security_Forensics::setting_changed( 'siteurl', 'SECRET_BEFORE', 'SECRET_AFTER' );
ok( count( $GLOBALS['audit_rows'] ) === 3, 'three security audit events' );
foreach ( $GLOBALS['audit_rows'] as $entry ) {
    $s = json_encode( $entry );
    ok( strpos( $s, 'SECRET_' ) === false, 'exclude sensitive option values' );
    ok( $entry['summary']['authorization'] === 'unverified', 'no inferred authorization' );
    ok( $entry['summary']['ssh_actor_inferred'] === false, 'no invented SSH actor' );
}
$events = MAD4B_SCP_Security_Forensics::events( array( 'limit' => 2 ) );
ok( count( $events['events'] ) === 2, 'query bounded Audit v2 envelope' );
ok( ! isset( $events['events'][0]['summary'] ), 'no raw audit summary leak' );
$plan = MAD4B_SCP_Security_Forensics::quarantine_plan();
ok( $plan['apply_ability_registered'] === false, 'quarantine always plan-only' );
$security_status = MAD4B_SCP_Security_Forensics::status();
ok( $security_status['release_ready'] === false, 'not release certified' );
ok( $security_status['capability_discovery']['capability_presence_authorizes'] === false, 'discovery does not authorize' );
ok( $security_status['capability_discovery']['version_pin_required'] === false, 'no exact provider version pin' );
unlink( $root . '/wp-content/uploads/2026/10/fixture.php' );
rmdir( $root . '/wp-content/uploads/2026/10' );
rmdir( $root . '/wp-content/uploads/2026' );
if ( file_exists( $link ) || is_link( $link ) ) unlink( $link );
rmdir( $root . '/wp-content/uploads' );
rmdir( $root . '/wp-content/plugins' );
rmdir( $root . '/wp-content' );
rmdir( $root );
echo "SECURITY_FORENSICS_FOUNDATION: PASS (PHP hooks, read abilities, path denial, no destructive grants)\n";
