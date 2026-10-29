<?php
/**
 * Hermetic PHP 7.4 / 8.3 route-selection regression.
 * No WordPress installation, network, filesystem mutation or owner credential.
 */
define( 'ABSPATH', '/test/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['test_can_manage'] = true;
$GLOBALS['test_env'] = 'staging';
$GLOBALS['native_plan'] = array();
$GLOBALS['selected_plan'] = array();
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['test_can_manage']; }
function wp_get_environment_type() { return $GLOBALS['test_env']; }
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Self_Update {
    public static function native_plan( $input ) { return $GLOBALS['native_plan']; }
}
class MAD4B_SCP_Selected_Head_Update {
    public static $calls = 0;
    public static $received_sha = null;
    public static function plan( $input ) {
        self::$calls++;
        self::$received_sha = $input['candidate_source']['reference'];
        return $GLOBALS['selected_plan'];
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-control-plane-test-router.php';

function check( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
$sha = str_repeat( 'a', 40 );
$digest = str_repeat( 'b', 64 );
$native = array(
    'eligible' => false,
    'blockers' => array( 'native_release_source_lineage_unverified' ),
    'target' => array(
        'published_from_master' => true, 'release_root_trust_verified' => true,
        'release_verdict_success' => true, 'source_commit_sha' => $sha,
        'archive_sha256' => $digest,
    ),
);
$selected = array(
    'eligible' => true, 'blockers' => array(),
    'source' => array( 'resolved_sha' => $sha ),
    'package_identity' => array( 'source_commit_sha' => $sha ),
);
$router = 'MAD4B_SCP_Control_Plane_Test_Router';

$result = $router::reduce( $native, $selected, 'staging' );
check( 'certified_selected_head' === $result['selected_route_id'], 'Prefer eligible selected head despite native lineage block' );
check( 'eligible_for_separate_governed_review' === $result['state'], 'Honest review state' );
check( false === $result['automatic_mutation_allowed'] && false === $result['runtime_certified'], 'No implicit authority or certification' );
check( in_array( 'native_release_source_lineage_unverified', $result['routes'][1]['blockers'], true ), 'Preserve original native blocker' );

$blocked_selected = $selected;
$blocked_selected['eligible'] = false;
$blocked_selected['blockers'] = array( 'selected_head_opt_in_disabled' );
$native_eligible = $native;
$native_eligible['eligible'] = true;
$native_eligible['blockers'] = array();
$result = $router::reduce( $native_eligible, $blocked_selected, 'staging' );
check( 'governed_native_release' === $result['selected_route_id'], 'Use verified native fallback' );

$result = $router::reduce( $native, $blocked_selected, 'staging' );
check( null === $result['selected_route_id'], 'Do not select blocked routes' );
check( 'mad4b/wordpress-native-candidate-opt-in-plan' === $result['next_read_ability'], 'Offer opt-in review, not automatic permission' );

$bad_sha = $selected;
$bad_sha['source']['resolved_sha'] = str_repeat( 'c', 40 );
$result = $router::reduce( $native, $bad_sha, 'staging' );
check( null === $result['selected_route_id'], 'Reject selected plan pointing at another source SHA' );

$result = $router::reduce( $native_eligible, $selected, 'production' );
check( 'environment_blocked' === $result['state'] && null === $result['selected_route_id'], 'Production always denied' );

$unsigned = $native_eligible;
$unsigned['target']['release_root_trust_verified'] = false;
$result = $router::reduce( $unsigned, $selected, 'staging' );
check( 'certified_source_unavailable' === $result['state'], 'Reject unsigned release' );

$GLOBALS['native_plan'] = $native;
$GLOBALS['selected_plan'] = $selected;
$GLOBALS['test_env'] = 'staging';
$result = $router::inspect();
check( $sha === MAD4B_SCP_Selected_Head_Update::$received_sha, 'Dynamic preflight uses only signed manifest source' );
check( 'certified_selected_head' === $result['selected_route_id'], 'Live provider result drives recommendation' );

$calls = MAD4B_SCP_Selected_Head_Update::$calls;
$GLOBALS['test_env'] = 'production';
$result = $router::inspect();
check( 'environment_blocked' === $result['state'] && $calls === MAD4B_SCP_Selected_Head_Update::$calls, 'No network preflight in Production' );

$GLOBALS['test_env'] = 'staging';
$GLOBALS['test_can_manage'] = false;
$result = $router::inspect();
check( is_wp_error( $result ) && 'mad4b_test_route_read_denied' === $result->get_error_code(), 'Permission boundary' );
$GLOBALS['test_can_manage'] = true;
$result = $router::inspect( array( 'native' => $native_eligible ) );
check( is_wp_error( $result ) && 'mad4b_test_route_input_invalid' === $result->get_error_code(), 'Reject caller-controlled preflight' );


// Verify that the MCP discovery surface is registered read-only and uses the
// independent governed policy, not a write/approval or caller-supplied plan.
$GLOBALS['ability_registration'] = array();
function add_action( $hook, $handler, $priority = 10 ) { $GLOBALS['test_action_hook'] = $hook; }
function wp_has_ability( $name ) { return false; }
function wp_register_ability( $name, $args ) { $GLOBALS['ability_registration'][ $name ] = $args; }
$router::boot();
check( 'wp_abilities_api_init' === $GLOBALS['test_action_hook'], 'Register on WordPress Abilities hook' );
$router::register_ability();
$reg = $GLOBALS['ability_registration'][ $router::ABILITY ] ?? array();
check( ( $reg['category'] ?? '' ) === 'mad4b-read', 'MCP read category only' );
check( true === ( $reg['meta']['annotations']['readonly'] ?? false ), 'Read-only tool annotation' );
check( false === ( $reg['meta']['public'] ?? true ), 'Never public capability' );
check( ( $reg['execute_callback'][1] ?? '' ) === 'inspect', 'Unforgeable local provider callback' );

echo "PASS: certified test route selector (hermetic security and fallback checks)\n";
