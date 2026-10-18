<?php
/** Hermetic optional governance diagnostics; no WordPress install required. */
define( 'ABSPATH', __DIR__ );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message ) { $this->code=$code; $this->message=$message; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
function get_current_user_id() { return 1; }
function get_current_blog_id() { return 1; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
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
    array( 'id'=>'optional_duplicated','reason'=>'optional_health_failed','label'=>'Duplicate',
        'description'=>'Duplicate advisory code is not allowed', 'scope'=>'operator_advisory_only' ),
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
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-optional-governance-remote.php';
$cls = 'MAD4B_SCP_Optional_Governance';
$remote = 'MAD4B_SCP_Optional_Governance_Remote';
$initial = $cls::status();
expect( $initial['valid'] && count( $initial['gates'] ) === 5, 'Built-in + dynamic advisory policy catalog missing' );
$remote_plan = $remote::plan( array( 'policy_id'=>'developer_lane', 'mode'=>'off' ) );
expect( ! is_wp_error( $remote_plan ) &&
    preg_match( '/^[a-f0-9]{64}$/', $remote_plan['plan_sha256'] ) &&
    empty( $remote_plan['mutation_performed'] ), 'Remote plan must not mutate and must bind SHA' );
$remote_read = $remote::verify( array( 'policy_id'=>'developer_lane', 'mode'=>'off' ) );
expect( ! is_wp_error( $remote_read ) && false === $remote_read['verified'],
    'Remote verify must report a mismatched stored setting without changing it' );
$remote_unknown = $remote::plan( array( 'policy_id'=>'unknown_policy', 'mode'=>'off' ) );
expect( is_wp_error( $remote_unknown ), 'Unknown remote policy must be refused' );
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
        'developer_breakglass_lane' => 'advisory',
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
expect( isset( $definitions['optional_health'] ) && ! isset( $definitions['optional_duplicated'] ) && $definitions['developer_lane']['reason'] === 'developer_lane_not_ready',
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
// Save-path regression: an append-only audit failure after the first-ever
// persistence must remove the newly added option, not leave it half applied.
function current_user_can( $capability ) { return 'manage_options' === $capability; }
$unauthorized = $remote::apply(array( 'policy_id'=>'developer_lane','mode'=>'off',
    'confirmation'=>$remote::CONFIRM,'expected_plan_sha256'=>$remote_plan['plan_sha256'] ));
expect( is_wp_error($unauthorized) &&
    $unauthorized->get_error_code()==='mad4b_optional_remote_staging_authority_denied',
    'Remote Apply must deny an unauthenticated/no-Site-Profile WordPress request' );
function check_admin_referer( $action ) { return true; }
function wp_unslash( $value ) { return $value; }
function wp_generate_uuid4() { return 'test-lease-token'; }
final class MAD4B_SCP_Managed_Skills_Lease {
    public static function acquire( $name, $ttl ) {
        $token = wp_generate_uuid4();
        if ( ! add_option( $name, array( 'owner'=>$token, 'expires_at_epoch'=>time()+$ttl ), '', false ) )
            return new WP_Error( 'busy', 'Busy' );
        return $token;
    }
    public static function release( $name, $owner ) {
        $lock = get_option($name,array());
        if ( is_array($lock) && ($lock['owner']??null) === $owner ) delete_option($name);
    }
}
function add_option( $name, $value, $unused = '', $autoload = false ) {
    if ( array_key_exists( $name, $GLOBALS['options'] ) ) return false;
    $GLOBALS['options'][$name] = $value;
    return true;
}
function update_option( $name, $value, $autoload = false ) {
    $GLOBALS['options'][$name] = $value;
    return true;
}
function delete_option( $name ) { unset( $GLOBALS['options'][$name] ); return true; }
function wp_die( $message, $title = '', $args = array() ) {
    throw new RuntimeException( (string) $message );
}
final class MAD4B_SCP_Audit {
    public static $count = 0;
    public static function record( $event, array $summary, $status = 'ok' ) {
        self::$count++;
        return self::$count === 2 ? new WP_Error('mock_audit_failed', 'audit failed') : array( 'event'=>$event );
    }
}
unset( $GLOBALS['options'][$cls::OPTION] );
$empty = $cls::status();
expect( $empty['valid'] && $empty['revision'] === 0, 'Test requires no previously saved policy' );
$save_modes = array();
foreach ( $empty['gates'] as $gate ) $save_modes[$gate['id']] = 'inherit';
$_POST = array( 'revision'=>0, 'definitions_sha256'=>$empty['definitions_sha256'], 'modes'=>$save_modes );
$save_blocked = false;
try { $cls::save(); } catch ( RuntimeException $error ) {
    $save_blocked = false !== strpos( $error->getMessage(), 'audit failed' )
        || false !== strpos( $error->getMessage(), 'rollback' );
}
expect( $save_blocked && MAD4B_SCP_Audit::$count === 2,
    'Failure after second audit must fail the save' );
expect( ! array_key_exists( $cls::OPTION, $GLOBALS['options'] )
    && ! array_key_exists( $cls::LOCK, $GLOBALS['options'] ),
    'Failed first-save audit must roll back option and release lease' );
// Stale-revision request must not change the store or append an audit event.
$_POST['revision'] = 7;
$stale_denied = false;
try { $cls::save(); } catch ( RuntimeException $error ) {
    $stale_denied = false !== strpos( $error->getMessage(), 'Options changed' );
}
expect( $stale_denied && MAD4B_SCP_Audit::$count === 2
    && ! array_key_exists( $cls::OPTION, $GLOBALS['options'] ),
    'Stale plan must be rejected without an audit or persistent mutation' );
// Explicitly exercise the same source-owned persistence service remotely,
// with a hermetic exact-authorized Staging owner and a fail-closed Production.
function enable_optional_remote_owner_fixture() {
    class MAD4B_SCP_Site_Profile {
        public static $environment = 'staging';
        public static function current_environment() { return self::$environment; }
        public static function user_is_enrolled( $id ) { return 1 === $id; }
    }
    class MAD4B_SCP_Policy {
        public static function can_mutate() { return true; }
    }
    class MAD4B_SCP_OAuth_Resource_Bridge {
        const AUTHORITY_STEP_UP_SCOPE = 'mad4b:authority:step-up';
        public static function verified_bearer_active() { return true; }
        public static function verified_bearer_has_scope( $scope ) {
            return $scope === self::AUTHORITY_STEP_UP_SCOPE;
        }
        public static function verified_bearer_client_is( $id ) {
            return $id === MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID;
        }
    }
    class MAD4B_SCP_Local_OAuth_Server {
        const CHATGPT_CIMD_CLIENT_ID = 'mad4b-chatgpt';
    }
    class MAD4B_SCP_Authorization {
        public static function authorize_mutation( $ability, $surface, $provider, $input ) {
            return $ability === MAD4B_SCP_Optional_Governance_Remote::APPLY
                && $surface === 'mad4b-write' && $provider === 'core';
        }
    }
}
enable_optional_remote_owner_fixture();
$reviewed = $remote::plan( array( 'policy_id'=>'developer_lane', 'mode'=>'off' ) );
expect( ! is_wp_error( $reviewed ) && $reviewed['revision'] === 0,
    'Remote Staging plan must bind an unchanged revision' );
$applied = $remote::apply( array( 'policy_id'=>'developer_lane', 'mode'=>'off',
    'expected_plan_sha256'=>$reviewed['plan_sha256'], 'confirmation'=>$remote::CONFIRM ) );
expect( ! is_wp_error($applied) && $applied['readback_verified']
    && $applied['audit_recorded'] && $applied['revision'] === 1
    && false === $applied['authority_created'], 'Governed remote apply parity failed' );
$stored_verify = $remote::verify( array( 'policy_id'=>'developer_lane', 'mode'=>'off' ) );
expect( ! is_wp_error( $stored_verify ) && $stored_verify['verified']
    && $stored_verify['revision'] === 1,
    'Remote persisted readback differs from applied state' );
$stale = $remote::apply( array( 'policy_id'=>'developer_lane', 'mode'=>'off',
    'expected_plan_sha256'=>$reviewed['plan_sha256'], 'confirmation'=>$remote::CONFIRM ) );
expect( is_wp_error($stale) && $stale->get_error_code()==='mad4b_optional_remote_plan_stale',
    'Stale remote plan must reject replay' );
MAD4B_SCP_Site_Profile::$environment = 'production';
$prod_plan = $remote::plan( array( 'policy_id'=>'machine_ingress', 'mode'=>'off' ) );
$prod = $remote::apply( array( 'policy_id'=>'machine_ingress', 'mode'=>'off',
    'expected_plan_sha256'=>$prod_plan['plan_sha256'], 'confirmation'=>$remote::CONFIRM ) );
expect( is_wp_error($prod) && $prod->get_error_code()==='mad4b_optional_remote_staging_authority_denied',
    'Production remote policy mutation must be denied' );
echo "OPTIONAL_GOVERNANCE: PASS (policy source, CAS lock, audit rollback, remote parity, stale SHA, Production denial)\n";
