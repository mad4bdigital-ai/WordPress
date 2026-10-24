<?php
/** Standalone dual-tenant, fail-closed runner identity regression. */
define( 'ABSPATH', __DIR__ . '/../../' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
class MAD4B_SCP_Site_Profile {
    public static function configured() { return getenv( 'MAD4B_TEST_PROFILE_CONFIGURED' ) !== '0'; }
    public static function origin_enrolled() { return getenv( 'MAD4B_TEST_PROFILE_ENROLLED' ) !== '0'; }
    public static function site_urls_match_enrollment() { return getenv( 'MAD4B_TEST_SITE_MATCH' ) !== '0'; }
    public static function current_environment() { return getenv( 'MAD4B_TEST_ENV' ) ?: 'staging'; }
}
$id = getenv( 'MAD4B_TEST_RUNNER_PROFILE_ID' );
if ( is_string( $id ) && $id !== '' ) define( 'MAD4B_OPERATOR_RUNNER_PROFILE_ID', $id );
require_once __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-runner-profile.php';
$result = MAD4B_SCP_Operator_Runner_Profile::current_id();
$expected = getenv( 'MAD4B_TEST_EXPECT_DENIED' ) === '1' ? false : $id;
if ( $expected === false ) {
    if ( ! is_wp_error( $result ) ) { fwrite(STDERR, "FAIL unexpected runner permission\n"); exit( 1 ); }
} else {
    if ( ! is_string( $result ) || $result !== $expected ) { fwrite(STDERR, "FAIL exact runner binding\n"); exit( 2 ); }
}
echo 'PASS runner_profile=', is_wp_error( $result ) ? 'denied' : $result, PHP_EOL;
