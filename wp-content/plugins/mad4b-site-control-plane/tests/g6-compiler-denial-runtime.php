<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code;
	private $data;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_current_user_id() { return $GLOBALS['g6_test_owner'] ? 17 : 0; }
function current_user_can( $cap, $id = 0 ) { return $GLOBALS['g6_test_owner'] && in_array( $cap, array( 'manage_options', 'edit_post' ), true ); }
function wp_get_environment_type() { return 'staging'; }
function wp_get_ability( $name ) { return 'mad4b/test-native-content-apply' === $name ? new stdClass() : null; }
class MAD4B_SCP_Site_Profile { public static function site_uuid() { return '12345678-1234-1234-1234-123456789abc'; } }
class MAD4B_SCP_Runtime_Generation_Fence {
	public static function capture() { return array( 'generation_sha256' => str_repeat( '1', 64 ), 'material' => array( 'runtime_sha256' => str_repeat( '2', 64 ) ) ); }
}
class MAD4B_SCP_Restore_Epoch { public static function material() { return array( 'epoch' => 1 ); } }
class MAD4B_SCP_Content_Jobs {
	public static function get_job( $input ) { return array( 'job' => $GLOBALS['g6_test_job'] ); }
}
class MAD4B_SCP_Content_Experience_Profiles {
	public static function profile( $slug ) {
		return 'test-tour' === $slug ? array( 'slug' => 'test-tour', 'enabled' => $GLOBALS['g6_test_profile_enabled'], 'post_type' => 'tour' ) : new WP_Error( 'unknown_profile' );
	}
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g6-operation-compiler.php';

function g6_test_assert( $condition, $label ) {
	if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
function g6_test_error( $value, $code, $label ) {
	g6_test_assert( is_wp_error( $value ) && $code === $value->get_error_code(), $label . ': ' . ( is_wp_error( $value ) ? $value->get_error_code() : 'no-error' ) );
}
final class G6_Test_Strategy implements MAD4B_SCP_G6_Operation_Strategy {
	public function strategy_id() { return 'test-reviewed-native'; }
	public function primitives() { return array( 'content', 'publication' ); }
	public function prepare( $primitive, array $arguments, array $context ) {
		return array(
			'provider_id' => 'test-reviewed-provider',
			'capability_id' => 'content_experience.update',
			'ability_name' => 'mad4b/test-native-content-apply',
			'typed_input' => array( 'post_content' => $arguments['content'] ),
			'schema_sha256' => str_repeat( '3', 64 ),
			'provider_binding_sha256' => str_repeat( isset( $GLOBALS['g6_test_pin_char'] ) ? $GLOBALS['g6_test_pin_char'] : '4', 64 ),
			'native_plan_sha256' => str_repeat( '5', 64 ),
			'object_pins' => array( array( 'resource_id' => 'post:17', 'state_sha256' => str_repeat( '6', 64 ) ) ),
			'permissions' => array( array( 'capability' => 'edit_post', 'object_id' => 17 ) ),
			'effects' => array( array( 'kind' => ! empty( $arguments['hidden_publish'] ) ? 'publication' : 'local_content', 'reversibility' => 'reversible' ) ),
			'compensation' => array( 'automatic' => false, 'readback_required' => true ),
			'before' => array( 'revision' => 1 ),
			'after' => array( 'private_text' => $arguments['content'] ),
		);
	}
}

$GLOBALS['g6_test_owner'] = true;
$GLOBALS['g6_test_profile_enabled'] = true;
$GLOBALS['g6_test_job'] = array( 'job_id' => 'test-job-17', 'job_revision' => 3, 'state' => 'WAITING_REVIEW', 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(), 'target_post_type' => 'tour' );
g6_test_assert( true === MAD4B_SCP_G6_Operation_Compiler::register_strategy( new G6_Test_Strategy() ), 'server-reviewed strategy registered' );
$node = array( 'node_id' => 'a', 'primitive' => 'content', 'strategy_id' => 'test-reviewed-native', 'arguments' => array( 'content' => 'PRIVATE-CONTENT-556677' ), 'depends_on' => array() );
$input = array( 'job_id' => 'test-job-17', 'expected_job_revision' => 3, 'profile_slug' => 'test-tour', 'nodes' => array( $node ), 'reason' => 'review' );
$plan = MAD4B_SCP_G6_Operation_Compiler::compile( $input );
g6_test_assert( ! is_wp_error( $plan ) && 1 === count( $plan['nodes'] ), 'typed plan compiles only after explicit strategy admission' );
$view = MAD4B_SCP_G6_Operation_Compiler::review_projection( $plan );
g6_test_assert( ! is_wp_error( $view ) && false === strpos( json_encode( $view ), 'PRIVATE-CONTENT-556677' ), 'review redacts private model inputs' );
g6_test_assert( false === $view['authorizing'] && true === $view['approval_required'], 'review does not grant authority' );

$cycleA = $node; $cycleA['depends_on'] = array( 'b' );
$cycleB = $node; $cycleB['node_id'] = 'b'; $cycleB['depends_on'] = array( 'a' );
$cycle = $input; $cycle['nodes'] = array( $cycleA, $cycleB );
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $cycle ), 'mad4b_g6_dag_cycle', 'cycle is denied' );
$missing = $input; $missing['nodes'][0]['strategy_id'] = 'removed-strategy';
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $missing ), 'mad4b_g6_strategy_missing', 'removed provider is denied' );
$stale = $input; $stale['expected_job_revision'] = 2;
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $stale ), 'mad4b_g6_job_revision', 'stale revision is denied' );
$poisoned = $input; $poisoned['nodes'][0]['arguments']['approval_override'] = true;
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $poisoned ), 'mad4b_g6_untrusted_control_key', 'generated control keys cannot create authority' );
$publish = $input; $publish['nodes'][0]['arguments']['hidden_publish'] = true;
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $publish ), 'mad4b_g6_hidden_publication', 'hidden publication effect denied' );
$GLOBALS['g6_test_job']['state'] = 'FAILED';
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $input ), 'mad4b_g6_job_recovery_required', 'failed job requires transition through recovery' );
$GLOBALS['g6_test_job']['state'] = 'WAITING_REVIEW';
$GLOBALS['g6_test_profile_enabled'] = false;
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $input ), 'mad4b_g6_profile_job_mismatch', 'disabled profile denied' );
$GLOBALS['g6_test_profile_enabled'] = true;
$GLOBALS['g6_test_pin_char'] = '5';
$drift = MAD4B_SCP_G6_Operation_Compiler::revalidate( array( 'plan' => $plan ) );
g6_test_error( $drift, 'mad4b_g6_replan_required', 'provider pin drift invalidates approval' );
$redacted = $drift->get_error_data();
g6_test_assert( isset( $redacted['diff_summary_sha256'] ) && ! isset( $redacted['diffs'] ) && true === $redacted['diff_paths_redacted'], 'revalidation errors must expose digest only, no source paths' );
$GLOBALS['g6_test_pin_char'] = '4';
$GLOBALS['g6_test_owner'] = false;
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $input ), 'mad4b_g6_owner_required', 'nonadmin compilation denied' );
$GLOBALS['g6_test_owner'] = true;
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::dispatch_step( $plan, 'a' ), 'mad4b_g6_execution_frame_required', 'no governed frame means no execution' );

echo "mad4b.feature007-g6-compiler-denials.v1: PASS\n";
