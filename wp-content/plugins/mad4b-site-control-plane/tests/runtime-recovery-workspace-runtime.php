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
$empty = array( 'contract' => 'mad4b.staging-convergence-plan.v1', 'read_only' => true, 'mutation_performed' => false, 'production_mutation_performed' => false, 'current_ready' => true, 'blocking_gates' => array(), 'actions' => array(), 'gate_coverage_complete' => true, 'plan_integrity_blockers' => array(), 'plan_sha256' => str_repeat( 'b', 64 ), 'plan_binding' => array( 'source_commit_sha' => str_repeat( 'a', 40 ) ) );
assert_recovery( 'OBSERVED_READY' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $empty )['state'], 'explicit ready plan should show its observed state' );
$incomplete = $empty;
unset( $incomplete['gate_coverage_complete'] );
assert_recovery( 'REVIEW_REQUIRED' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $incomplete )['state'], 'no coverage cannot pass' );
$incomplete = $empty;
$incomplete['plan_integrity_blockers'] = array( 'source_drift' );
assert_recovery( 'REVIEW_REQUIRED' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $incomplete )['state'], 'integrity blocker cannot pass' );
$incomplete = $empty;
$incomplete['plan_binding']['source_commit_sha'] = 'unbound';
assert_recovery( 'REVIEW_REQUIRED' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $incomplete )['state'], 'unbound source cannot pass' );
$incomplete = $empty;
unset( $incomplete['plan_sha256'] );
assert_recovery( 'REVIEW_REQUIRED' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $incomplete )['state'], 'missing digest cannot pass' );
$incomplete = $empty;
unset( $incomplete['production_mutation_performed'] );
assert_recovery( 'UNAVAILABLE' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $incomplete )['state'], 'missing mutation evidence is not trusted' );
$incomplete = $empty;
unset( $incomplete['blocking_gates'] );
assert_recovery( 'REVIEW_REQUIRED' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $incomplete )['state'], 'unknown blocker inventory cannot be treated as clear' );
$incomplete = $empty;
$incomplete['read_only'] = 1;
assert_recovery( 'UNAVAILABLE' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $incomplete )['state'], 'read-only must be strict boolean true' );
$truncated = $valid;
$truncated['actions'] = array_fill( 0, 100, array( 'action_id' => 'candidate_binding_only' ));
assert_recovery( 1 === MAD4B_SCP_Runtime_Recovery_Workspace::model( $truncated )['action_count'], 'workspace must bound and deduplicate untrusted action lists' );

$mapped = $valid;
$mapped['blocking_gates'] = array( 'skills_runtime', 'write_authority', 'browser_runtime' );
$mapped['actions'][0]['target_gates'] = array( 'skills_runtime', 'skills_runtime', '../untrusted_gate' );
$mapped['actions'][1]['target_gates'] = array( 'write_authority' );
$mapped['actions'][2]['target_gates'] = array( 'browser_runtime' );
$mapped_model = MAD4B_SCP_Runtime_Recovery_Workspace::model( $mapped );
assert_recovery( 3 === $mapped_model['blocked_gate_count'], 'blocked gate count must match current plan' );
assert_recovery( array( 'browser_runtime' ) === $mapped_model['unmapped_blocking_gates'], 'duplicate action must not fabricate gate closure' );
assert_recovery( 'UNMAPPED_GATE_REQUIRES_REVIEW' === $mapped_model['plan_gate_coverage'], 'unmapped blocked gate must be explicit' );
assert_recovery( array( 'skills_runtime' ) === $mapped_model['actions'][0]['target_gates'], 'unsafe or duplicate target was accepted' );
assert_recovery( ! $mapped_model['actions'][0]['readback_verified'] && ! $mapped_model['actions'][0]['retry_authorized']
 && ! $mapped_model['actions'][0]['approval_granted'], 'plan must not create verification or authority' );
$mapped['blocking_gates'] = array( 'skills_runtime', 'write_authority' );
assert_recovery( 'MAPPED_FOR_REVIEW' === MAD4B_SCP_Runtime_Recovery_Workspace::model( $mapped )['plan_gate_coverage'], 'mapping is not acceptance' );
$malformed = $mapped;
$malformed['blocking_gates'][] = str_repeat( 'x', 2048 );
$malformed['blocking_gates'][] = '<script>unsafe</script>';
assert_recovery( 2 === MAD4B_SCP_Runtime_Recovery_Workspace::model( $malformed )['blocked_gate_count'], 'unsafe gate text leaked' );

if ( ! function_exists( '__' ) ) { function __( $text, $domain = '' ) { return $text; } }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-operator-workspace.php';
$scope_by_id = function ( $snapshot ) {
 $rows = array();
 foreach ( MAD4B_SCP_Operator_Workspace::readiness_scopes( $snapshot ) as $row ) $rows[ $row['id'] ] = $row;
 return $rows;
};
$unknown = $scope_by_id( array( 'state' => 'HEALTHY' ) );
assert_recovery( 'NOT_VERIFIED' === $unknown['governed_write']['state'] && 'NOT_VERIFIED' === $unknown['host_execution']['state'], 'unavailable observation fabricated readiness' );
$ready = $scope_by_id( array(
 'state' => 'HEALTHY',
 'signals' => array( 'write_authority_ready' => true, 'candidate_binding_match' => true, 'database_topology_ready' => true, 'governed_write_lane_ready' => true ),
 'operational' => array( 'lanes' => array( 'developer_execution' => array( 'execution_ready' => false ) ) ),
) );
assert_recovery( 'OBSERVED_READY' === $ready['governed_write']['state'], 'all local write observations should suffice for local scope' );
assert_recovery( 'BLOCKED' === $ready['host_execution']['state'], 'authority never implies Host sandbox execution' );
assert_recovery( 'INDEPENDENT_ACCEPTANCE_REQUIRED' === $ready['staging_release']['state'], 'local HEALTHY is not Staging release' );
assert_recovery( 'NOT_AUTHORIZED_HERE' === $ready['production_promotion']['state'], 'operator cannot grant Production promotion' );
$partial = $scope_by_id( array( 'signals' => array( 'write_authority_ready' => false ) ) );
assert_recovery( 'BLOCKED' === $partial['governed_write']['state'], 'explicit failed gate must override unknown gates' );
$forged = $scope_by_id( array( 'state' => 'HEALTHY', 'signals' => array(
 'write_authority_ready' => 1, 'candidate_binding_match' => 1, 'database_topology_ready' => 1, 'governed_write_lane_ready' => 1 ),
 'production_authorized' => true, 'release_certified' => true ) );
assert_recovery( 'NOT_VERIFIED' === $forged['governed_write']['state']
 && 'INDEPENDENT_ACCEPTANCE_REQUIRED' === $forged['staging_release']['state']
 && 'NOT_AUTHORIZED_HERE' === $forged['production_promotion']['state'], 'truthy or forged success accepted' );

echo "MAD4B native runtime recovery workspace PASS\\n";
