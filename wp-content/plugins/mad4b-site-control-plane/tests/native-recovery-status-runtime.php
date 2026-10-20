<?php
/** Pure read-only WordPress Site Health + existing Query Monitor bridge model. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['allowed'] = true;
$GLOBALS['administrator'] = false;
$GLOBALS['registered_abilities'] = array();
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['native_hooks'][] = $hook; }
function current_user_can( $cap ) { return $GLOBALS['administrator']; }
function wp_register_ability( $name, $args ) { $GLOBALS['registered_abilities'][ $name ] = $args; }
function wp_has_ability( $name ) { return isset( $GLOBALS['registered_abilities'][ $name ] ); }
function wp_salt( $ctx ) { return 'fixture-secret'; }
function get_option( $k, $fallback = false ) { return array(); }
function is_admin() { return false; }
class WP_Error { public $code; public function __construct( $code ) { $this->code = $code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class MAD4B_SCP_Policy { public static function can_read() { return $GLOBALS['allowed']; } }
class MAD4B_SCP_Site_Profile { public static function status() { return array( 'configured_environment' => 'staging', 'origin_match' => true, 'environment_match' => true ); } }
class MAD4B_SCP_Query_Monitor_Evidence_Bridge {}
class MAD4B_SCP_Frontend_Origin_Diagnostic {}
require __DIR__ . '/../bootstrap/mad4b-native-incident-mu.php';
require __DIR__ . '/../includes/class-mad4b-scp-native-runtime-diagnostics.php';
$check = static function ( $ok, $message ) { if ( ! $ok ) throw new RuntimeException( $message ); };
MAD4B_SCP_Native_Runtime_Diagnostics::boot();
MAD4B_SCP_Native_Runtime_Diagnostics::register_ability();
// Both services must coexist; diagnostics cannot replace pending scratch jobs
// or remove their source-owned plan/readback/cleanup capability contracts.
require __DIR__ . '/../includes/class-mad4b-scp-native-recovery.php';
MAD4B_SCP_Native_Recovery::register_abilities();
foreach ( array( 'mad4b/native-recovery-status', 'mad4b/native-recovery-plan',
    'mad4b/native-recovery-start', 'mad4b/native-recovery-readback', 'mad4b/native-recovery-cleanup' ) as $legacy ) {
    $check( isset( $GLOBALS['registered_abilities'][$legacy] ), 'Existing native recovery ability removed: ' . $legacy );
}
$check( MAD4B_SCP_Native_Recovery::STATUS !== MAD4B_SCP_Native_Runtime_Diagnostics::ABILITY,
    'Diagnostics reused the scratch recovery ability identity' );
$check( isset( $GLOBALS['registered_abilities'][MAD4B_SCP_Native_Runtime_Diagnostics::ABILITY] ), 'governed read ability unavailable' );
$meta = $GLOBALS['registered_abilities'][MAD4B_SCP_Native_Runtime_Diagnostics::ABILITY]['meta'];
$check( $meta['annotations']['readonly'] && ! $meta['show_in_rest'], 'remote read surface misclassified' );
$status = MAD4B_SCP_Native_Runtime_Diagnostics::status();
$check( ! is_wp_error( $status ) && $status['site_profile_staging_enrolled'] && $status['read_only'] && ! $status['authority_granted'], 'status not restricted to verified read evidence' );
$check( 'QUERY_MONITOR_NOT_LOADED_IN_THIS_REQUEST' === $status['query_monitor']['status'] && ! $status['query_monitor']['collector_snapshot_taken_in_this_read'], 'Query Monitor unknown runtime mislabeled absent' );
$check( 'RECORDER_NOT_ACTIVE' === $status['incidents']['state'], 'fallback fatal recorder state incorrect' );
$probe = MAD4B_SCP_Native_Runtime_Diagnostics::project_probe( array( 'state' => 'FRONTEND_LOOPBACK_5XX_REST_REACHABLE', 'results' => array( 'homepage' => array( 'http_status' => 500, 'body' => 'SECRET_UNSAFE' ), 'rest_root' => array( 'http_status' => 200 ) ) ) );
$check( 500 === $probe['homepage']['http_status'] && 200 === $probe['rest_root']['http_status'] && false === $probe['root_cause_proven'], '500/200 drift label false acceptance' );
$check( strpos( json_encode( $probe ), 'SECRET_UNSAFE' ) === false, 'HTTP body entered persisted projection' );
$GLOBALS['allowed'] = false;
$check( is_wp_error( MAD4B_SCP_Native_Runtime_Diagnostics::status() ), 'read denied identity was permitted' );
$GLOBALS['administrator'] = true;
$check( ! is_wp_error( MAD4B_SCP_Native_Runtime_Diagnostics::status() ), 'local admin could not read native diagnostics' );
$ui = file_get_contents( __DIR__ . '/../includes/class-mad4b-scp-runtime-components-admin-ui.php' );
$check( strpos( $ui, "'diagnostics' => __( 'Diagnostics & Recovery'" ) !== false, 'missing native diagnostics tab' );
$check( strpos( $ui, 'wp_nonce_field( MAD4B_SCP_Native_Runtime_Diagnostics::NONCE_ACTION )' ) !== false, 'admin POST missing nonce' );
$check( strpos( $ui, "'site-health.php'" ) !== false && strpos( $ui, 'plugins.php?s=query-monitor' ) !== false, 'native WordPress tools not linked' );
echo "PASS native WordPress Recovery: registered MCP read, QM reuse, Site Health, 500/200 projection, nonce UI, fail-closed permissions\n";

$GLOBALS['allowed'] = new WP_Error('policy_failed');
$GLOBALS['administrator'] = false;
$check(is_wp_error(MAD4B_SCP_Native_Runtime_Diagnostics::status()), 'WP_Error policy denial became truthy diagnostic access');
