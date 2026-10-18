<?php
/** Hermetic optional governance diagnostics; no WordPress install required. */
define( 'ABSPATH', __DIR__ );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
class WP_Error { public function __construct( $code, $message ) {} }
$GLOBALS['operating_mode'] = 'SINGLE_OWNER_HARDENED';
class MAD4B_SCP_Policy_Resolution {
    public static function current_operating_mode() { return $GLOBALS['operating_mode']; }
}
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
expect( in_array('developer_lane_not_ready',$project['advisory'],true)
    && ! in_array('developer_lane_not_ready',$project['degraded'],true)
    && in_array('repository_evidence_failed',$project['degraded'],true),
    'Single owner optional warning should be advisory without changing a core blocker' );
$GLOBALS['operating_mode'] = 'ENTERPRISE_MULTI_OPERATOR';
$enterprise = $cls::project($hard,array());
expect(in_array('developer_lane_not_ready',$enterprise['degraded'],true),
    'Enterprise mode must enforce optional warnings');
$GLOBALS['operating_mode'] = 'EMERGENCY_RECOVERY';
$emergency = $cls::project($hard,array());
expect(in_array('developer_lane_not_ready',$emergency['degraded'],true),
    'Recovery mode must be conservative');
$GLOBALS['operating_mode'] = 'SINGLE_OWNER_HARDENED';
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
expect( in_array( 'optional_health_failed', $custom['advisory'], true )
    && in_array( 'optional_health_failed', $custom['raw_degraded'], true ), 'Dynamic advisory signal missing' );
expect( in_array( 'recovery_required', $custom['degraded'], true ), 'Recovery signal cannot be overridden' );
$definitions = $cls::catalog();
expect( isset( $definitions['optional_health'] ) && $definitions['developer_lane']['reason'] === 'developer_lane_not_ready',
    'Dynamic extension not registered or core policy overwritten by spoof' );
$profile = $cls::status();
expect($profile['operating_mode'] === 'SINGLE_OWNER_HARDENED'
    && count($profile['locked_security_gates']) >= 6, 'Required gates must remain visible as locked');
$GLOBALS['options'][$cls::OPTION]['modes']['developer_lane'] = 'inherit';
$inherit = $cls::project($hard,array());
expect(in_array('developer_lane_not_ready',$inherit['advisory'],true),
    'Explicit inherit must use current profile');
$GLOBALS['operating_mode'] = 'UNTRUSTED_MODE';
$bad_profile = $cls::project($hard,array());
expect($bad_profile['degraded'] === $hard && ! $bad_profile['active_policy'],
    'Invalid policy mode must preserve raw warnings');
$GLOBALS['operating_mode'] = 'SINGLE_OWNER_HARDENED';
// Fail closed on invalid persisted values.
$GLOBALS['options'][$cls::OPTION]['modes']['developer_lane'] = 'bypass_auth';
$invalid = $cls::project( $hard, array() );
expect( $invalid['degraded'] === $hard && ! $invalid['active_policy'], 'Invalid options must fail closed' );
echo "OPTIONAL_GOVERNANCE: PASS (default, optional, core protection, invalid storage)\n";
