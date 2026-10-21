<?php
// Actual PHP fixture: lazy page, narrow dispatch, redaction and measurements.
define( 'ABSPATH', __DIR__ );
define( 'MAD4B_SCP_FILE', __FILE__ );
define( 'MAD4B_SCP_VERSION', 'fixture' );
$GLOBALS['allowed'] = true;
$GLOBALS['nonce'] = true;
$GLOBALS['queries'] = 10;
$GLOBALS['calls'] = array();
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['allowed']; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function wp_enqueue_script() {}
function plugins_url( $path ) { return $path; }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function wp_create_nonce() { return 'fixture'; }
function esc_url( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function get_num_queries() { return $GLOBALS['queries']; }
function check_ajax_referer() { return $GLOBALS['nonce']; }
function nocache_headers() {}
function wp_send_json_error( $data, $status ) { $GLOBALS['response'] = array( false, $status, $data ); }
function wp_send_json_success( $data ) { $GLOBALS['response'] = array( true, 200, $data ); }
function report( $key ) { if ( ! empty( $GLOBALS['slow'] ) ) usleep( 5100000 ); $GLOBALS['calls'][] = $key; $GLOBALS['queries'] += 3; return array( 'ready' => true, 'secret' => 'must-not-leak' ); }
class MAD4B_SCP_Staging_Write_Authority { static function current_execution_readiness() { return report( 'write' ); } }
class MAD4B_SCP_Database_Topology { static function status( $deep ) { check( false === $deep, 'database remains shallow' ); return report( 'database' ); } }
class MAD4B_SCP_Full_Staging_Authority { static function status() { report( 'authority' ); echo 'secret'; throw new Exception( 'private provider message' ); } }
class MAD4B_SCP_Provider_Closure_Matrix { static function matrix() { report( 'providers' ); return array( 'ready' => 'yes' ); } }
class MAD4B_SCP_Adaptive_Runtime_Convergence { static function status( $input ) { check( array( 'include_capabilities' => false, 'limit' => 5 ) === $input, 'bounded adaptive' ); report( 'adaptive' ); return array(); } }
function check( $ok, $label ) { if ( ! $ok ) throw new Exception( 'FAIL: ' . $label ); }
require __DIR__ . '/../includes/class-mad4b-scp-deferred-admin-reports.php';
require __DIR__ . '/../includes/class-mad4b-scp-operator-control-center.php';
require __DIR__ . '/../includes/class-mad4b-scp-chatgpt-connection-admin-ui.php';
foreach ( array( 'MAD4B_SCP_Operator_Control_Center', 'MAD4B_SCP_ChatGPT_Connection_Admin_UI' ) as $class ) {
 ob_start(); $class::render_page(); $html = ob_get_clean();
 check( false !== strpos( $html, 'Not checked' ), 'initial unknown state' );
 check( empty( $GLOBALS['calls'] ), 'no report on initial GET' );
}
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( 'report' => 'write' );
foreach ( array( 'method', 'capability', 'nonce', 'selector', 'array' ) as $failure ) {
 $_SERVER['REQUEST_METHOD'] = 'method' === $failure ? 'GET' : 'POST';
 $GLOBALS['allowed'] = 'capability' !== $failure; $GLOBALS['nonce'] = 'nonce' !== $failure;
 $_POST['report'] = 'selector' === $failure ? 'arbitrary_callback' : ( 'array' === $failure ? array( 'write' ) : 'write' );
 MAD4B_SCP_Deferred_Admin_Reports::ajax();
 check( false === $GLOBALS['response'][0] && empty( $GLOBALS['calls'] ), 'guard rejects before callback: ' . $failure );
}
$GLOBALS['allowed'] = true; $GLOBALS['nonce'] = true;
foreach ( array( 'write', 'database', 'authority', 'providers', 'adaptive' ) as $key ) {
 $GLOBALS['calls'] = array(); $_POST['report'] = $key;
 ob_start(); MAD4B_SCP_Deferred_Admin_Reports::ajax(); $noise = ob_get_clean();
 $data = $GLOBALS['response'][2];
 check( array( $key ) === $GLOBALS['calls'], 'exact single callback' );
 check( 3 === $data['query_count'] && $data['elapsed_ms'] >= 0, 'component metrics' );
 check( '' === $noise && false === strpos( json_encode( $data ), 'secret' ), 'provider output redacted' );
 if ( in_array( $key, array( 'authority', 'providers', 'adaptive' ), true ) ) check( null === $data['ready'], 'unknown is not ready' );
 if ( 'authority' === $key ) check( 'REPORT_FAILED' === $data['state'], 'exception contained' );
}
$GLOBALS['slow'] = true;
$slow = MAD4B_SCP_Deferred_Admin_Reports::measure( 'write' );
check( 'BUDGET_EXCEEDED' === $slow['state'] && null === $slow['ready'], 'slow report cannot claim readiness' );
$GLOBALS['allowed'] = false;
try { MAD4B_SCP_Operator_Control_Center::render_page(); throw new Exception( 'unguarded page' ); }
catch ( RuntimeException $expected ) {}
echo "DEFERRED_ADMIN_REPORTS_RUNTIME: PASS\n";
