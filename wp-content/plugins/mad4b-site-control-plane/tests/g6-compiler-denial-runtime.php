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
class G6_Test_Ability {
	public function execute( $input ) { ++$GLOBALS['g6_test_dispatches']; return array( 'fixture_completed' => true ); }
}
function wp_get_ability( string $name ) { return 'mad4b/test-native-content-apply' === $name ? new G6_Test_Ability() : null; }
function get_post( $id ) { ++$GLOBALS['g6_test_post_reads']; return (object) array( 'ID' => $id, 'post_type' => 'tour', 'post_status' => 'draft' ); }
class MAD4B_SCP_Site_Profile { public static function site_uuid() { return '12345678-1234-1234-1234-123456789abc'; } }
class MAD4B_SCP_Runtime_Generation_Fence {
	public static function capture() { return array( 'generation_sha256' => str_repeat( isset( $GLOBALS['g6_test_generation_char'] ) ? $GLOBALS['g6_test_generation_char'] : '1', 64 ), 'material' => array( 'runtime_sha256' => str_repeat( '2', 64 ) ) ); }
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
class MAD4B_SCP_Content_Experience_Runtime {
	public static function operation_plan( $slug, $operation, $input ) { ++$GLOBALS['g6_test_native_plans']; return new WP_Error( 'fixture_native_plan_stop' ); }
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
		if ( ! empty( $GLOBALS['g6_test_prepare_mutation'] ) ) g6_test_interleave( $GLOBALS['g6_test_prepare_mutation'] );
		$step = array(
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
		if ( isset( $GLOBALS['g6_test_bad_step_identity'] ) ) $step[ $GLOBALS['g6_test_bad_step_identity'] ] = array( 'untyped-identity' );
		return $step;
	}
}
final class G6_Bad_Primitive_Strategy implements MAD4B_SCP_G6_Operation_Strategy {
	private $list;
	public function __construct( $list ) { $this->list = $list; }
	public function strategy_id() { return 'test-invalid-primitives'; }
	public function primitives() { return $this->list; }
	public function prepare( $primitive, array $arguments, array $context ) { return new WP_Error( 'fixture_not_called' ); }
}

// Review-only disposable core fakes. Mutations deliberately interleave after
// an earlier server read; no fixture supplies authority to production code.
function g6_test_interleave( $kind ) {
	if ( 'cancel' === $kind ) { $GLOBALS['g6_test_job']['state'] = 'CANCELLED'; ++$GLOBALS['g6_test_job']['job_revision']; }
	elseif ( 'revision' === $kind ) ++$GLOBALS['g6_test_job']['job_revision'];
	elseif ( 'generation' === $kind ) $GLOBALS['g6_test_generation_char'] = '9';
	elseif ( 'profile' === $kind ) $GLOBALS['g6_test_profile_enabled'] = false;
}
class MAD4B_SCP_Execution_Commit_Guard {}
class MAD4B_SCP_Execution_Fence {
	public static function has_active_frame() { return ! empty( $GLOBALS['g6_test_frame'] ); }
	public static function final_execution_wrapper_verified( $name ) { return 'mad4b/test-native-content-apply' === $name; }
	public static function with_governed_child( $name, $input, $callback, $reason ) {
		if ( ! empty( $GLOBALS['g6_test_child_mutation'] ) ) g6_test_interleave( $GLOBALS['g6_test_child_mutation'] );
		return $callback();
	}
}
class MAD4B_SCP_Durable_Execution {
	public static function scope_key( $site, $capability, $operation, $target ) { return hash( 'sha256', $site . $capability . $operation . $target ); }
	public static function begin_idempotency( $scope, $key, $sha ) {
		$GLOBALS['g6_test_claim_key'] = $key;
		if ( strlen( $key ) > 191 ) return new WP_Error( 'mad4b_idempotency_key_invalid' );
		if ( ! empty( $GLOBALS['g6_test_claim_mutation'] ) ) g6_test_interleave( $GLOBALS['g6_test_claim_mutation'] );
		return array( 'claimed' => empty( $GLOBALS['g6_test_completed_claim'] ), 'result' => array( 'prior_fixture_result' => true ) );
	}
	public static function complete_idempotency( $claim, $result ) { ++$GLOBALS['g6_test_completions']; return true; }
}

$GLOBALS['g6_test_owner'] = true;
$GLOBALS['g6_test_profile_enabled'] = true;
$GLOBALS['g6_test_job'] = array( 'job_id' => 'test-job-17', 'job_revision' => 3, 'state' => 'WAITING_REVIEW', 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(), 'target_post_type' => 'tour' );
g6_test_assert( true === MAD4B_SCP_G6_Operation_Compiler::register_strategy( new G6_Test_Strategy() ), 'server-reviewed strategy registered' );
$node = array( 'node_id' => 'a', 'primitive' => 'content', 'strategy_id' => 'test-reviewed-native', 'arguments' => array( 'content' => 'PRIVATE-CONTENT-556677' ), 'depends_on' => array() );
$input = array( 'job_id' => 'test-job-17', 'expected_job_revision' => 3, 'profile_slug' => 'test-tour', 'nodes' => array( $node ), 'reason' => 'review' );
foreach ( array( array( array( 'content' ) ), array( 'named' => 'content' ), array( 17 ) ) as $bad_primitives )
	g6_test_error( MAD4B_SCP_G6_Operation_Compiler::register_strategy( new G6_Bad_Primitive_Strategy( $bad_primitives ) ), 'mad4b_g6_strategy_primitive', 'primitive declarations must be a typed list before comparisons' );
foreach ( array( 'provider_id', 'capability_id', 'ability_name' ) as $identity ) {
	$GLOBALS['g6_test_bad_step_identity'] = $identity;
	g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $input ), 'mad4b_g6_step_identity', 'untyped ' . $identity . ' must fail before native string APIs' );
}
unset( $GLOBALS['g6_test_bad_step_identity'] );
$native_strategy = new MAD4B_SCP_G6_Native_Content_Strategy();
$native_context = array( 'profile' => array( 'slug' => 'test-tour', 'revision' => 1, 'post_type' => 'tour', 'media_meta_fields' => array( 'hero_image' => array() ) ) );
$GLOBALS['g6_test_post_reads'] = 0; $GLOBALS['g6_test_native_plans'] = 0;
foreach ( array( 'not-a-map', 17, null ) as $bad_meta ) {
	g6_test_error( $native_strategy->prepare( 'media', array( 'post_id' => 17, 'meta' => $bad_meta ), $native_context ), 'mad4b_g6_native_fields', 'native media metadata must fail as a map before array_keys or planner entry' );
}
g6_test_error( $native_strategy->prepare( 'taxonomy', array( 'post_id' => 17, 'taxonomies' => 'not-a-map' ), $native_context ), 'mad4b_g6_native_fields', 'taxonomy data cannot be coerced into a map' );
g6_test_error( $native_strategy->prepare( 'content', array( 'post_id' => -17 ), $native_context ), 'mad4b_g6_native_arguments', 'native post identity must be positive' );
g6_test_assert( 0 === $GLOBALS['g6_test_post_reads'] && 0 === $GLOBALS['g6_test_native_plans'], 'malformed native inputs perform no post read or planner call' );
g6_test_error( $native_strategy->prepare( 'media', array( 'post_id' => 17, 'meta' => array( 'hero_image' => 91 ) ), $native_context ), 'fixture_native_plan_stop', 'well-typed native input still reaches the existing planner boundary' );
$plan = MAD4B_SCP_G6_Operation_Compiler::compile( $input );
g6_test_assert( ! is_wp_error( $plan ) && 1 === count( $plan['nodes'] ), 'typed plan compiles only after explicit strategy admission' );
$view = MAD4B_SCP_G6_Operation_Compiler::review_projection( $plan );
g6_test_assert( ! is_wp_error( $view ) && false === strpos( json_encode( $view ), 'PRIVATE-CONTENT-556677' ), 'review redacts private model inputs' );
g6_test_assert( false === $view['authorizing'] && true === $view['approval_required'], 'review does not grant authority' );

