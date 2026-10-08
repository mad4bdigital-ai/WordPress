<?php
/** Hermetic enrollment-safe read diagnostic: never initialize user state. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array(); $GLOBALS['abilities'] = array(); $GLOBALS['mutations'] = 0;
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook] = $callback; return true; }
function wp_register_ability( $name, $schema ) { $GLOBALS['abilities'][$name] = $schema; return true; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][$name] ); }
function wp_get_environment_type() { return 'development'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error { private $code; public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
class MAD4B_SCP_Site_Profile {
    public static $configured = false; public static $origin = false;
    public static function configured() { return self::$configured; }
    public static function origin_enrolled() { return self::$origin; }
    public static function site_urls_match_enrollment() { return self::$origin; }
    public static function current_environment() { return 'staging'; }
}
class MAD4B_SCP_Adaptive_Operations_Context {
    public static $calls = 0; public static $result;
    public static function current() { ++self::$calls; return self::$result; }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-bootstrap-diagnostic.php';
function check_case( $name, $test ) { if ( ! $test ) { fwrite( STDERR, 'FAIL ' . $name . PHP_EOL ); exit( 1 ); } echo 'PASS ' . $name . PHP_EOL; }
MAD4B_SCP_Assistant_Bootstrap_Diagnostic::boot();
check_case( 'hook bound', isset( $GLOBALS['hooks']['wp_abilities_api_init'] ) );
call_user_func( $GLOBALS['hooks']['wp_abilities_api_init'] );
$schema = $GLOBALS['abilities']['mad4b/assistant-bootstrap-diagnostic'] ?? array();
check_case( 'read only tool registered', ( $schema['meta']['annotations']['readonly'] ?? null ) === true );
check_case( 'no writable schema fields', ( $schema['input_schema']['additionalProperties'] ?? null ) === false );
$first = MAD4B_SCP_Assistant_Bootstrap_Diagnostic::status();
check_case( 'missing profile detected without initialization', ! $first['profile_configured']
    && in_array( 'site_profile_unconfigured', $first['blockers'], true ) && ! $first['site_enrollment_performed'] );
check_case( 'missing profile does not probe runtime', 0 === MAD4B_SCP_Adaptive_Operations_Context::$calls );
check_case( 'no environment elevated from fallback', 'development' === $first['environment'] );
MAD4B_SCP_Site_Profile::$configured = true;
$second = MAD4B_SCP_Assistant_Bootstrap_Diagnostic::status();
check_case( 'unbound origin never trusted', in_array( 'site_origin_not_bound', $second['blockers'], true ) && ! $second['preview_eligible'] );
check_case( 'unbound origin cannot read runtime', 0 === MAD4B_SCP_Adaptive_Operations_Context::$calls );
MAD4B_SCP_Site_Profile::$origin = true;
MAD4B_SCP_Adaptive_Operations_Context::$result = new WP_Error( 'mad4b_adaptive_restore_epoch_unverified' );
$third = MAD4B_SCP_Assistant_Bootstrap_Diagnostic::status();
check_case( 'restore epoch missing reports blocker', in_array( 'mad4b_adaptive_restore_epoch_unverified', $third['blockers'], true ) );
check_case( 'restore anchor never auto initialized', !$third['restore_initialization_performed'] && !$third['preview_eligible'] );
MAD4B_SCP_Adaptive_Operations_Context::$result = array( 'contract' => 'mad4b.adaptive-operations-context.v1' );
$ready = MAD4B_SCP_Assistant_Bootstrap_Diagnostic::status();
check_case( 'verified runtime binding enables preview only', $ready['preview_eligible'] && !$ready['ready_for_mutation'] && !$ready['authorizing'] );
check_case( 'no grants or installers from bootstrap diagnostic', !$ready['write_grants_created'] && !$ready['automatic_install_allowed'] );
$before = MAD4B_SCP_Adaptive_Operations_Context::$calls;
$invalid = MAD4B_SCP_Assistant_Bootstrap_Diagnostic::status( array( 'approve' => true ) );
check_case( 'unexpected input denied', is_wp_error( $invalid ) && $invalid->get_error_code() === 'mad4b_assistant_bootstrap_input_invalid' );
check_case( 'rejected input avoids runtime', $before === MAD4B_SCP_Adaptive_Operations_Context::$calls );
check_case( 'no mutations', 0 === $GLOBALS['mutations'] );
echo 'ASSISTANT_BOOTSTRAP_DIAGNOSTIC: PASS' . PHP_EOL;
