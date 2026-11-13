<?php
/** No WordPress DB, no OAuth token, no host actions. Governance denial contract. */
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $msg = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $value, $options = 0 ) { return json_encode( $value, $options ); }
function sanitize_key( $name ) {
    return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $name ) );
}
function add_action( ...$args ) {}
function get_option( $name, $default = '' ) { return $default; }
function wp_get_environment_type() { return $GLOBALS['test_env'] ?? 'staging'; }
class MAD4B_SCP_Site_Profile {
    public static function current_environment() { return $GLOBALS['test_env'] ?? 'staging'; }
    public static function managed_runtime_enabled() { return empty( $GLOBALS['test_managed_runtime_disabled'] ); }
    public static function status() {
        return array( 'authority_ready' => empty( $GLOBALS['test_site_authority_revoked'] ) );
    }
    public static function nonproduction_governed( $feature = '' ) {
        return in_array( self::current_environment(), array( 'local', 'development', 'staging' ), true )
            && ( 'write' !== $feature || empty( $GLOBALS['test_site_write_disabled'] ) );
    }
}
class MAD4B_SCP_Registered_Write_Admission {
    public static function preflight( $name ) {
        if ( 'mad4b/operator-grants-converge' !== $name || ! empty( $GLOBALS['registered_policy_revoked'] ) )
            return new WP_Error( 'mad4b_registered_operation_policy_missing' );
        if ( ! empty( $GLOBALS['test_site_write_disabled'] ) )
            return new WP_Error( 'mad4b_registered_write_site_unverified' );
        if ( 'production' === MAD4B_SCP_Site_Profile::current_environment()
            && empty( $GLOBALS['test_production_governance_active'] ) )
            return new WP_Error( 'mad4b_registered_write_runtime_policy_required' );
        if ( ! empty( $GLOBALS['test_governance_denied'] ) )
            return new WP_Error( 'mad4b_registered_write_governance_denied' );
        return array(
            'contract' => 'mad4b.registered-write-admission.v1',
            'state' => 'eligible_for_exact_canonical_plan_only',
            'ability' => $name,
            'operation_id' => 'wordpress.developer.operator-grants.converge',
            'environment' => MAD4B_SCP_Site_Profile::current_environment(),
            'site_uuid' => '11111111-1111-4111-8111-111111111111',
            'profile_revision' => 2,
            'profile_digest' => str_repeat( 'c', 64 ),
            'policy_sha256' => str_repeat( 'd', 64 ),
            'authorization_issued' => false, 'mutation_performed' => false );
    }
}
class MAD4B_SCP_Local_OAuth_Server {
    const CHATGPT_CIMD_CLIENT_ID = 'https://chatgpt.com/connector-client';
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    public static function verified_bearer_active() { return empty( $GLOBALS['test_bearer_revoked'] ); }
    public static function verified_bearer_has_scope( $scope ) {
        return empty( $GLOBALS['test_verified_scope_missing'] ) && $scope === 'mad4b:authority:step-up';
    }
    public static function verified_bearer_client_is( $client_id ) {
        return empty( $GLOBALS['test_other_client'] )
            && $client_id === MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID;
    }
}
class MAD4B_SCP_Identity_Context {
    public static function current() {
        return array( 'token_scopes' => $GLOBALS['test_scopes'] ?? array( 'mad4b:authority:step-up' ) );
    }
}
class MAD4B_SCP_Developer_Authority {
    public static $apply_calls = 0;
    public static function can_access( $x = null ) { return true; }
    public static function status() {
        return array( 'normal_authority' => array(
            'ready' => true, 'tool_count' => 9, 'exact_grant_count' => 9,
            'blockers' => array(),
        ) );
    }
    public static function plan() {
        $ops = array(
            'mad4b/operator-session-open', 'mad4b/operator-session-status',
            'mad4b/operator-session-close', 'mad4b/operator-operation-plan',
            'mad4b/operator-operation-submit',
        );
        if ( ! empty( $GLOBALS['test_scope_drift'] ) ) $ops[] = 'mad4b/developer-shell-unrestricted';
        $rows = array_fill( 0, 9, array( 'ability' => 'legacy', 'exact_grant_present' => true ) );
        foreach ( $ops as $ability ) $rows[] = array( 'ability' => $ability, 'exact_grant_present' => false );
        $plan = array(
            'contract' => 'mad4b.developer-authority.v1',
            'production_allowed' => false,
            'environment' => MAD4B_SCP_Site_Profile::current_environment(),
            'ready_to_apply' => empty( $GLOBALS['test_plan_blocked'] ),
            'blockers' => empty( $GLOBALS['test_plan_blocked'] ) ? array() : array( 'uncertified_manifest' ),
            'tool_count' => count( $rows ),
            'rows' => $rows,
            'source_commit_sha' => str_repeat( 'b', 40 ),
            'site_uuid' => '11111111-1111-4111-8111-111111111111',
            'site_profile_revision' => 2, 'site_profile_digest' => str_repeat( 'c', 64 ),
        );
        $plan['plan_sha256'] = hash( 'sha256', wp_json_encode( $plan,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        return $plan;
    }
    public static function apply( $input ) {
        ++self::$apply_calls;
        return new WP_Error( 'test_must_not_reach_canonical_apply' );
    }
}
require_once __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-developer-authority-policy-v2.php';
require_once __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-grant-convergence.php';
function check( $label, $value, $expected_error ) {
    $error = is_wp_error( $value ) ? $value->get_error_code() : '';
    if ( $expected_error && $error !== $expected_error ) {
        fwrite( STDERR, 'FAIL ' . $label . ': ' . $error . PHP_EOL ); exit( 1 );
    }
    if ( ! $expected_error && ( $error || ! is_array( $value ) || empty( $value['ready_to_apply'] )
        || count( $value['new_grants'] ?? array() ) !== 5
        || ! empty( $value['mutation_performed'] )
        || ( $value['developer_policy_v2']['contract'] ?? null )
            !== MAD4B_SCP_Developer_Authority_Policy_V2::CONTRACT
        || ( $value['developer_policy_v2']['legacy_plan_sha256'] ?? null )
            !== $value['canonical_plan_sha256']
        || ( $value['developer_policy_v2']['authorization_issued'] ?? null ) !== false ) ) {
        fwrite( STDERR, 'FAIL ' . $label . ': expected read-only exact-five plan' . PHP_EOL ); exit( 2 );
    }
    echo 'PASS ', $label, PHP_EOL;
}
check( 'exact five grants plan', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), null );
check( 'explicit approval required', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'apply' ) ), 'mad4b_operator_grants_confirmation_or_plan_missing' );
$GLOBALS['test_scope_drift'] = true;
check( 'unexpected capability rejected', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_operator_grants_canonical_plan_blocked' );
unset( $GLOBALS['test_scope_drift'] );
$GLOBALS['test_plan_blocked'] = true;
check( 'uncertified runtime rejected', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_operator_grants_canonical_plan_blocked' );
unset( $GLOBALS['test_plan_blocked'] );
$GLOBALS['test_scopes'] = array( 'mad4b:read' );
check( 'OAuth step-up missing', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_operator_grants_step_up_required' );
unset( $GLOBALS['test_scopes'] );
$GLOBALS['test_other_client'] = true;
check( 'cross-client OAuth denied', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_operator_grants_chatgpt_client_required' );
unset( $GLOBALS['test_other_client'] );
$GLOBALS['test_bearer_revoked'] = true;
check( 'revoked bearer denied', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_operator_grants_step_up_required' );
unset( $GLOBALS['test_bearer_revoked'] );
$GLOBALS['test_verified_scope_missing'] = true;
check( 'verified scope absent denied', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_operator_grants_step_up_required' );
unset( $GLOBALS['test_verified_scope_missing'] );
$GLOBALS['test_managed_runtime_disabled'] = true;
check( 'managed runtime disabled', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_operator_grants_site_not_ready' );
unset( $GLOBALS['test_managed_runtime_disabled'] );
$GLOBALS['test_site_authority_revoked'] = true;
check( 'site authority revoked', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_operator_grants_site_not_ready' );
unset( $GLOBALS['test_site_authority_revoked'] );
$GLOBALS['test_site_write_disabled'] = true;
check( 'profile write disabled', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_registered_write_site_unverified' );
unset( $GLOBALS['test_site_write_disabled'] );
$GLOBALS['test_env'] = 'production';
check( 'production write without current governance denied', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_registered_write_runtime_policy_required' );
$GLOBALS['test_production_governance_active'] = true;
check( 'production with current governance yields read-only canonical plan', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), null );
$GLOBALS['registered_policy_revoked'] = true;
check( 'missing operation policy denied', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_registered_operation_policy_missing' );
unset( $GLOBALS['registered_policy_revoked'] );
$GLOBALS['test_governance_denied'] = true;
check( 'policy-denied production rejected', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_registered_write_governance_denied' );
unset( $GLOBALS['test_governance_denied'] );
if ( MAD4B_SCP_Developer_Authority::$apply_calls !== 0 ) {
    fwrite( STDERR, 'FAIL canonical apply touched' . PHP_EOL ); exit( 3 );
}
echo 'PASS no grant mutations or Production effects' . PHP_EOL;
