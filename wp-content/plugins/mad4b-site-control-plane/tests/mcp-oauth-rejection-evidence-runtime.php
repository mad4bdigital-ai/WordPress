<?php
/** Hermetic, no-WordPress denial-evidence regression: no token processing. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['test_env'] = 'staging';
$GLOBALS['options'] = array();
$GLOBALS['writes'] = 0;
function wp_get_environment_type() { return $GLOBALS['test_env']; }
function get_option( $key, $fallback = false ) { return $GLOBALS['options'][$key] ?? $fallback; }
function update_option( $key, $value, $autoload = false ) {
    ++$GLOBALS['writes'];
    $GLOBALS['options'][$key] = $value;
    return true;
}
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
class MAD4B_Native_Incident_MU {
    public static $active = true;
    public static function active() { return self::$active; }
    public static function correlation_id() { return str_repeat( 'f', 32 ); }
}
class WP_REST_Response {
    private $data;
    private $status;
    public $headers = array();
    public function __construct( $data, $status ) { $this->data = $data; $this->status = $status; }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
    public function header( $key, $val ) { $this->headers[$key] = $val; }
}
class MAD4B_SCP_Servers {
    public static function expected_server_ids() { return array( 'mad4b-chatgpt' ); }
}
class Fake_MAD4B_Request {
    public function get_route() { return '/mcp/mad4b-chatgpt'; }
}
function assert_evidence( $ok, $why ) {
    if ( ! $ok ) throw new RuntimeException( $why );
}
require __DIR__ . '/../includes/class-mad4b-scp-mcp-transport-admission.php';
$name = 'MAD4B_SCP_MCP_Transport_Admission';
assert_evidence( true === $name::observe_rejection( 'oauth', 401, 'mad4b_oauth_bearer_required' ), 'First staging 401 denied' );
$visible = $name::rejection_evidence_status();
assert_evidence( $visible['available'] && 1 === $visible['count'], 'Rejection not readable' );
assert_evidence( 'oauth' === $visible['events'][0]['stage'] && 401 === $visible['events'][0]['http_status'], 'Stage/status drift' );
assert_evidence( 'mad4b_oauth_bearer_required' === $visible['events'][0]['code'], 'Error code drift' );
assert_evidence( 1 === $GLOBALS['writes'], 'Unexpected database writes' );
assert_evidence( false === $name::observe_rejection( 'oauth', 403, 'mad4b_oauth_subject_not_approved' ), 'Request cap bypassed' );
$r = new WP_REST_Response( array( 'jsonrpc' => '2.0', 'error' => array( 'code' => -32601, 'message' => 'SENSITIVE PAYLOAD' ) ), 200 );
$name::annotate_response( $r, null, new Fake_MAD4B_Request() );
assert_evidence( 1 === $GLOBALS['writes'], '200 JSON-RPC error bypassed rate limit' );
assert_evidence( false === strpos( json_encode( $GLOBALS['options'] ), 'SENSITIVE' ), 'Raw MCP response stored' );
$ref = new ReflectionProperty( $name, 'observed_rejection' );
$ref->setAccessible( true );
$ref->setValue( false );
assert_evidence( false === $name::observe_rejection( 'oauth', 401, 'mad4b_oauth_bearer_required' ), 'Site-wide throttle bypassed' );
assert_evidence( 1 === $GLOBALS['writes'], 'Site-wide throttle wrote twice' );
$GLOBALS['test_env'] = 'production';
assert_evidence( false === $name::observe_rejection( 'oauth', 503, 'mad4b_oauth_evaluation_exception' ), 'Production capture permitted' );
assert_evidence( ! $name::rejection_evidence_status()['available'], 'Production disclosed staging evidence' );
$GLOBALS['test_env'] = 'staging';
MAD4B_Native_Incident_MU::$active = false;
assert_evidence( false === $name::observe_rejection( 'oauth', 503, 'mad4b_oauth_evaluation_exception' ), 'Inactive managed MU permitted' );
MAD4B_Native_Incident_MU::$active = true;
$ref->setValue( false );
$GLOBALS['options'][$name::REJECTION_OPTION]['last_write_at'] = time() - 17;
assert_evidence( true === $name::observe_rejection( 'oauth', 403, 'Bearer-CREDENTIAL-PRIVATE' ), 'Unknown code did not fall back' );
$rows = $name::rejection_evidence_status()['events'];
assert_evidence( 2 === count( $rows ) && 'mad4b_rejection_unspecified' === $rows[1]['code'], 'Untrusted code not sanitized' );
assert_evidence( false === strpos( json_encode( $GLOBALS['options'] ), 'CREDENTIAL-PRIVATE' ), 'Raw error code leaked' );
$GLOBALS['options'][$name::REJECTION_OPTION]['events'][] = array(
    'stage' => 'oauth', 'status' => 401, 'code' => 'Bearer private', 'correlation_id' => '<script>', 'at' => time(),
);
assert_evidence( 2 === $name::rejection_evidence_status()['count'], 'Tampered stored row was projected' );
echo "MCP_OAUTH_REJECTION_EVIDENCE_RUNTIME: PASS\n";
