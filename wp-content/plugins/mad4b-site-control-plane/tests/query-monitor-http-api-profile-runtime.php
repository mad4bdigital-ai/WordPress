<?php
define( 'ABSPATH', '/tmp/' );
define( 'QM_VERSION', '4.0.7' );

class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }

class MAD4B_HTTP_Component {
	public $type;
	private $name;
	public function __construct( $type, $name ) { $this->type = $type; $this->name = $name; }
	public function get_name() { return $this->name; }
}
class MAD4B_HTTP_Trace {
	private $caller;
	private $component;
	public function __construct( $caller, $component ) { $this->caller = $caller; $this->component = $component; }
	public function get_caller() { return (object) array( 'id' => $this->caller ); }
	public function get_component() { return $this->component; }
}
class MAD4B_HTTP_Response {
	public $code;
	public function __construct( $code ) { $this->code = $code; }
}
class MAD4B_HTTP_Collector {
	public $data;
	public function __construct( $requests ) { $this->data = (object) array( 'http' => $requests ); }
	public function get_data() { return $this->data; }
}
class QM_Collectors {
	public static $collector;
	public static function get( $id ) { return 'http' === $id ? self::$collector : null; }
}

$requests = array();

$r = new stdClass();
$r->host = 'api.example.com';
$r->url = 'https://api.example.com/private/path?token=VERY_SECRET_TOKEN';
$r->args = array( 'method' => 'POST', 'headers' => array( 'Authorization' => 'Bearer VERY_SECRET_TOKEN' ), 'body' => 'PRIVATE_BODY' );
$r->ltime = 0.125;
$r->local = false;
$r->result = new MAD4B_HTTP_Response( 200 );
$r->trace = new MAD4B_HTTP_Trace( 'Plugin_A::sync()', new MAD4B_HTTP_Component( 'plugin', 'Plugin A' ) );
$requests[] = $r;

$r = new stdClass();
$r->host = 'api.example.com';
$r->url = 'https://api.example.com/another?api_key=SECOND_SECRET';
$r->args = array( 'method' => 'GET' );
$r->ltime = 0.075;
$r->local = false;
$r->result = new WP_Error( 'timeout_secret_should_not_leak' );
$r->trace = new MAD4B_HTTP_Trace( 'Plugin_A::status()', new MAD4B_HTTP_Component( 'plugin', 'Plugin A' ) );
$requests[] = $r;

$r = new stdClass();
$r->host = 'staging.example.test';
$r->url = 'https://staging.example.test/wp-json/private?nonce=LOCAL_SECRET';
$r->args = array( 'method' => 'HEAD' );
$r->ltime = 0.010;
$r->local = true;
$r->result = new MAD4B_HTTP_Response( 204 );
$r->trace = new MAD4B_HTTP_Trace( 'Core_Check::run()', new MAD4B_HTTP_Component( 'plugin', 'MAD4B Core' ) );
$requests[] = $r;

QM_Collectors::$collector = new MAD4B_HTTP_Collector( $requests );

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-query-monitor-evidence-bridge.php';

function mad4b_http_assert( $condition, $message, $data = null ) {
	if ( $condition ) return;
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

$p = MAD4B_SCP_Query_Monitor_Evidence_Bridge::http_api_profile_for_test();
mad4b_http_assert( 'mad4b.http-api-performance-profile.v1' === $p['contract'], 'HTTP profile contract drifted', $p );
mad4b_http_assert( ! empty( $p['available'] ), 'HTTP profile should be available with collector data', $p );
mad4b_http_assert( 3 === (int) $p['request_count'], 'HTTP request count drifted', $p );
mad4b_http_assert( 1 === (int) $p['error_count'], 'HTTP error count drifted', $p );
mad4b_http_assert( 1 === (int) $p['local_count'] && 2 === (int) $p['remote_count'], 'local/remote counts drifted', $p );
mad4b_http_assert( 210.0 === (float) $p['total_time_ms'], 'total HTTP time drifted', $p );
mad4b_http_assert( 'api.example.com' === $p['top_hosts'][0]['host'] && 2 === (int) $p['top_hosts'][0]['count'], 'host aggregation drifted', $p );
mad4b_http_assert( 'Plugin_A::sync' === $p['slowest_calls'][0]['caller'], 'caller attribution drifted', $p );
mad4b_http_assert( 'plugin:Plugin A' === $p['slowest_calls'][0]['component'], 'component attribution drifted', $p );
mad4b_http_assert( 64 === strlen( $p['slowest_calls'][0]['call_fingerprint'] ) && ctype_xdigit( $p['slowest_calls'][0]['call_fingerprint'] ), 'HTTP call fingerprint invalid', $p );

foreach ( array( 'raw_urls_returned', 'request_headers_returned', 'request_bodies_returned', 'response_headers_returned', 'response_bodies_returned' ) as $flag ) {
	mad4b_http_assert( empty( $p[$flag] ), 'sensitive HTTP data exposure flag changed: ' . $flag, $p );
}

$encoded = json_encode( $p );
foreach ( array( 'VERY_SECRET_TOKEN', 'SECOND_SECRET', 'LOCAL_SECRET', '/private/path', '/another', '/wp-json/private', 'Authorization', 'PRIVATE_BODY' ) as $forbidden ) {
	mad4b_http_assert( false === strpos( $encoded, $forbidden ), 'HTTP profile leaked forbidden request detail: ' . $forbidden, $p );
}

echo "mad4b.query-monitor-http-api-profile.runtime.v1: PASS\n";