$wrong_strategy_type = $input; $wrong_strategy_type['nodes'][0]['strategy_id'] = array( 'test-reviewed-native' );
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $wrong_strategy_type ), 'mad4b_g6_strategy_missing', 'array strategy id must not trigger illegal offset errors' );
$bad_dependencies = $input; $bad_dependencies['nodes'][0]['depends_on'] = array( array( 'another-node' ) );
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $bad_dependencies ), 'mad4b_g6_dependency_schema', 'nested dependency payload must fail closed before de-duplication' );
$assoc_dependencies = $input; $assoc_dependencies['nodes'][0]['depends_on'] = array( 'arbitrary_key' => 'another-node' );
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $assoc_dependencies ), 'mad4b_g6_dependency_schema', 'associative dependency list is rejected' );
$duplicate_deps = $input; $duplicate_deps['nodes'][0]['depends_on'] = array( 'b', 'b' );
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $duplicate_deps ), 'mad4b_g6_dependency_schema', 'duplicate dependencies are rejected' );
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
$known_job = $GLOBALS['g6_test_job'];
unset( $GLOBALS['g6_test_job']['site_uuid'] );
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $input ), 'mad4b_g6_job_missing', 'missing site identity must fail closed' );
$GLOBALS['g6_test_job'] = $known_job;
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

