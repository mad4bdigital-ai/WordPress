<?php
/** Regression: v5 deterministic site UUIDs must bind the exact provider breaker generation. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function assert_breaker( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); }
}
class MAD4B_SCP_Schema {}
class MAD4B_SCP_Database_Transaction_Guard {}
class MAD4B_SCP_Entropy {}
class MAD4B_SCP_Time_Policy {}
class MAD4B_SCP_Site_Profile {
    public static function site_uuid() { return $GLOBALS['breaker_test_site_uuid']; }
}
class MAD4B_SCP_Servers {
    public static function provider_for_ability( $surface, $ability ) {
        return 'context/status' === $ability && 'mad4b-read' === $surface
            ? 'google_drive_context' : null;
    }
}
class MAD4B_SCP_Provider_Compatibility_Certification {
    public static function supports_provider( $provider ) { return 'google_drive_context' === $provider; }
    public static function certification_generation_sha256( $provider ) {
        return $GLOBALS['breaker_test_generation'];
    }
}
$GLOBALS['breaker_test_site_uuid'] = 'd745d81f-6fc4-5c6a-99dd-d953c92137bf';
$GLOBALS['breaker_test_generation'] = str_repeat( 'c', 64 );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-circuit-breaker.php';
$c = 'MAD4B_SCP_Provider_Circuit_Breaker';
$v5 = $c::target_context( 'read', 'context/status' );
assert_breaker( is_array( $v5 ) && ! empty( $v5['applicable'] ), 'Site Profile v5 UUID accepted.' );
assert_breaker( $v5['site_uuid'] === $GLOBALS['breaker_test_site_uuid'], 'Exact canonical v5 UUID bound.' );
assert_breaker( $v5['provider_id'] === 'google_drive_context', 'Exact certified provider bound.' );
assert_breaker( $v5['certification_generation_sha256'] === $GLOBALS['breaker_test_generation'], 'Exact generation bound.' );
assert_breaker( strlen( $v5['breaker_key_sha256'] ) === 64, 'Identity is still cryptographically bound.' );
$GLOBALS['breaker_test_site_uuid'] = 'd745d81f-6fc4-4c6a-99dd-d953c92137bf';
$v4 = $c::target_context( 'read', 'context/status' );
assert_breaker( is_array( $v4 ) && ! empty( $v4['applicable'] ), 'Existing v4 UUID remains accepted.' );
assert_breaker( $v4['breaker_key_sha256'] !== $v5['breaker_key_sha256'], 'Distinct sites produce distinct breaker keys.' );
$GLOBALS['breaker_test_site_uuid'] = 'd745d81f-6fc4-3c6a-99dd-d953c92137bf';
$wrong_version = $c::target_context( 'read', 'context/status' );
assert_breaker( is_wp_error( $wrong_version ) && 'mad4b_provider_circuit_breaker_context_unavailable' === $wrong_version->get_error_code(), 'Unreviewed UUID versions fail closed.' );
$GLOBALS['breaker_test_site_uuid'] = 'd745d81f-6fc4-5c6a-79dd-d953c92137bf';
$wrong_variant = $c::target_context( 'read', 'context/status' );
assert_breaker( is_wp_error( $wrong_variant ), 'Non-RFC4122 UUID variant fails closed.' );
$GLOBALS['breaker_test_site_uuid'] = 'd745d81f-6fc4-5c6a-99dd-d953c92137bf';
$GLOBALS['breaker_test_generation'] = '';
$unknown_generation = $c::target_context( 'read', 'context/status' );
assert_breaker( is_wp_error( $unknown_generation ), 'Missing provider generation fails closed.' );
$GLOBALS['breaker_test_generation'] = str_repeat( 'd', 64 );
$new_generation = $c::target_context( 'read', 'context/status' );
assert_breaker( is_array( $new_generation ) && $new_generation['breaker_key_sha256'] !== $v5['breaker_key_sha256'], 'Provider certification drift changes breaker identity.' );
$unrelated = $c::target_context( 'read', 'not-a-registered-ability' );
assert_breaker( is_array( $unrelated ) && empty( $unrelated['applicable'] ), 'Unrelated abilities do not gain provider authority.' );
echo "PASS: v5 canonical Site UUID provider breaker + v4 compatibility + fail-closed generation/version/variant\n";
