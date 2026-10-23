<?php
/** No WordPress DB, no OAuth token, no host actions. Governance denial contract. */
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $msg = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( ...$args ) {}
function get_option( $name, $default = '' ) { return $default; }
function wp_get_environment_type() { return $GLOBALS['test_env'] ?? 'staging'; }
class MAD4B_SCP_Site_Profile {
    public static function current_environment() { return $GLOBALS['test_env'] ?? 'staging'; }
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
        return array(
            'ready_to_apply' => empty( $GLOBALS['test_plan_blocked'] ),
            'blockers' => empty( $GLOBALS['test_plan_blocked'] ) ? array() : array( 'uncertified_manifest' ),
            'tool_count' => count( $rows ),
            'rows' => $rows,
            'plan_sha256' => str_repeat( 'a', 64 ),
            'source_commit_sha' => str_repeat( 'b', 40 ),
            'site_uuid' => '11111111-1111-4111-8111-111111111111',
            'site_profile_revision' => 2, 'site_profile_digest' => str_repeat( 'c', 64 ),
        );
    }
    public static function apply( $input ) {
        ++self::$apply_calls;
        return new WP_Error( 'test_must_not_reach_canonical_apply' );
    }
}
require_once __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-grant-convergence.php';
function check( $label, $value, $expected_error ) {
    $error = is_wp_error( $value ) ? $value->get_error_code() : '';
    if ( $expected_error && $error !== $expected_error ) {
        fwrite( STDERR, 'FAIL ' . $label . ': ' . $error . PHP_EOL ); exit( 1 );
    }
    if ( ! $expected_error && ( $error || ! is_array( $value ) || empty( $value['ready_to_apply'] )
        || count( $value['new_grants'] ?? array() ) !== 5
        || ! empty( $value['mutation_performed'] ) ) ) {
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
$GLOBALS['test_env'] = 'production';
check( 'production denied', MAD4B_SCP_Operator_Grant_Convergence::execute( array( 'action' => 'plan' ) ), 'mad4b_operator_grants_staging_only' );
if ( MAD4B_SCP_Developer_Authority::$apply_calls !== 0 ) {
    fwrite( STDERR, 'FAIL canonical apply touched' . PHP_EOL ); exit( 3 );
}
echo 'PASS no grant mutations or Production effects' . PHP_EOL;
