<?php
/** Offline, deterministic operator-session smoke suite. Never executes host commands. */
define( 'ABSPATH', __DIR__ . '/../' );
define( 'MAD4B_MCP_OPERATOR_SESSIONS_ENABLED', true );

class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_action( ...$a ) {}
function wp_has_ability( $name ) { return false; }
function wp_register_ability( ...$a ) {}
function get_option( $k, $default = '' ) { return $default; }
function current_user_can( $cap ) { return true; }
function get_current_user_id() { return 17; }
function wp_generate_uuid4() { return sprintf( '00000000-0000-4000-8000-%012d', ++$GLOBALS['counter'] ); }
$GLOBALS['counter'] = 0;
$GLOBALS['transients'] = array();
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][$key] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['transients'][$key] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][$key] ); return true; }

class MAD4B_SCP_Developer_Runtime {
    public static function kill_switch_enabled() { return false; }
    public static function developer_flag_enabled() { return true; }
    public static function configured_agent_public_id() { return '00000000-0000-4000-8000-000000000007'; }
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    public static function verified_bearer_active() { return ! empty( $GLOBALS['bearer_valid'] ); }
}
class MAD4B_SCP_Site_Profile {
    public static function configured() { return true; }
    public static function current_environment() { return $GLOBALS['environment']; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function user_is_enrolled( $id ) { return 17 === $id; }
    public static function site_uuid() { return '00000000-0000-4000-8000-000000000001'; }
}
class MAD4B_SCP_Identity_Context {
    public static function current() { return $GLOBALS['identity']; }
}
class MAD4B_SCP_Host_Bridge {
    public static function target_fingerprint_readonly() { return $GLOBALS['target_fp']; }
}
class MAD4B_SCP_Audit {
    public static function storage_status() { return array( 'ready' => true ); }
    public static function record( $name, $body, $state ) { return true; }
}
$GLOBALS['environment'] = 'staging';
$GLOBALS['bearer_valid'] = true;
$GLOBALS['target_fp'] = str_repeat( 'a', 64 );
$GLOBALS['identity'] = array(
    'authenticated' => true, 'subject_type' => 'oauth', 'auth_method' => 'oauth2_bearer',
    'subject_fingerprint' => str_repeat( 'b', 64 ), 'client_fingerprint' => str_repeat( 'c', 64 ),
    'issuer_fingerprint' => str_repeat( 'd', 64 ),
    'token_scopes' => array( 'mad4b:authority:step-up' ),
);
require_once __DIR__ . '/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-sessions.php';

function test_check( $condition, $name ) {
    $GLOBALS['passed'] = ( $GLOBALS['passed'] ?? 0 ) + 1;
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $name . PHP_EOL ); exit( 1 ); }
}
$call = MAD4B_SCP_Operator_Sessions::open( array(
    'scopes' => array( 'shell', 'wp-cli', 'sql.read' ),
    'ttl_seconds' => 120, 'reason' => 'Staging smoke check',
    'confirmation' => 'OPEN STAGING OPERATOR SESSION',
) );
test_check( ! is_wp_error( $call ), 'issue_one_session' );
test_check( isset( $call['session_token'] ) && 43 === strlen( $call['session_token'] ), 'random_bearer_length' );
test_check( $call['authorized'] === false && $call['execution_available'] === false, 'no_implicit_execution' );
$proof = array( 'session_id' => $call['session_id'], 'session_token' => $call['session_token'] );
$active = MAD4B_SCP_Operator_Sessions::status( $proof );
test_check( ! is_wp_error( $active ) && $active['eligible'] === true, 'session_status' );
test_check( ! is_wp_error( MAD4B_SCP_Operator_Sessions::verify( $proof, 'shell' ) ), 'allowed_scope' );
test_check( is_wp_error( MAD4B_SCP_Operator_Sessions::verify( $proof, 'sql.write' ) ), 'missing_scope_denied' );
$bad = $proof;
$bad['session_token'] = str_repeat( 'x', 43 );
test_check( is_wp_error( MAD4B_SCP_Operator_Sessions::status( $bad ) ), 'wrong_token_denied' );
$GLOBALS['target_fp'] = str_repeat( 'e', 64 );
test_check( is_wp_error( MAD4B_SCP_Operator_Sessions::status( $proof ) ), 'target_drift_denied' );
$GLOBALS['target_fp'] = str_repeat( 'a', 64 );
$GLOBALS['identity']['client_fingerprint'] = str_repeat( 'f', 64 );
test_check( is_wp_error( MAD4B_SCP_Operator_Sessions::status( $proof ) ), 'oauth_client_drift_denied' );
$GLOBALS['identity']['client_fingerprint'] = str_repeat( 'c', 64 );
$GLOBALS['bearer_valid'] = false;
test_check( is_wp_error( MAD4B_SCP_Operator_Sessions::status( $proof ) ), 'oauth_revocation_denied' );
$GLOBALS['bearer_valid'] = true;
$GLOBALS['environment'] = 'production';
test_check( is_wp_error( MAD4B_SCP_Operator_Sessions::status( $proof ) ), 'production_denied' );
$GLOBALS['environment'] = 'staging';
$close = MAD4B_SCP_Operator_Sessions::close( $proof );
test_check( ! is_wp_error( $close ) && $close['closed'], 'close_session' );
test_check( is_wp_error( MAD4B_SCP_Operator_Sessions::status( $proof ) ), 'closed_session_denied' );
echo 'PASS ' . $GLOBALS['passed'] . ' bounded OAuth-session checks (no host command executed)' . PHP_EOL;
