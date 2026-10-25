<?php
/* Hermetic regression: an exception in optional OAuth rejection telemetry must not escape. */
define( 'ABSPATH', __DIR__ );
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $v ) ); }
function home_url( $path = '' ) { return 'https://staging.example.test' . $path; }
function untrailingslashit( $v ) { return rtrim( $v, '/' ); }
function wp_set_current_user( $id ) {}
class WP_REST_Response {
    private $data; private $status; private $headers = array();
    public function __construct( $data, $status ) { $this->data = $data; $this->status = $status; }
    public function header( $name, $value ) { $this->headers[$name] = $value; }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
    public function get_headers() { return $this->headers; }
}
class MAD4B_SCP_MCP_MU_Bootstrap_Refresh {
    public static function runtime_transaction_gate() { throw new RuntimeException( 'private runtime error' ); }
}
class MAD4B_SCP_MCP_Transport_Admission {
    public static $called = 0;
    public static function observe_rejection( $reason, $status, $code ) {
        self::$called++;
        throw new RuntimeException( 'private telemetry error' );
    }
}
class Fake_MCP_Request {
    public function get_route() { return '/mcp/mad4b-chatgpt'; }
    public function get_method() { return 'POST'; }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-oauth-resource-bridge.php';
$response = MAD4B_SCP_OAuth_Resource_Bridge::authenticate_rest_request( null, null, new Fake_MCP_Request() );
if ( ! $response instanceof WP_REST_Response || 503 !== $response->get_status()
    || 'mad4b_oauth_evaluation_exception' !== ( $response->get_data()['error'] ?? '' )
    || 1 !== MAD4B_SCP_MCP_Transport_Admission::$called
    || 'no-store' !== ( $response->get_headers()['Cache-Control'] ?? '' ) ) {
    throw new RuntimeException( 'Secondary diagnostics must preserve the fail-closed OAuth 503 response' );
}
echo "PASS: OAUTH_SECONDARY_DIAGNOSTIC_EXCEPTION_RUNTIME\n";
