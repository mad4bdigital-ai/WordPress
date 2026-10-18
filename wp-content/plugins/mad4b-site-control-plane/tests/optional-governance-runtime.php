<?php
/** Hermetic optional governance diagnostics; no WordPress install required. */
define( 'ABSPATH', __DIR__ );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
class WP_Error { public function __construct( $code, $message ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
$GLOBALS['options'] = array();
$GLOBALS['extension'] = array(
    array( 'id'=>'developer_lane','reason'=>'repository_evidence_failed','label'=>'Spoof','description'=>'Spoof' ),
    array( 'id'=>'optional_health','reason'=>'optional_health_failed','label'=>'Extra diagnostic',
        'description'=>'Read-only advisory', 'scope'=>'operator_advisory_only' ),
);
$GLOBALS['observations'] = array( 'optional_health_failed' => false );
function get_option( $key, $default = array() ) { return $GLOBALS['options'][$key] ?? $default; }
function apply_filters( $name, $initial ) {
    if ( 'mad4b_scp_optional_governance_definitions' === $name ) return $GLOBALS['extension'];
    if ( 'mad4b_scp_optional_governance_observations' === $name ) return $GLOBALS['observations'];
    return $initial;
}
function expect( $test, $message ) { if ( ! $test ) throw new RuntimeException( $message ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-optional-governance.php';
$cls = 'MAD4B_SCP_Optional_Governance';
$initial = $cls::status();
expect( $initial['valid'] && count( $initial['gates'] ) === 6, 'Built-in + dynamic advisory policy catalog missing' );
$hard = array( 'repository_evidence_failed', 'runtime_identity_mismatch', 'developer_lane_not_ready' );
$project = $cls::project( $hard, array() );
expect( $project['degraded'] === $hard, 'Default mode must preserve old behavior' );
$GLOBALS['options'][$cls::OPTION] = array( 'contract' => $cls::CONTRACT, 'revision' => 1,
    'modes' => array( 'developer_lane' => 'off',
        'developer_breakglass_lane' => 'advisory', 'provider_closure'=>'advisory',
        'machine_ingress'=>'off', 'machine_ingress_evidence'=>'off' ) );
$view = $cls::project( $hard, array( 'mutation_uncertain' => true ) );
expect( ! in_array( 'developer_lane_not_ready', $view['degraded'], true )
    && in_array( 'developer_lane_not_ready', $view['muted'], true ), 'Optional signal not suppressed' );
expect( in_array( 'repository_evidence_failed', $view['degraded'], true )
    && in_array( 'runtime_identity_mismatch', $view['degraded'], true ), 'Core signals must remain' );
// Extension was registered before catalog initialization; policy is cached for this request.
$custom = $cls::project( array( 'recovery_required' ), array() );
expect( in_array( 'optional_health_failed', $custom['degraded'], true ), 'Dynamic advisory signal missing' );
expect( in_array( 'recovery_required', $custom['degraded'], true ), 'Recovery signal cannot be overridden' );
$definitions = $cls::catalog();
expect( isset( $definitions['optional_health'] ) && $definitions['developer_lane']['reason'] === 'developer_lane_not_ready',
    'Dynamic extension not registered or core policy overwritten by spoof' );
// Fail closed on invalid persisted values.
$GLOBALS['options'][$cls::OPTION]['modes']['developer_lane'] = 'bypass_auth';
$invalid = $cls::project( $hard, array() );
expect( $invalid['degraded'] === $hard && ! $invalid['active_policy'], 'Invalid options must fail closed' );
echo "OPTIONAL_GOVERNANCE: PASS (default, optional, core protection, invalid storage)\n";
