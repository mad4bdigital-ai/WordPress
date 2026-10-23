<?php
/**
 * Contract regression for VPS-local Local Client code ported to master-era MCP.
 * Standalone fixtures only: no WordPress/bootstrap, DB, HTTP or credentials.
 */
define( 'ABSPATH', '/tmp/mad4b-wordpress-isolated/' );
define( 'MAD4B_LOCAL_CLIENT_SITE_UUID', '49c562d1-8f2f-456f-b454-26816c6ba4cb' );
$GLOBALS['local_test'] = array(
    'environment' => 'production', 'origin' => true, 'urls' => true,
    'user_cap' => true, 'policy' => true, 'write' => false, 'actions' => array(),
);
function wp_get_environment_type() { return $GLOBALS['local_test']['environment']; }
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['local_test']['user_cap']; }
function get_current_user_id() { return 4; }
function add_action( $hook, $handler ) { $GLOBALS['local_test']['actions'][] = $hook; }
function wp_die( $message, $title = '', $args = array() ) {
    throw new RuntimeException( (string) $message );
}
class MAD4B_SCP_Site_Profile {
    public static function site_uuid() { return '49c562d1-8f2f-456f-b454-26816c6ba4cb'; }
    public static function origin_enrolled() { return $GLOBALS['local_test']['origin']; }
    public static function site_urls_match_enrollment() { return $GLOBALS['local_test']['urls']; }
}
class MAD4B_SCP_Policy {
    public static function can_connect_user( $id ) { return 4 === $id && $GLOBALS['local_test']['policy']; }
    public static function mutation_gate_status() { return array( 'effective' => $GLOBALS['local_test']['write'] ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-client.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-client-admin.php';

function expect_local( $assertion, $message ) {
    if ( ! $assertion ) {
        fwrite( STDERR, "FAIL: " . $message . PHP_EOL );
        exit( 1 );
    }
}
expect_local( ! MAD4B_SCP_Local_Client::bound(), 'Production denied' );
expect_local( ! MAD4B_SCP_Local_Client::can_local_write(), 'Production write denied' );
expect_local( 0 === count( $GLOBALS['local_test']['actions'] ), 'No Production admin hooks' );
$GLOBALS['local_test']['environment'] = 'staging';
expect_local( ! MAD4B_SCP_Local_Client::bound(), 'Staging denied' );
$GLOBALS['local_test']['environment'] = 'local';
expect_local( MAD4B_SCP_Local_Client::bound(), 'Exact local site UUID accepted' );
expect_local( MAD4B_SCP_Local_Client::can_local_admin(), 'Current approved local admin' );
expect_local( ! MAD4B_SCP_Local_Client::can_local_write(), 'Write unavailable without effective gate' );
$GLOBALS['local_test']['write'] = true;
expect_local( MAD4B_SCP_Local_Client::can_local_write(), 'Write requires effective existing policy' );
$GLOBALS['local_test']['origin'] = false;
expect_local( ! MAD4B_SCP_Local_Client::bound(), 'Unenrolled local origin denied' );
$GLOBALS['local_test']['origin'] = true;
$GLOBALS['local_test']['urls'] = false;
expect_local( ! MAD4B_SCP_Local_Client::bound(), 'Origin URLs mismatch denied' );
$GLOBALS['local_test']['urls'] = true;
$GLOBALS['local_test']['user_cap'] = false;
expect_local( ! MAD4B_SCP_Local_Client::can_local_admin(), 'Missing user capabilities denied' );
$GLOBALS['local_test']['user_cap'] = true;
$GLOBALS['local_test']['policy'] = false;
expect_local( ! MAD4B_SCP_Local_Client::can_local_admin(), 'Existing policy rejection preserved' );
$GLOBALS['local_test']['policy'] = true;
MAD4B_SCP_Local_Client_Admin::boot();
expect_local( 2 === count( $GLOBALS['local_test']['actions'] ), 'Explicit Local admin hooks registered only on local' );
$status = MAD4B_SCP_Local_Client::status();
foreach ( array( 'oauth_authority_inherited', 'governed_write_convergence_inherited', 'developer_breakglass_inherited' ) as $key ) {
    expect_local( false === $status[ $key ], $key . ' must not be inherited' );
}
try {
    MAD4B_SCP_Local_Client_Admin::provision();
    expect_local( false, 'Unapproved credential creation must be rejected' );
} catch ( RuntimeException $error ) {
    expect_local( 'Access denied.' === $error->getMessage(), 'Provisioning fails closed by default' );
}
$bootstrap = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );
$schema = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-schema-lifecycle.php' );
expect_local( false !== strpos( $bootstrap, "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-client.php'" ), 'Local module available via opt-in bootstrap' );
expect_local( false !== strpos( $bootstrap, "&& 'local' === wp_get_environment_type()" ), 'Bootstrap explicitly environment scoped' );
expect_local( false !== strpos( $schema, "MAD4B_SCP_Local_Client::bound()" ), 'Local schema auto scheduling must be bound' );
expect_local( false !== strpos( $schema, "defined( 'DOING_CRON' ) && DOING_CRON" ), 'Cron remains on full path' );
expect_local( false !== strpos( $schema, "defined( 'WP_CLI' ) && WP_CLI" ), 'Explicit WP-CLI remains on full schema path' );
echo "mad4b.local-client-vps-port.v1: PASS\n";
