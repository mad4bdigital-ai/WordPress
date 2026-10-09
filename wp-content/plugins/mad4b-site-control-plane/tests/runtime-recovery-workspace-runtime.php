<?php
// Native PHP, no WordPress or database dependency: tests the pure read-only model.
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', (string) $value ) ); }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-recovery-workspace.php';
function assert_recovery( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, $message . PHP_EOL ); exit( 1 ); }
}
$valid = array(
	'contract' => 'mad4b.staging-convergence-plan.v1',
	'read_only' => true,
	'mutation_performed' => false,
	'production_mutation_performed' => false,
	'current_ready' => false,
	'blocking_gates' => array( 'skills_runtime', 'write_authority' ),
	'plan_sha256' => str_repeat( 'a', 64 ),
	'actions' => array(
		array( 'action_id' => 'managed_skills_runtime_refresh', 'kind' => 'bounded_native_convergence', 'executor' => 'existing_runtime_convergence_worker', 'human_decision_required' => false, 'readback_ability' => 'mad4b/skill-runtime-certification' ),
		array( 'action_id' => 'candidate_binding_only', 'kind' => 'governed_mutation', 'executor' => 'wordpress_native', 'human_decision_required' => true, 'automatic_execution_allowed' => false, 'depends_on' => array( 'managed_skills_runtime_refresh' ), 'readback_ability' => 'mad4b/write-runtime-certification' ),
		array( 'action_id' => 'candidate_binding_only', 'kind' => 'governed_mutation', 'human_decision_required' => false ),
	),
);
$observed = MAD4B_SCP_Runtime_Recovery_Workspace::model( $valid );
assert_recovery( 'REVIEW_REQUIRED' === $observed['state'], 'not-ready gates cannot become ready' );
assert_recovery( 2 === $observed['action_count'], 'duplicate actions are not safe to present' );
assert_recovery( 'mad4b-control-plane-skills' === $observed['actions'][0]['link_slug'], 'Skills route incorrect' );
assert_recovery( 'APPROVAL_REQUIRED' === $observed['actions'][1]['classification'], 'governed mutation must require approval' );
assert_recovery( array( 'managed_skills_runtime_refresh' ) === $observed['actions'][1]['depends_on'], 'dependency was lost' );
assert_recovery( $observed['read_only'] && ! $observed['authorizing'] && ! $observed['mutation_performed'], 'workspace became authorizing' );
assert_recovery( 0 === $observed['automatic_executions_performed'], 'workspace claimed an execution' );
$bad_contract = $valid;
$bad_contract['contract'] = 'unknown';
assert_recovery( 'UNAVAILABLE' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $bad_contract )['state'], 'invalid contract must fail closed' );
$mutated = $valid;
$mutated['mutation_performed'] = true;
assert_recovery( 'UNAVAILABLE' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $mutated )['state'], 'mutating plan must fail closed' );
$production = $valid;
$production['production_mutation_performed'] = true;
assert_recovery( 'UNAVAILABLE' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $production )['state'], 'production mutation must fail closed' );
$empty = array( 'contract' => 'mad4b.staging-convergence-plan.v1', 'read_only' => true, 'current_ready' => true, 'blocking_gates' => array(), 'actions' => array() );
assert_recovery( 'OBSERVED_READY' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $empty )['state'], 'explicit ready plan should show its observed state' );
$truncated = $valid;
$truncated['actions'] = array_fill( 0, 100, array( 'action_id' => 'candidate_binding_only' ));
assert_recovery( 1 === MAD4B_SCP_Runtime_Recovery_Workspace::model( $truncated )['action_count'], 'workspace must bound and deduplicate untrusted action lists' );
echo "MAD4B native runtime recovery workspace PASS\\n";
