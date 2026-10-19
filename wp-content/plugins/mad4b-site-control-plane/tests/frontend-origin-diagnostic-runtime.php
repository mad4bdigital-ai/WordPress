<?php
/** Native fixture for the real staging-only, bounded canonical origin diagnostic. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_salt( $context = 'auth' ) { return 'fixture-trusted-server-secret'; }
function wp_register_ability( $name, $args ) { $GLOBALS['registered'][$name] = $args; return true; }
function wp_has_ability( $name ) { return isset( $GLOBALS['registered'][$name] ); }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][] = $hook; }
function home_url( $path = '/' ) { return $GLOBALS['home_origin']; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 0; }
function wp_safe_remote_get( $url, $args ) {
    ++$GLOBALS['outbound_requests'];
    $GLOBALS['outbound_arguments'] = $args;
    $parts = parse_url( $url );
    $query = array();
    parse_str( (string) ( $parts['query'] ?? '' ), $query );
    $allowed_path = in_array( (string) ( $parts['path'] ?? '' ), array( '/', '/wp-json/' ), true );
    $signature = (string) ( $query['mad4b_synthetic_probe'] ?? '' );
    $expected = hash_hmac( 'sha256', MAD4B_SCP_Frontend_Origin_Diagnostic::MARKER . '|' . (int) floor( time() / 120 ), wp_salt( 'auth' ) );
    $GLOBALS['last_probe_path'] = (string) ( $parts['path'] ?? '' );
    if ( 'https' !== ( $parts['scheme'] ?? '' ) || 'staging.example.test' !== ( $parts['host'] ?? '' )
        || ! $allowed_path || count( $query ) !== 1 || ! hash_equals( $expected, $signature )
        || $args['redirection'] !== 0 ||
        $args['timeout'] > 6 || $args['sslverify'] !== true ||
        $args['reject_unsafe_urls'] !== true ||
        ! empty( $args['cookies'] ) ||
        ! empty( $args['headers']['Authorization'] ) )
        throw new RuntimeException( 'Origin probe allowed an unsafe target or HTTP request' );
    if ( ! is_string( $args['headers']['X-MAD4B-Synthetic-Probe'] ) )
        throw new RuntimeException( 'Synthetic marker missing' );
    return $GLOBALS['http_error'] ? new WP_Error( 'http_request_failed', isset( $GLOBALS['http_error_message'] ) ? (string) $GLOBALS['http_error_message'] : 'cURL error 6: Could not resolve host' )
        : array( 'response' => array( 'code' => $GLOBALS['http_code_by_path'][ $GLOBALS['last_probe_path'] ] ?? $GLOBALS['http_code'] ) );
}
class MAD4B_SCP_Policy {
    public static function can_read() { return $GLOBALS['permitted']; }
}
class MAD4B_SCP_Site_Profile {
    public static function configured() { return true; }
    public static function current_environment() { return $GLOBALS['environment']; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function acceptance_enabled() { return true; }
    public static function status() { return array( 'canonical_origin' => 'https://staging.example.test' ); }
}
$GLOBALS['hooks'] = array();
$GLOBALS['registered'] = array();
$GLOBALS['permitted'] = true;
$GLOBALS['environment'] = 'staging';
$GLOBALS['home_origin'] = 'https://staging.example.test/';
$GLOBALS['outbound_requests'] = 0;
$GLOBALS['http_error'] = false;
$GLOBALS['http_code'] = 200;
require __DIR__ . '/../includes/class-mad4b-scp-frontend-origin-diagnostic.php';
$assert = static function ( $ok, $message ) { if ( ! $ok ) throw new RuntimeException( $message ); };
MAD4B_SCP_Frontend_Origin_Diagnostic::register_abilities();
$assert( isset( $GLOBALS['registered'][MAD4B_SCP_Frontend_Origin_Diagnostic::ABILITY] ),
    'Staging origin tool was not registered with WordPress Abilities' );
$registered = $GLOBALS['registered'][MAD4B_SCP_Frontend_Origin_Diagnostic::ABILITY];
$assert( $registered['meta']['annotations']['readonly'] && !$registered['meta']['show_in_rest'],
    'Capability metadata leaked or misclassified' );
$plan = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array() );
$assert( 'READY_FOR_EXPLICIT_PROBE' === $plan['state'] && $GLOBALS['outbound_requests'] === 0,
    'Default plan unexpectedly started a network request' );
$success = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe' ) );
$assert( 'HTTP_REACHABLE_NOT_BROWSER_VERIFIED' === $success['state'] &&
    $success['http_status'] === 200 && !$success['external_browser_attestation'] &&
    !$success['frontend_acceptance_certificate_issued'] &&
    $GLOBALS['outbound_requests'] === 1, 'A single HTTP probe claimed browser certification' );
$GLOBALS['http_code'] = 302;
$redirect = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe' ) );
$assert( 'HTTP_RESPONSE_NOT_ACCEPTED' === $redirect['state'] &&
    !$redirect['http_success'], 'HTTP redirect was treated as a completed probe' );
$GLOBALS['http_error'] = true;
$failure = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe' ) );
$assert( 'HTTP_PROBE_FAILED' === $failure['state'] && 'http_request_failed' === $failure['error_code'] &&
    'dns_resolution_failure' === $failure['transport_error_class'],
    'Network failure was silently converted into success' );
$GLOBALS['environment'] = 'production';
$blocked = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe' ) );
$assert( is_wp_error( $blocked ) && $GLOBALS['outbound_requests'] === 3,
    'Production probe changed the network despite staging-only guard' );
$GLOBALS['environment'] = 'staging';
$GLOBALS['home_origin'] = 'http://127.0.0.1/';
$assert( is_wp_error( MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe' ) ) ) &&
    $GLOBALS['outbound_requests'] === 3, 'Private or non-HTTPS origin was accepted' );
$GLOBALS['home_origin'] = 'https://staging.example.test/';
$GLOBALS['permitted'] = false;
$assert( is_wp_error( MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe' ) ) ) &&
    $GLOBALS['outbound_requests'] === 3, 'Permission failure permitted network probe' );
$GLOBALS['permitted'] = true;
// Fixed REST root distinguishes homepage pipeline failures from public REST
// routing without granting arbitrary URL probing.
$GLOBALS['http_error'] = false;
$GLOBALS['http_code'] = 200;
$rest = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe', 'target' => 'rest_root' ) );
$assert( ! is_wp_error( $rest ) && 'HTTP_REACHABLE_NOT_BROWSER_VERIFIED' === $rest['state']
    && '/wp-json/' === $GLOBALS['last_probe_path'] && 4 === $GLOBALS['outbound_requests'],
    'REST root was not confined to one safe same-origin signed GET' );
$invalid = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe', 'target' => 'https://example.com/' ) );
$assert( is_wp_error( $invalid ) && 4 === $GLOBALS['outbound_requests'], 'Arbitrary probe target allowed' );
$_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] = '0';
$assert( ! MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(),
    'Untrusted diagnostic marker was accepted' );
$_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] = hash_hmac( 'sha256',
    MAD4B_SCP_Frontend_Origin_Diagnostic::MARKER . '|' . (int) floor( time() / 120 ), wp_salt( 'auth' ) );
$assert( MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(),
    'Genuine synthetic origin marker was rejected' );
$_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] = '0';
$_GET['mad4b_synthetic_probe'] = hash_hmac( 'sha256',
    MAD4B_SCP_Frontend_Origin_Diagnostic::MARKER . '|' . (int) floor( time() / 120 ), wp_salt( 'auth' ) );
$assert( MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(),
    'Signed query marker lost after a reverse proxy strips the header' );
$_GET['mad4b_synthetic_probe'] = 'invalid';
$assert( ! MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(),
    'Unsigned query parameter contaminated frontend sample classification' );
// A single governed read can now compare two fixed-origin routes without
// doubling connector prepare/dispatch round trips. This is NOT browser proof.
$GLOBALS['http_error'] = false;
$GLOBALS['http_code_by_path'] = array( '/' => 500, '/wp-json/' => 200 );
$before = $GLOBALS['outbound_requests'];
$pair_plan = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'target' => 'paired' ) );
$assert( 'READY_FOR_EXPLICIT_PROBE' === $pair_plan['state'] && 2 === $pair_plan['max_requests']
    && $before === $GLOBALS['outbound_requests'], 'Paired plan unexpectedly probed network' );
$paired = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'target' => 'paired', 'mode' => 'probe' ) );
$assert( 'FRONTEND_LOOPBACK_5XX_REST_REACHABLE' === $paired['state']
    && 2 === $paired['network_requests_performed']
    && $GLOBALS['outbound_requests'] === $before + 2
    && 500 === $paired['results']['homepage']['http_status']
    && 200 === $paired['results']['rest_root']['http_status']
    && false === $paired['root_cause_proven']
    && false === $paired['external_browser_attestation']
    && false === $paired['frontend_acceptance_certificate_issued'],
    'Paired 500/200 misclassified frontend vs REST or claimed external browser acceptance' );
$GLOBALS['http_code_by_path'] = array( '/' => 200, '/wp-json/' => 200 );
$assert( 'PAIRED_HTTP_REACHABLE_NOT_BROWSER_VERIFIED' === MAD4B_SCP_Frontend_Origin_Diagnostic::inspect(
    array( 'target' => 'paired', 'mode' => 'probe' ) )['state'], 'Paired 200/200 did not remain uncertified' );
$GLOBALS['http_code_by_path'] = array( '/' => 500, '/wp-json/' => 502 );
$assert( 'BOTH_LOOPBACK_PATHS_5XX' === MAD4B_SCP_Frontend_Origin_Diagnostic::inspect(
    array( 'target' => 'paired', 'mode' => 'probe' ) )['state'], 'Paired 500/502 incorrectly classified as frontend-only' );
unset( $GLOBALS['http_code_by_path'] );
$before = $GLOBALS['outbound_requests'];
$GLOBALS['environment'] = 'production';
$assert( is_wp_error( MAD4B_SCP_Frontend_Origin_Diagnostic::inspect(
    array( 'target' => 'paired', 'mode' => 'probe' ) ) ) && $GLOBALS['outbound_requests'] === $before,
    'Paired probe bypassed Production prohibition' );
$GLOBALS['environment'] = 'staging';
$GLOBALS['permitted'] = false;
$assert( is_wp_error( MAD4B_SCP_Frontend_Origin_Diagnostic::inspect(
    array( 'target' => 'paired', 'mode' => 'probe' ) ) ) && $GLOBALS['outbound_requests'] === $before,
    'Paired probe bypassed governed read permission' );
$GLOBALS['permitted'] = true;
// Verify all allowlisted loopback transport failure categories in the same
// sanitized, read-only contract. No message, URL or token is returned.
$GLOBALS['http_error'] = true;
$cases = array(
    'cURL error 6: Could not resolve host' => 'dns_resolution_failure',
    'cURL error 60: SSL certificate problem' => 'tls_verification_failure',
    'cURL error 28: Operation timed out' => 'network_timeout',
    'cURL error 7: Could not connect to server' => 'tcp_connection_failure',
    'Blocked external request by policy' => 'local_http_policy_block',
    'Undocumented socket failure' => 'unknown_transport_failure',
);
$before = $GLOBALS['outbound_requests'];
foreach ( $cases as $message => $expected_class ) {
    $GLOBALS['http_error_message'] = $message;
    $row = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect(
        array( 'target' => 'homepage', 'mode' => 'probe' ) );
    $assert( 'HTTP_PROBE_FAILED' === $row['state']
        && $expected_class === $row['transport_error_class']
        && ! isset( $row['error_message'] ) && ! isset( $row['body'] )
        && ! empty( $row['network_request_performed'] ),
        'Live transport failure category was not redacted and classified: ' . $expected_class );
}
$assert( $GLOBALS['outbound_requests'] === $before + count( $cases ),
    'Transport failure fixture made extra outbound requests' );
$GLOBALS['http_error_message'] = 'cURL error 28: Operation timed out';
$pair_failed = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect(
    array( 'target' => 'paired', 'mode' => 'probe' ) );
$assert( 'PAIRED_HTTP_INCONCLUSIVE' === $pair_failed['state']
    && 'network_timeout' === $pair_failed['results']['homepage']['transport_error_class']
    && 'network_timeout' === $pair_failed['results']['rest_root']['transport_error_class']
    && false === $pair_failed['root_cause_proven']
    && false === $pair_failed['public_network_verified'],
    'Paired transport failure falsely certified the homepage or REST endpoint' );
unset( $GLOBALS['http_error_message'] );
$GLOBALS['http_error'] = false;
echo "PASS staging origin probe: six transport failures, mixed 500/200, zero-network default, bounded GET, HTTPS-only, no redirects, no credentials, no public browser certification or Production mutation\n";
