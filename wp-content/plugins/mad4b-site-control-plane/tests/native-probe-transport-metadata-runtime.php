<?php
/** Regression: a failed safe loopback probe keeps actionable metadata after an admin refresh. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['current_admin'] = true;
function current_user_can( $cap ) { return $GLOBALS['current_admin'] && $cap === 'manage_options'; }
function get_current_user_id() { return 1; }
function get_transient( $key ) { return $GLOBALS['saved_probe'] ?? null; }
require __DIR__ . '/../includes/class-mad4b-scp-native-runtime-diagnostics.php';
$check = static function ( $ok, $message ) { if ( ! $ok ) throw new RuntimeException( $message ); };
$raw = array( 'state' => 'PAIRED_HTTP_INCONCLUSIVE', 'results' => array(
  'homepage' => array( 'state' => 'HTTP_PROBE_FAILED', 'http_status' => 0,
    'transport_error_class' => 'network_timeout', 'elapsed_ms' => 6001.57,
    'network_timing' => array('dns_complete_ms'=>10.5,'tcp_complete_ms'=>20.5,'tls_complete_ms'=>30.5,'first_byte_ms'=>null,'total_ms'=>6001.57,'url'=>'SECRET_URL'),
    'message' => 'SECRET_PRIVATE_URL_OR_TOKEN' ),
  'rest_root' => array( 'http_status' => 200, 'elapsed_ms' => 120.23, 'body' => 'SECRET_HTTP_BODY' ),
) );
$saved = MAD4B_SCP_Native_Runtime_Diagnostics::project_probe( $raw );
$check( $saved['homepage']['http_status'] === 0 && $saved['homepage']['transport_error_class'] === 'network_timeout'
    && $saved['rest_root']['http_status'] === 200 && $saved['homepage']['elapsed_ms'] === 6001.57,
    'ETG failure class or timing was lost during projection' );
$check($saved['homepage']['network_timing']['tls_complete_ms']===30.5 && $saved['homepage']['network_timing']['first_byte_ms']===null, 'Phase timing lost');
$check( $saved === MAD4B_SCP_Native_Runtime_Diagnostics::project_probe( $saved ), 'Projection is not idempotent' );
$GLOBALS['saved_probe'] = $saved;
$refresh = MAD4B_SCP_Native_Runtime_Diagnostics::last_local_probe();
$check( $refresh['homepage'] === $saved['homepage'] && $refresh['rest_root'] === $saved['rest_root'],
    'Admin refresh lost allowlisted transport evidence' );
$check( strpos( json_encode( $saved ), 'SECRET_' ) === false, 'Private response/request data leaked' );
$invalid = MAD4B_SCP_Native_Runtime_Diagnostics::project_probe( array( 'state' => 'PAIRED_HTTP_INCONCLUSIVE',
    'homepage' => array( 'http_status' => 0, 'transport_error' => true,
        'transport_error_class' => '<script>unsafe</script>', 'elapsed_ms' => -500 ),
    'rest_root' => array( 'http_status' => 200 ) ) );
$check( $invalid['homepage']['transport_error_class'] === 'unknown_transport_failure'
    && $invalid['homepage']['elapsed_ms'] === 0.0
    && strpos( json_encode( $invalid ), 'script' ) === false, 'Untrusted metadata was exposed' );
$legacy = MAD4B_SCP_Native_Runtime_Diagnostics::project_probe( array(
    'state' => 'PAIRED_HTTP_INCONCLUSIVE',
    'homepage' => array( 'http_status' => 0, 'transport_error' => true ),
    'rest_root' => array( 'http_status' => 200 ) ) );
$check( $legacy['homepage']['transport_error_class'] === 'unknown_transport_failure',
    'Old transient incorrectly fabricated a specific network error' );
$GLOBALS['current_admin'] = false;
$check( MAD4B_SCP_Native_Runtime_Diagnostics::last_local_probe() === array(),
    'Unauthorised user observed admin-only transient' );
$ui = file_get_contents( __DIR__ . '/../includes/class-mad4b-scp-runtime-components-admin-ui.php' );
$check( strpos( $ui, '(no HTTP response)' ) !== false
    && strpos( $ui, 'transport_error_class' ) !== false,
    'WordPress admin UI still hides HTTP 0 transport classification' );
echo "PASS native staging probe transport classification, sanitizer, persistence, admin projection\n";
