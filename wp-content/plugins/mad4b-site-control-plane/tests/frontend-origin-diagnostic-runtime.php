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
    if ( $url !== 'https://staging.example.test/' || $args['redirection'] !== 0 ||
        $args['timeout'] > 6 || $args['sslverify'] !== true ||
        $args['reject_unsafe_urls'] !== true ||
        ! empty( $args['cookies'] ) ||
        ! empty( $args['headers']['Authorization'] ) )
        throw new RuntimeException( 'Origin probe allowed an unsafe target or HTTP request' );
    if ( ! is_string( $args['headers']['X-MAD4B-Synthetic-Probe'] ) )
        throw new RuntimeException( 'Synthetic marker missing' );
    return $GLOBALS['http_error'] ? new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' )
        : array( 'response' => array( 'code' => $GLOBALS['http_code'] ) );
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
$_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] = '0';
$assert( ! MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(),
    'Untrusted diagnostic marker was accepted' );
$_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] = hash_hmac( 'sha256',
    MAD4B_SCP_Frontend_Origin_Diagnostic::MARKER . '|' . (int) floor( time() / 120 ), wp_salt( 'auth' ) );
$assert( MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(),
    'Genuine synthetic origin marker was rejected' );
echo "PASS staging origin probe: default zero-network, bounded GET, HTTPS-only, no redirects, no credentials, non-browser, denied production and spoofed markers\n";
