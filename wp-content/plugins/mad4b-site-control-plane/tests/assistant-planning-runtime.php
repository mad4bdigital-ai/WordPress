<?php
/** Disposable assistant planner fixture; no WordPress, outbound services or DB. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['registered'] = array(); $GLOBALS['actions'] = array();
$GLOBALS['serialization_side_effect'] = false;
function add_action( $name, $callback, $priority = 10 ) { $GLOBALS['actions'][ $name ] = $callback; return true; }
function wp_register_ability( $id, $args ) { $GLOBALS['registered'][ $id ] = $args; return true; }
function wp_has_ability( $id ) { return isset( $GLOBALS['registered'][ $id ] ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error { private $code; public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
$binding = array( 'profile_digest' => str_repeat( 'a', 64 ), 'runtime_generation' => str_repeat( 'b', 64 ), 'artifact_sha256' => str_repeat( 'c', 64 ), 'environment' => 'staging' );
class MAD4B_SCP_Adaptive_Operations_Context {
    public static $binding;
    public static function current() { return self::$binding; }
}
MAD4B_SCP_Adaptive_Operations_Context::$binding = $binding;
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-planning.php';
function check_case( $name, $test ) { if ( ! $test ) { fwrite( STDERR, 'FAIL: ' . $name . "\n" ); exit( 1 ); } echo 'PASS ' . $name . "\n"; }
function rejected( $result, $code ) { return is_wp_error( $result ) && 'mad4b_assistant_' . $code === $result->get_error_code(); }
$desired = array( 'capabilities' => array(
    array( 'capability' => 'search.intelligence', 'required' => true ),
    array( 'capability' => 'workflow.automation', 'required' => false ),
), 'facts' => array( array( 'key' => 'audience.market.country', 'value' => 'US', 'provenance' => 'operator' ),
    array( 'key' => 'audience.language', 'value' => 'en', 'provenance' => 'operator' ) ) );
$observed = array( 'capabilities' => array( array( 'capability' => 'search.intelligence', 'state' => 'missing' ),
    array( 'capability' => 'workflow.automation', 'state' => 'missing' ) ) );
MAD4B_SCP_Assistant_Planning::boot();
check_case( 'ability registered on hook', isset( $GLOBALS['actions']['wp_abilities_api_init'] ) );
call_user_func( $GLOBALS['actions']['wp_abilities_api_init'] );
check_case( 'read-only ability metadata', ( $GLOBALS['registered']['mad4b/assistant-plan']['meta']['annotations']['readonly'] ?? false ) === true );
$input = array( 'expected_profile_digest' => $binding['profile_digest'], 'expected_runtime_generation' => $binding['runtime_generation'], 'desired' => $desired, 'observed' => $observed );
$plan = MAD4B_SCP_Assistant_Planning::read_plan( $input );
check_case( 'plan nonauthorizing', is_array( $plan ) && false === $plan['authorizing'] && false === $plan['write_performed'] );
check_case( 'required missing discovers alternatives', 'DISCOVER_ALTERNATIVES' === $plan['tasks'][0]['decision'] );
check_case( 'optional missing does not install', 'OPTIONAL_NO_INSTALL' === $plan['tasks'][1]['decision'] );
check_case( 'tasks cannot expand authority', false === $plan['tasks'][0]['execution_allowed'] && false === $plan['tasks'][0]['authority_expansion_allowed'] );
check_case( 'stable identity-bound plan hash', $plan['plan_sha256'] === MAD4B_SCP_Assistant_Planning::read_plan( $input )['plan_sha256'] );
$changed = $input; $changed['expected_runtime_generation'] = str_repeat( 'd', 64 );
check_case( 'stale generation rejects', rejected( MAD4B_SCP_Assistant_Planning::read_plan( $changed ), 'stale_binding' ) );
$changed = $desired; unset( $changed['facts'] );
check_case( 'missing market cannot be inferred from timezone', 'REVIEW_CONTEXT' === MAD4B_SCP_Assistant_Planning::plan( $binding, $changed )['tasks'][0]['decision'] );
$changed = $desired; $changed['facts'][1]['value'] = 'fr'; $changed['facts'][] = array( 'key' => 'audience.language', 'value' => 'en', 'provenance' => 'provider' );
check_case( 'contradictory facts block proposal', 'CONFLICT_REVIEW_REQUIRED' === MAD4B_SCP_Assistant_Planning::plan( $binding, $changed )['state'] );
$changed = $desired; $changed['facts'][0]['value'] = 'Egypt';
check_case( 'invalid country denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $changed ), 'market_invalid' ) );
$changed = $desired; $changed['facts'][1]['value'] = 'en_US<script>';
check_case( 'invalid language denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $changed ), 'language_invalid' ) );
$changed = $desired; $changed['capabilities'][] = $changed['capabilities'][0];
check_case( 'duplicate capability denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $changed ), 'capabilities_invalid' ) );
$changed = $observed; $changed['capabilities'][] = $changed['capabilities'][0];
check_case( 'ambiguous observations denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, $changed ), 'observations_invalid' ) );
$changed = $observed; $changed['capabilities'][0]['state'] = 'active';
check_case( 'claimed active still requires independent verification', 'VERIFY_BEHAVIOR' === MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, $changed )['tasks'][0]['decision'] );
$changed = $desired; $changed['capabilities'] = array_fill( 0, 65, $desired['capabilities'][0] );
check_case( 'oversized input denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $changed ), 'desired_invalid' ) );
class MaliciousObservation { public function __serialize() { $GLOBALS['serialization_side_effect'] = true; return array(); } }
check_case( 'object rejected before serialization', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, array('capabilities'=>array(new MaliciousObservation())) ), 'observed_invalid' ) && ! $GLOBALS['serialization_side_effect'] );
$prod = $binding; $prod['environment'] = 'production';
check_case( 'production never mutation', false === MAD4B_SCP_Assistant_Planning::plan( $prod, $desired, $observed )['production_authorized'] );
echo "ASSISTANT_GA_GB_FIXTURE: PASS\n";
