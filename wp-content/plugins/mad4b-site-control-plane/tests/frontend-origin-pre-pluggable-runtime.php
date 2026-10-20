<?php
/**
 * Regression: signed synthetic frontend classification can execute before
 * WordPress loads pluggable.php. No unauthenticated fallback is permitted.
 */
define( 'ABSPATH', __DIR__ );
function add_action( $hook, $callback, $priority = 10 ) {}
function home_url( $path = '/' ) { return 'https://staging.example.test/'; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_safe_remote_get( $url, $args ) {
    $GLOBALS['outbound_requests']++;
    throw new RuntimeException( 'An unsigned early request was sent' );
}
class MAD4B_SCP_Policy {
    public static function can_read() { return true; }
}
class MAD4B_SCP_Site_Profile {
    public static function configured() { return true; }
    public static function current_environment() { return 'staging'; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function acceptance_enabled() { return true; }
    public static function status() { return array( 'canonical_origin' => 'https://staging.example.test' ); }
}
$GLOBALS['outbound_requests'] = 0;
require __DIR__ . '/../includes/class-mad4b-scp-frontend-origin-diagnostic.php';
if ( function_exists( 'wp_salt' ) ) {
    throw new RuntimeException( 'Fixture must run before wp_salt is defined' );
}
$_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] = str_repeat( 'a', 64 );
$_GET['mad4b_synthetic_probe'] = str_repeat( 'b', 64 );
if ( MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request() ) {
    throw new RuntimeException( 'Early request accepted without an initialized WordPress salt' );
}
$plan = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'plan' ) );
if ( ! is_array( $plan ) || 'READY_FOR_EXPLICIT_PROBE' !== $plan['state'] ) {
    throw new RuntimeException( 'Read-only planning regressed without a pluggable salt' );
}
$probe = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe' ) );
if ( ! is_array( $probe )
    || 'HTTP_PROBE_FAILED' !== $probe['state']
    || 'mad4b_frontend_probe_signature_unavailable' !== $probe['error_code']
    || ! empty( $probe['network_request_performed'] )
    || 0 !== $GLOBALS['outbound_requests'] ) {
    throw new RuntimeException( 'Early unsigned probe did not fail closed before network I/O' );
}
$paired = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe', 'target' => 'paired' ) );
if ( 0 !== $paired['network_requests_performed']
    || ! empty( $paired['results']['homepage']['network_request_performed'] )
    || ! empty( $paired['results']['rest_root']['network_request_performed'] )
    || 0 !== $GLOBALS['outbound_requests'] ) {
    throw new RuntimeException( 'Paired unsigned probe falsified network counts or performed I/O' );
}
echo "frontend-origin pre-pluggable fail-closed PASS\n";