$wrong_job = $GLOBALS['g6_test_job']; $GLOBALS['g6_test_job']['job_id'] = 'another-job';
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $input ), 'mad4b_g6_job_missing', 'server reader cannot substitute another job identity' );
$GLOBALS['g6_test_job'] = $wrong_job; $GLOBALS['g6_test_job']['job_revision'] = '3';
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $input ), 'mad4b_g6_job_missing', 'persisted job revision cannot be a numeric string' );
$GLOBALS['g6_test_job'] = $wrong_job;
foreach ( array( 'cancel', 'revision', 'generation', 'profile' ) as $interleave ) {
	$GLOBALS['g6_test_prepare_mutation'] = $interleave;
	g6_test_error( MAD4B_SCP_G6_Operation_Compiler::compile( $input ), 'mad4b_g6_compile_context_changed', 'planning must reject ' . $interleave . ' during a strategy read' );
	$GLOBALS['g6_test_job'] = $wrong_job; $GLOBALS['g6_test_generation_char'] = '1'; $GLOBALS['g6_test_profile_enabled'] = true;
}
unset( $GLOBALS['g6_test_prepare_mutation'] );
$GLOBALS['g6_test_job']['state'] = 'RUNNING';
$running_job = $GLOBALS['g6_test_job'];
$running_plan = MAD4B_SCP_G6_Operation_Compiler::compile( $input );
g6_test_assert( ! is_wp_error( $running_plan ), 'running fixture plan compiles' );
$GLOBALS['g6_test_frame'] = true; $GLOBALS['g6_test_dispatches'] = 0; $GLOBALS['g6_test_completions'] = 0;
$executed = MAD4B_SCP_G6_Operation_Compiler::dispatch_step( $running_plan, 'a' );
g6_test_assert( ! is_wp_error( $executed ) && 1 === $GLOBALS['g6_test_dispatches'] && 1 === $GLOBALS['g6_test_completions'], 'unchanged root fixture enters only its typed child and records completion' );
g6_test_assert( $running_plan['plan_sha256'] . ':a' === $GLOBALS['g6_test_claim_key'], 'short persisted compiled-step keys remain unchanged' );
$long_node_id = str_repeat( 'n', 191 ); $long_input = $input; $long_input['nodes'][0]['node_id'] = $long_node_id;
$long_plan = MAD4B_SCP_G6_Operation_Compiler::compile( $long_input );
g6_test_assert( ! is_wp_error( $long_plan ), 'full-length declared node identity still compiles' );
$long_result = MAD4B_SCP_G6_Operation_Compiler::dispatch_step( $long_plan, $long_node_id );
g6_test_assert( ! is_wp_error( $long_result ) && strlen( $GLOBALS['g6_test_claim_key'] ) <= 191, 'full-length node dispatch fits the real durable key bound' );
g6_test_assert( MAD4B_SCP_G6_Contracts::compiled_step_key( $long_plan['plan_sha256'], $long_node_id ) === $GLOBALS['g6_test_claim_key'], 'dispatch uses the shared readback key contract' );
foreach ( array( 'cancel', 'revision', 'generation', 'profile' ) as $interleave ) {
	$GLOBALS['g6_test_dispatches'] = 0; $GLOBALS['g6_test_completions'] = 0;
	$GLOBALS['g6_test_claim_mutation'] = $interleave;
	g6_test_error( MAD4B_SCP_G6_Operation_Compiler::dispatch_step( $running_plan, 'a' ), 'mad4b_g6_replan_required', 'dispatch must reject ' . $interleave . ' during claim acquisition' );
	g6_test_assert( 0 === $GLOBALS['g6_test_dispatches'] && 0 === $GLOBALS['g6_test_completions'], 'claim drift must perform no child call or completion' );
	$GLOBALS['g6_test_job'] = $running_job; $GLOBALS['g6_test_generation_char'] = '1'; $GLOBALS['g6_test_profile_enabled'] = true;
}
unset( $GLOBALS['g6_test_claim_mutation'] );
foreach ( array( 'cancel', 'revision', 'generation', 'profile' ) as $interleave ) {
	$GLOBALS['g6_test_child_mutation'] = $interleave;
	$failed = MAD4B_SCP_G6_Operation_Compiler::dispatch_step( $running_plan, 'a' );
	g6_test_error( $failed, 'mad4b_g6_execution_uncertain', 'dispatch must retain its claim when ' . $interleave . ' races child entry' );
	g6_test_assert( 'mad4b_g6_replan_required' === $failed->get_error_data()['cause'] && 0 === $GLOBALS['g6_test_dispatches'] && 0 === $GLOBALS['g6_test_completions'], 'child-entry drift must retain claim without executing or completing' );
	$GLOBALS['g6_test_job'] = $running_job; $GLOBALS['g6_test_generation_char'] = '1'; $GLOBALS['g6_test_profile_enabled'] = true;
}
unset( $GLOBALS['g6_test_child_mutation'] );
$GLOBALS['g6_test_completed_claim'] = true; $GLOBALS['g6_test_claim_mutation'] = 'cancel';
g6_test_error( MAD4B_SCP_G6_Operation_Compiler::dispatch_step( $running_plan, 'a' ), 'mad4b_g6_replan_required', 'completed claim replay cannot bypass the current cancelled job' );
unset( $GLOBALS['g6_test_completed_claim'], $GLOBALS['g6_test_claim_mutation'] );
$GLOBALS['g6_test_job'] = $running_job; $GLOBALS['g6_test_frame'] = false;

echo "mad4b.feature007-g6-compiler-denials.v1: PASS\n";
