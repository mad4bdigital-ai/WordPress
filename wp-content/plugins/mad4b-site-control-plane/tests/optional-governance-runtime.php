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
function get_current_user_id() { return $GLOBALS['mock_actor_id'] ?? 1; }
function get_current_blog_id() { return $GLOBALS['mock_blog_id'] ?? 1; }
function home_url( $path = '/' ) { return ( $GLOBALS['mock_site_origin'] ?? 'https://example.test' ) . $path; }
$GLOBALS['operating_mode'] = 'SINGLE_OWNER_HARDENED';
class MAD4B_SCP_Policy_Resolution {
    public static function current_operating_mode() { return $GLOBALS['operating_mode']; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
$GLOBALS['options'] = array();
$GLOBALS['extension'] = array(
    array( 'id'=>'developer_lane','reason'=>'repository_evidence_failed','label'=>'Spoof','description'=>'Spoof' ),
    array( 'id'=>'optional_extension_health','reason'=>'optional_extension_health_failed','label'=>'Extra diagnostic',
        'description'=>'Read-only advisory', 'scope'=>'operator_advisory_only' ),
    array( 'id'=>'optional_extension_duplicated','reason'=>'optional_extension_health_failed','label'=>'Duplicate',
        'description'=>'Duplicate advisory code is not allowed', 'scope'=>'operator_advisory_only' ),
    array( 'id'=>'optional_core_spoof','reason'=>'optional_capability_state_unbound',
        'label'=>'Safety spoof','description'=>'Cannot redefine a core reason',
        'scope'=>'operator_advisory_only' ),
);
$GLOBALS['observations'] = array( 'optional_extension_health_failed' => false );
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
expect( $initial['default_mode'] === 'off' && $initial['configured_profile'] === 'off'
    && $initial['effective_profile'] === 'off', 'Fresh plugin must default optional advisories to off' );
foreach ( $initial['gates'] as $row ) expect( $row['mode'] === 'off',
    'Fresh default must disable only optional operator warnings' );
$raw_default = $cls::project( array( 'developer_lane_not_ready', 'repository_evidence_failed' ), array() );
expect( ! in_array( 'developer_lane_not_ready', $raw_default['degraded'], true )
    && in_array( 'repository_evidence_failed', $raw_default['degraded'], true ),
    'Optional Off may not silence mandatory blockers' );
// Existing saved installations without explicit gate modes retain inherited behavior.
$GLOBALS['options'][$cls::OPTION] = array( 'contract'=>$cls::CONTRACT,
    'revision'=>0, 'modes'=>array() );
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
expect( in_array( 'optional_extension_health_failed', $custom['advisory'], true )
    && in_array( 'optional_extension_health_failed', $custom['raw_degraded'], true ), 'Dynamic advisory signal missing' );
expect( in_array( 'recovery_required', $custom['degraded'], true ), 'Recovery signal cannot be overridden' );
$definitions = $cls::catalog();
expect( isset( $definitions['optional_extension_health'] ) && ! isset( $definitions['optional_extension_duplicated'] )
    && ! isset( $definitions['optional_core_spoof'] ) && $definitions['developer_lane']['reason'] === 'developer_lane_not_ready',
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
function current_user_can( $capability ) {
    return 'manage_options' === $capability && ( $GLOBALS['mock_admin_allowed'] ?? true );
}
$unauthorized = $remote::apply(array( 'policy_id'=>'developer_lane','mode'=>'off',
    'confirmation'=>$remote::CONFIRM,'expected_plan_sha256'=>$remote_plan['plan_sha256'] ));
expect( is_wp_error($unauthorized) &&
    $unauthorized->get_error_code()==='mad4b_optional_remote_claimed_execution_required',
    'Remote Apply must deny an unauthenticated/no-Site-Profile WordPress request' );
function check_admin_referer( $action ) { return true; }
function wp_unslash( $value ) { return $value; }
function wp_generate_uuid4() { return 'test-lease-token'; }
final class MAD4B_SCP_Managed_Skills_Lease {
    public static $reject_refresh = false;
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
    public static function refresh( $name, $ttl, $owner ) {
        $lock = get_option($name,array());
        return ! self::$reject_refresh && is_array($lock) && ($lock['owner']??null) === $owner
            ? true : new WP_Error('fenced','lease lost');
    }
    public static function compensate_optional_governance_option( $owner, $expected, $replacement = null ) {
        $lock=get_option('mad4b_scp_optional_governance_settings_lock_v1',array());
        $name='mad4b_scp_optional_governance_options_v1';
        if (!is_array($lock) || ($lock['owner']??null)!==$owner
            || ($lock['expires_at_epoch']??0)<time()
            || !array_key_exists($name,$GLOBALS['options']) ||
            $GLOBALS['options'][$name] !== $expected) return false;
        if (null===$replacement) unset($GLOBALS['options'][$name]);
        else $GLOBALS['options'][$name] = $replacement;
        return true;
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
$save_modes['developer_lane'] = 'off'; // ensure first-save failure exercises a real write
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
        public static $write_allowed = true;
        public static function can_mutate() { return self::$write_allowed; }
    }
    class MAD4B_SCP_OAuth_Resource_Bridge {
        const AUTHORITY_STEP_UP_SCOPE = 'mad4b:authority:step-up';
        public static $bearer_valid = true;
        public static $scope_valid = true;
        public static $client_valid = true;
        public static function verified_bearer_active() { return self::$bearer_valid; }
        public static function verified_bearer_has_scope( $scope ) {
            return self::$scope_valid && $scope === self::AUTHORITY_STEP_UP_SCOPE;
        }
        public static function verified_bearer_client_is( $id ) {
            return self::$client_valid && $id === MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID;
        }
    }
    class MAD4B_SCP_Local_OAuth_Server {
        const CHATGPT_CIMD_CLIENT_ID = 'mad4b-chatgpt';
    }
    class MAD4B_SCP_Authorization {
        public static $in_claimed_callback = false;
        public static $preflight_calls = 0;
        public static function authorize_mutation( $ability, $surface, $provider, $input ) {
            self::$preflight_calls++;
            return $ability === MAD4B_SCP_Optional_Governance_Remote::APPLY
                && $surface === 'mad4b-write' && $provider === 'core'
                ? array( 'allowed'=>true, 'reason_code'=>'preflight_allowed' )
                : new WP_Error('unauthorized','Unmatched authorization');
        }
        public static function execution_callback_started( $ability ) {
            return self::$in_claimed_callback && $ability === MAD4B_SCP_Optional_Governance_Remote::APPLY;
        }
    }
    class MAD4B_SCP_Execution_Fence {
        public static function has_active_frame() {
            return MAD4B_SCP_Authorization::$in_claimed_callback;
        }
    }
}
enable_optional_remote_owner_fixture();
// Explicitly model WordPress Abilities: preflight happens before the
// central claim; only the governed callback may invoke the provider.
function governed_optional_apply_fixture( $input ) {
    $checked = MAD4B_SCP_Optional_Governance_Remote::can_apply( $input );
    if ( is_wp_error( $checked ) ) return $checked;
    expect( is_array($checked) && true === ($checked['allowed']??false),
        'Governed Ability permission-time preflight did not permit reviewed Staging actor' );
    MAD4B_SCP_Authorization::$in_claimed_callback = true;
    try { return MAD4B_SCP_Optional_Governance_Remote::apply( $input ); }
    finally { MAD4B_SCP_Authorization::$in_claimed_callback = false; }
}
$reviewed = $remote::plan( array( 'policy_id'=>'developer_lane', 'mode'=>'enforced' ) );
expect( ! is_wp_error( $reviewed ) && $reviewed['revision'] === 0,
    'Remote Staging plan must bind an unchanged revision' );
$direct = $remote::apply( array( 'policy_id'=>'developer_lane', 'mode'=>'enforced',
    'expected_plan_sha256'=>$reviewed['plan_sha256'], 'confirmation'=>$remote::CONFIRM ) );
expect( is_wp_error($direct) &&
    $direct->get_error_code()==='mad4b_optional_remote_claimed_execution_required',
    'Calling Apply directly without a central ticket claim must be refused' );
$auth_calls_before = MAD4B_SCP_Authorization::$preflight_calls;
$applied = governed_optional_apply_fixture( array( 'policy_id'=>'developer_lane', 'mode'=>'enforced',
    'expected_plan_sha256'=>$reviewed['plan_sha256'], 'confirmation'=>$remote::CONFIRM ) );
expect( ! is_wp_error($applied) && $applied['readback_verified']
    && $applied['audit_recorded'] && $applied['revision'] === 1
    && false === $applied['authority_created']
    && MAD4B_SCP_Authorization::$preflight_calls === $auth_calls_before + 1,
    'Governed remote apply must use a single central preflight; executor must not replay approval' );
$stored_verify = $remote::verify( array( 'policy_id'=>'developer_lane', 'mode'=>'enforced' ) );
expect( ! is_wp_error( $stored_verify ) && $stored_verify['verified']
    && $stored_verify['revision'] === 1,
    'Remote persisted readback differs from applied state' );
$unchanged_plan = $remote::plan( array( 'policy_id'=>'developer_lane', 'mode'=>'enforced' ) );
expect( ! is_wp_error($unchanged_plan) && false===$unchanged_plan['change_required'],
    'Remote preview must explicitly identify no-op changes' );
$audit_before_noop = MAD4B_SCP_Audit::$count;
$unchanged_apply = governed_optional_apply_fixture(array( 'policy_id'=>'developer_lane', 'mode'=>'enforced',
    'expected_plan_sha256'=>$unchanged_plan['plan_sha256'], 'confirmation'=>$remote::CONFIRM ));
expect( is_wp_error($unchanged_apply) &&
    $unchanged_apply->get_error_code()==='mad4b_optional_remote_no_change_use_verify'
    && MAD4B_SCP_Audit::$count===$audit_before_noop,
    'No-op Write must refuse the provider mutation and direct users to read-only Verify' );
$stale = governed_optional_apply_fixture( array( 'policy_id'=>'developer_lane', 'mode'=>'enforced',
    'expected_plan_sha256'=>$reviewed['plan_sha256'], 'confirmation'=>$remote::CONFIRM ) );
expect( is_wp_error($stale) && $stale->get_error_code()==='mad4b_optional_remote_plan_stale',
    'Stale remote plan must reject replay' );
MAD4B_SCP_Site_Profile::$environment = 'production';
$prod_plan = $remote::plan( array( 'policy_id'=>'machine_ingress', 'mode'=>'off' ) );
$prod = governed_optional_apply_fixture( array( 'policy_id'=>'machine_ingress', 'mode'=>'off',
    'expected_plan_sha256'=>$prod_plan['plan_sha256'], 'confirmation'=>$remote::CONFIRM ) );
expect( is_wp_error($prod) && $prod->get_error_code()==='mad4b_optional_remote_staging_authority_denied',
    'Production remote policy mutation must be denied' );
// Shared admin AJAX entry verifies the identical CAS/audit state and returns
// a signed-by-source view contract, with no independent persistence shortcut.
function check_ajax_referer( $action ) { return true; }
function optional_ajax_fixture() {
    class MAD4B_SCP_Admin_Settings_Persistence {
        public static function view_readback( $form, $fields ) {
            return array( 'contract'=>'mad4b.admin-settings-view-readback.v1',
                'form_id'=>$form, 'fields'=>$fields );
        }
    }
}
optional_ajax_fixture();
function wp_send_json_error( $data, $status = 400 ) {
    throw new RuntimeException( 'AJAX_ERROR:' . $data['code'] );
}
function wp_send_json_success( $data ) {
    if ( empty($data['persistence_verified']) ||
        ($data['view_readback']['fields']['revision']??null)!==($GLOBALS['expected_ajax_revision']??1) ) throw new RuntimeException('AJAX_BAD_RECEIPT');
    throw new RuntimeException( 'AJAX_OK' );
}
// Once the requested audit was recorded, a lost lease must deny the
// change before writing any option, with no speculative rollback.
MAD4B_SCP_Site_Profile::$environment = 'staging';
$live_before_loss = $cls::status();
$lost_modes = array();
foreach ( $live_before_loss['gates'] as $row ) $lost_modes[$row['id']] = $row['configured_mode'];
$lost_modes['machine_ingress'] = 'enforced';
$before_loss = get_option( $cls::OPTION, null );
MAD4B_SCP_Managed_Skills_Lease::$reject_refresh = true;
$lost_result = $cls::persist_modes(
    $lost_modes, $live_before_loss['revision'], $live_before_loss['definitions_sha256'] );
MAD4B_SCP_Managed_Skills_Lease::$reject_refresh = false;
expect( is_wp_error( $lost_result ) &&
    get_option( $cls::OPTION, null ) === $before_loss &&
    ! array_key_exists( $cls::LOCK, $GLOBALS['options'] ),
    'Lost lock must fail closed before writing and release owned lease' );
$live_status = $cls::status();
$form_modes = array();
foreach( $live_status['gates'] as $row ) $form_modes[$row['id']] = $row['configured_mode'];
$_POST = array( 'revision'=>(string)$live_status['revision'],
    'definitions_sha256'=>$live_status['definitions_sha256'], 'modes'=>$form_modes );
$ajax_success = false;
try { $cls::save_ajax(); } catch ( RuntimeException $error ) {
    $ajax_success = 'AJAX_OK' === $error->getMessage();
}
expect( $ajax_success && $cls::status()['revision'] === 1,
    'No-op Admin AJAX must verify the current view without redundant revision increments' );
// Remote bulk profile uses one exact source-owned plan and one governed claim.
$bulk = $remote::plan( array( 'policy_id'=>$remote::ALL, 'mode'=>'off' ) );
expect( ! is_wp_error($bulk) && $bulk['change_required']
    && $bulk['affected_optional_notices'] === count( $cls::status()['gates'] ),
    'Bulk remote plan must include every optional gate' );
$bulk_applied = governed_optional_apply_fixture( array( 'policy_id'=>$remote::ALL, 'mode'=>'off',
    'expected_plan_sha256'=>$bulk['plan_sha256'], 'confirmation'=>$remote::CONFIRM ) );
expect( ! is_wp_error( $bulk_applied ) && $bulk_applied['readback_verified']
    && $bulk_applied['revision'] === 2,
    'Bulk remote off must persist with one exact ticket-claimed operation' );
$bulk_verify = $remote::verify( array( 'policy_id'=>$remote::ALL, 'mode'=>'off' ) );
expect( $bulk_verify['verified'] && $bulk_verify['effective_mode'] === 'off',
    'Remote verify must confirm every optional warning is off' );
$enable = $remote::plan( array( 'policy_id'=>$remote::ALL, 'mode'=>'enforced' ) );
$enabled = governed_optional_apply_fixture( array( 'policy_id'=>$remote::ALL, 'mode'=>'enforced',
    'expected_plan_sha256'=>$enable['plan_sha256'], 'confirmation'=>$remote::CONFIRM ) );
expect( ! is_wp_error( $enabled ) && $enabled['revision'] === 3
    && $remote::verify( array( 'policy_id'=>$remote::ALL, 'mode'=>'enforced' ) )['verified'],
    'Bulk governed profile must reactivate all optional warnings remotely' );
// Admin bulk selector must override individual modes only on the explicit save.
$current = $cls::status();
$form = array();
foreach( $current['gates'] as $row ) $form[$row['id']] = $row['configured_mode'];
$_POST = array( 'revision'=>(string)$current['revision'],
    'definitions_sha256'=>$current['definitions_sha256'],
    'modes'=>$form, 'bulk_mode'=>'off' );
$GLOBALS['expected_ajax_revision'] = 4;
$admin_bulk_ok = false;
try { $cls::save_ajax(); } catch ( RuntimeException $error ) {
    $admin_bulk_ok = 'AJAX_OK' === $error->getMessage();
}
expect( $admin_bulk_ok && $cls::status()['effective_profile'] === 'off'
    && $cls::status()['revision'] === 4,
    'Admin options page must bulk-save Off with revision, nonce and readback' );
// Selected batch: target subset only, deterministic order, exact approval plan.
$selection = array( 'machine_ingress', 'developer_lane' );
$select_plan = $remote::plan( array( 'policy_id'=>$remote::SELECTED,
    'mode'=>'advisory', 'policy_ids'=>$selection ) );
$permuted = $remote::plan( array( 'policy_id'=>$remote::SELECTED,
    'mode'=>'advisory', 'policy_ids'=>array_reverse($selection) ) );
expect( ! is_wp_error($select_plan) && $select_plan['change_required']
    && $select_plan['changed_optional_notices'] === 2
    && $select_plan['affected_optional_notices'] === 2
    && $select_plan['plan_sha256'] === $permuted['plan_sha256'],
    'Selected batch must hash canonical target order and count changed policies' );
$before_select = $cls::status();
$selected = governed_optional_apply_fixture( array( 'policy_id'=>$remote::SELECTED,
    'mode'=>'advisory', 'policy_ids'=>array_reverse($selection),
    'expected_plan_sha256'=>$select_plan['plan_sha256'], 'confirmation'=>$remote::CONFIRM ) );
expect( ! is_wp_error($selected) && $selected['revision'] === 5
    && $remote::verify(array( 'policy_id'=>$remote::SELECTED,
        'policy_ids'=>$selection, 'mode'=>'advisory' ))['verified'],
    'Selected plan Apply/Verify must persist exactly the requested subset' );
$after_select = $cls::status();
foreach( $after_select['gates'] as $row ) {
    if ( in_array($row['id'],$selection,true) ) expect( $row['mode']==='advisory',
        'Targeted selected notice did not change' );
    else expect( $row['configured_mode']==='off',
        'Unselected optional notice must keep its prior configuration' );
}
$select_diff = $remote::verify(array( 'policy_id'=>$remote::SELECTED,
    'policy_ids'=>array('developer_lane','machine_ingress','machine_ingress_evidence'),
    'mode'=>'advisory' ));
expect( !$select_diff['verified']
    && $select_diff['mismatched_policy_ids']===array('machine_ingress_evidence'),
    'Scoped readback must identify mismatched optional policy IDs' );

// Fail-closed selection validation: unknown, duplicate, reserved, empty, non-list,
// over-limit, malformed, extraneous selector, incorrect mode and no-op.
foreach ( array(
    array( 'policy_id'=>$remote::SELECTED, 'mode'=>'off', 'policy_ids'=>array() ),
    array( 'policy_id'=>$remote::SELECTED, 'mode'=>'off', 'policy_ids'=>array('developer_lane','developer_lane') ),
    array( 'policy_id'=>$remote::SELECTED, 'mode'=>'off', 'policy_ids'=>array('developer_lane','unknown_policy') ),
    array( 'policy_id'=>$remote::SELECTED, 'mode'=>'off', 'policy_ids'=>array($remote::ALL) ),
    array( 'policy_id'=>$remote::SELECTED, 'mode'=>'off', 'policy_ids'=>array('wrong pattern') ),
    array( 'policy_id'=>$remote::SELECTED, 'mode'=>'off', 'policy_ids'=>array('developer_lane'=>1) ),
    array( 'policy_id'=>$remote::SELECTED, 'mode'=>'off', 'policy_ids'=>array_fill(0,65,'developer_lane') ),
    array( 'policy_id'=>'developer_lane','mode'=>'off','policy_ids'=>array('developer_lane') ),
    array( 'policy_id'=>$remote::ALL,'mode'=>'off','policy_ids'=>array('developer_lane') ),
    array( 'policy_id'=>$remote::SELECTED,'mode'=>'bypass_auth','policy_ids'=>array('developer_lane') ),
    array( 'policy_id'=>$remote::SELECTED, 'mode'=>'off', 'policy_ids'=>array('developer_lane'), 'unexpected'=>'x' ),
) as $invalid_case ) {
    $rejected = $remote::plan( $invalid_case );
    expect( is_wp_error($rejected), 'Malformed or unsafe selected policy input must be refused' );
}
$no_op_batch = $remote::plan( array('policy_id'=>$remote::SELECTED,
    'mode'=>'advisory','policy_ids'=>$selection ) );
expect( !$no_op_batch['change_required'], 'Selected no-op must be detectable at plan time' );
$no_op_before = MAD4B_SCP_Authorization::$preflight_calls;
$no_op_result = governed_optional_apply_fixture( array('policy_id'=>$remote::SELECTED,
    'mode'=>'advisory','policy_ids'=>$selection,
    'expected_plan_sha256'=>$no_op_batch['plan_sha256'], 'confirmation'=>$remote::CONFIRM ) );
expect( is_wp_error($no_op_result)
    && $no_op_result->get_error_code()==='mad4b_optional_remote_no_change_use_verify'
    && MAD4B_SCP_Authorization::$preflight_calls===$no_op_before,
    'No-op selected batch must not use an approved mutation ticket' );

// The site/blog/actor and revision belong to the exact plan SHA.
$cross_site = $remote::plan(array('policy_id'=>$remote::SELECTED,
    'mode'=>'off','policy_ids'=>$selection));
$GLOBALS['mock_site_origin']='https://other-site.example.test';
$foreign = governed_optional_apply_fixture(array('policy_id'=>$remote::SELECTED,
    'mode'=>'off','policy_ids'=>$selection,
    'expected_plan_sha256'=>$cross_site['plan_sha256'], 'confirmation'=>$remote::CONFIRM));
expect( is_wp_error($foreign) && $foreign->get_error_code()==='mad4b_optional_remote_plan_stale',
    'Reviewed plan must not be replayed against another WordPress site' );
unset($GLOBALS['mock_site_origin']);
$GLOBALS['mock_blog_id']=2;
$foreign_blog = governed_optional_apply_fixture(array('policy_id'=>$remote::SELECTED,
    'mode'=>'off','policy_ids'=>$selection,
    'expected_plan_sha256'=>$cross_site['plan_sha256'], 'confirmation'=>$remote::CONFIRM));
expect( is_wp_error($foreign_blog) && $foreign_blog->get_error_code()==='mad4b_optional_remote_plan_stale',
    'Reviewed plan must not cross multisite blog context' );
unset($GLOBALS['mock_blog_id']);

$security_target = $remote::plan(array('policy_id'=>$remote::SELECTED,
    'mode'=>'off','policy_ids'=>$selection));
$secure_input = array('policy_id'=>$remote::SELECTED,'mode'=>'off','policy_ids'=>$selection,
    'expected_plan_sha256'=>$security_target['plan_sha256'],'confirmation'=>$remote::CONFIRM);
$before_security = get_option($cls::OPTION,null);
$GLOBALS['mock_admin_allowed']=false;
$actor_denied=$remote::can_apply($secure_input);
expect( is_wp_error($actor_denied)
    && $actor_denied->get_error_code()==='mad4b_optional_remote_staging_authority_denied',
    'Insufficient WordPress administrator capability must prevent remote Apply' );
unset($GLOBALS['mock_admin_allowed']);
$GLOBALS['mock_actor_id']=2;
$not_enrolled=$remote::can_apply($secure_input);
expect( is_wp_error($not_enrolled)
    && $not_enrolled->get_error_code()==='mad4b_optional_remote_staging_authority_denied',
    'Unenrolled actor must not remotely edit optional policy' );
unset($GLOBALS['mock_actor_id']);
MAD4B_SCP_Policy::$write_allowed=false;
$write_denied=$remote::can_apply($secure_input);
expect( is_wp_error($write_denied)
    && $write_denied->get_error_code()==='mad4b_optional_remote_staging_authority_denied',
    'Missing governed Write permission must deny even an advisory option' );
MAD4B_SCP_Policy::$write_allowed=true;
foreach ( array('bearer_valid','scope_valid','client_valid') as $property ) {
    MAD4B_SCP_OAuth_Resource_Bridge::$$property=false;
    $blocked=$remote::can_apply($secure_input);
    expect( is_wp_error($blocked)
        && $blocked->get_error_code()==='mad4b_optional_remote_step_up_missing',
        'Remote mutation must enforce bearer validity, step-up and client binding' );
    MAD4B_SCP_OAuth_Resource_Bridge::$$property=true;
}
expect( get_option($cls::OPTION,null)===$before_security,
    'Denied remote writes must leave stored options untouched' );
MAD4B_SCP_Site_Profile::$environment='production';
$prod_batch=governed_optional_apply_fixture($secure_input);
expect( is_wp_error($prod_batch)
    && $prod_batch->get_error_code()==='mad4b_optional_remote_staging_authority_denied'
    && get_option($cls::OPTION,null)===$before_security,
    'Production remote selected batch must remain denied' );
MAD4B_SCP_Site_Profile::$environment='staging';

// Stale revision from a concurrent local admin save: no replay of reviewed plan.
$stale_plan=$remote::plan(array('policy_id'=>$remote::SELECTED,
    'mode'=>'off','policy_ids'=>$selection));
$changed_modes=array();
foreach ($cls::status()['gates'] as $row) $changed_modes[$row['id']]=$row['configured_mode'];
$changed_modes['machine_ingress_evidence']='advisory';
$current_status=$cls::status();
$local_edit=$cls::persist_modes($changed_modes,$current_status['revision'],$current_status['definitions_sha256']);
expect( ! is_wp_error($local_edit), 'Fixture local change for concurrency regression failed' );
$stale_apply=governed_optional_apply_fixture(array('policy_id'=>$remote::SELECTED,
    'mode'=>'off','policy_ids'=>$selection,
    'expected_plan_sha256'=>$stale_plan['plan_sha256'],'confirmation'=>$remote::CONFIRM));
expect( is_wp_error($stale_apply)
    && $stale_apply->get_error_code()==='mad4b_optional_remote_plan_stale',
    'Concurrent preference update must invalidate earlier remote plans' );
echo "OPTIONAL_GOVERNANCE: PASS (fresh Off, single/all/selected, validation, tenancy, stale revisions, admin/MCP, OAuth/Write, CAS, audit, Production denial)\n";
