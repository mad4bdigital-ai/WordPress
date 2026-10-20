<?php
/** Reproduce the pre-pluggable frontend bootstrap using the exact source file. */
if ( PHP_SAPI !== 'cli' ) exit( 1 );
$mode = $argv[1] ?? 'valid';
define( 'ABSPATH', '/test/' );
if ( 'valid' === $mode ) {
    define( 'AUTH_KEY', str_repeat( 'K', 64 ) );
    define( 'AUTH_SALT', str_repeat( 'S', 64 ) );
} elseif ( 'weak' === $mode ) {
    define( 'AUTH_KEY', 'put your unique phrase here' );
    define( 'AUTH_SALT', str_repeat( 'S', 64 ) );
} elseif ( 'missing' !== $mode ) { exit( 2 ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-frontend-origin-diagnostic.php';
function check_marker( $ok, $reason ) { if ( ! $ok ) { fwrite( STDERR, $reason . "\n" ); exit( 1 ); } }
check_marker( ! function_exists( 'wp_salt' ), 'Fixture accidentally loaded pluggable.php' );
$reflect = new ReflectionMethod( 'MAD4B_SCP_Frontend_Origin_Diagnostic', 'marker' );
$reflect->setAccessible( true );
$period = (int) floor( time() / 120 );
$current = $reflect->invoke( null, $period );
$_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] = '';
$_GET['mad4b_synthetic_probe'] = '';
check_marker( ! MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(), 'Unmarked request wrongly accepted' );
if ( 'valid' === $mode ) {
    check_marker( preg_match( '/^[a-f0-9]{64}$/D', $current ) === 1, 'Missing signed marker' );
    $_GET['mad4b_synthetic_probe'] = $current;
    check_marker( MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(), 'Signed query marker rejected during early load' );
    $_GET['mad4b_synthetic_probe'] = '';
    $_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] = $reflect->invoke( null, $period - 1 );
    check_marker( MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(), 'Previous window header rejected' );
    $_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] = $reflect->invoke( null, $period - 2 );
    check_marker( ! MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(), 'Expired marker accepted' );
    $_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] = '';
    $_GET['mad4b_synthetic_probe'] = str_repeat( '0', 64 );
    check_marker( ! MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(), 'Forged marker accepted' );
    $_GET['mad4b_synthetic_probe'] = strtoupper( $current );
    check_marker( ! MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(), 'Malformed marker accepted' );
} else {
    check_marker( '' === $current, 'Weak/missing WordPress signing keys incorrectly allowed' );
    $_GET['mad4b_synthetic_probe'] = str_repeat( 'a', 64 );
    check_marker( ! MAD4B_SCP_Frontend_Origin_Diagnostic::is_signed_synthetic_request(), 'Missing keys fail-open' );
    // Even with a read-authorized, enrolled Staging site, no unsigned
    // fallback network probe is allowed when the boot secret is unavailable.
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
    function home_url( $path = '/' ) { return 'https://staging.example.test' . $path; }
    function wp_parse_url( $value ) { return parse_url( $value ); }
    function wp_safe_remote_get( $url, $args = array() ) { throw new Exception( 'Unsigned network probe must never start' ); }
    $probe = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe', 'target' => 'paired' ) );
    check_marker( is_array( $probe ) && ( $probe['state'] ?? '' ) === 'SIGNED_SYNTHETIC_PROBE_UNAVAILABLE'
        && empty( $probe['network_request_performed'] ), 'Probe failed to stop before network on missing signing keys' );
}
echo "MAD4B_FRONTEND_EARLY_MARKER: PASS ($mode)\n";
