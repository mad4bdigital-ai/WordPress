<?php
/* A pure task-first user journey acceptance fixture. No real WP or writes. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['abilities'] = array();
function wp_has_ability( $name ) { return !empty( $GLOBALS['abilities'][ $name ] ); }
function wp_register_ability( $name, $spec ) { $GLOBALS['abilities'][ $name ] = $spec; }
function add_action( $hook, $callback, $priority = 10 ) {}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-operator-journeys.php';
function assert_journey( $ok, $why ) {
    if ( !$ok ) { fwrite( STDERR, 'FAIL: ' . $why . PHP_EOL ); exit( 1 ); }
}
$recipes = MAD4B_SCP_Operator_Journeys::recipes();
assert_journey( count( $recipes ) === 9, 'nine distinct task journeys' );
$names = array();
foreach ( $recipes as $id => $recipe ) {
    assert_journey( count( $recipe['steps'] ) >= 3
        && isset( $recipe['label'], $recipe['caution'], $recipe['risk'] ),
        'each task includes user goal, multiple steps, caveat and risk' );
    foreach ( $recipe['steps'] as $step ) {
        assert_journey( count( $step ) === 3
            && preg_match( '#^[a-z0-9._-]+/[a-z0-9._-]+$#D', $step[1] )
            && in_array( $step[2], array( 'read', 'write' ), true ),
            'typed, canonical, no arbitrary URL or shell tasks' );
        $names[ $step[1] ] = true;
    }
}
$missing = MAD4B_SCP_Operator_Journeys::reduce( array() );
assert_journey( $missing['total'] === 9
    && !$missing['execution_authorized'] && !$missing['workflow_completed'],
    'missing tools are not fabricated as ready' );
foreach ( $missing['journeys'] as $journey ) {
    assert_journey( !$journey['all_steps_mounted'] &&
        $journey['readiness_state'] === 'INTEGRATION_MISSING'
        && !$journey['one_click_execution_supported']
        && !$journey['live_acceptance_verified'],
        'unmounted journey is not shown as accepted' );
}
$mounted = array_fill_keys( array_keys( $names ), true );
$registered = MAD4B_SCP_Operator_Journeys::reduce( $mounted );
foreach ( $registered['journeys'] as $journey ) {
    assert_journey( $journey['all_steps_mounted']
        && $journey['readiness_state'] === 'REGISTERED_NOT_OPERATIONALLY_VERIFIED',
        'even all mounted tools are not an authorization or completed workflow' );
    foreach ( $journey['steps'] as $step )
        assert_journey( !$step['approved'] && !$step['executed'] && !$step['verified'],
            'no implied approval, write or verification per step' );
}
assert_journey( $registered['journeys']['forms_and_integration']['risk'] === 'read_only',
    'forms discovered do not promise writing arbitrary secrets' );
assert_journey( $registered['journeys']['wordpress_native_recovery']['steps'][0]['ability_name']
    === 'mad4b/native-recovery-status', 'recovery task always resumes prior work first' );
$single = MAD4B_SCP_Operator_Journeys::reduce( $mounted, 'plugin_upgrades' );
assert_journey( $single['total'] === 1 && isset( $single['journeys']['plugin_upgrades'] ),
    'operator may focus on selected goal' );
$invalid = MAD4B_SCP_Operator_Journeys::reduce( $mounted, 'delete_everything' );
assert_journey( is_wp_error( $invalid ), 'no invented unsupported user task' );
$empty_missing = MAD4B_SCP_Operator_Journeys::reduce( $mounted, '', true );
assert_journey( $empty_missing['total'] === 0, 'optional list of only missing integrations' );
assert_journey( is_wp_error( MAD4B_SCP_Operator_Journeys::discover(
    array( 'journey' => array( 'malformed' ) ) ) ),
    'malformed journey identifier rejected before type coercion' );
assert_journey( is_wp_error( MAD4B_SCP_Operator_Journeys::discover(
    array( 'only_incomplete' => 'yes' ) ) ),
    'string boolean cannot silently alter journey filtering' );
MAD4B_SCP_Operator_Journeys::register_ability();
assert_journey( isset( $GLOBALS['abilities'][MAD4B_SCP_Operator_Journeys::ABILITY] ),
    'task index mounted in read-only transport' );
$ability = $GLOBALS['abilities'][MAD4B_SCP_Operator_Journeys::ABILITY];
assert_journey( $ability['meta']['annotations']['readonly'] === true
    && $ability['meta']['mcp']['public'] === false,
    'never public or authorized mutation' );
echo "mad4b.site-control-plane.operator-journeys.runtime.v1: PASS\n";
