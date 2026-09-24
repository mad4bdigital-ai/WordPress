<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function mad4b_governance_runtime_fail( $message ) {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

if ( ! class_exists( 'MAD4B_SCP_Plugin_Lifecycle' ) || ! class_exists( 'MAD4B_SCP_Workflow_Providers' ) || ! class_exists( 'MAD4B_SCP_Operating_Model' ) ) {
	mad4b_governance_runtime_fail( 'workflow/lifecycle/operating-model governance classes are unavailable' );
}

$control_plane = plugin_basename( MAD4B_SCP_FILE );
$input = array(
	'plugin' => $control_plane,
	'desired_active' => false,
	'reason' => 'disposable runtime protected plugin preflight',
);
$first = MAD4B_SCP_Plugin_Lifecycle::plan( $input );
$second = MAD4B_SCP_Plugin_Lifecycle::plan( $input );
if ( is_wp_error( $first ) || is_wp_error( $second ) ) {
	mad4b_governance_runtime_fail( 'protected plugin lifecycle plan unexpectedly errored' );
}
if ( ! empty( $first['eligible'] ) ) {
	mad4b_governance_runtime_fail( 'Control Plane deactivation plan must fail closed' );
}
if ( ! in_array( 'protected_control_plane_dependency', $first['blockers'], true ) ) {
	mad4b_governance_runtime_fail( 'Control Plane deactivation plan did not expose protected dependency blocker' );
}
if ( empty( $first['state_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $first['state_sha256'] ) ) {
	mad4b_governance_runtime_fail( 'plugin lifecycle state fingerprint is invalid' );
}
if ( empty( $first['plan_sha256'] ) || ! hash_equals( $first['plan_sha256'], $second['plan_sha256'] ) ) {
	mad4b_governance_runtime_fail( 'plugin lifecycle plan is not deterministic' );
}
if ( ! empty( $first['mutation_performed'] ) || ! empty( $first['authority_created'] ) ) {
	mad4b_governance_runtime_fail( 'plugin lifecycle planner must remain non-mutating and non-authorizing' );
}

$status = MAD4B_SCP_Workflow_Providers::status();
if ( is_wp_error( $status ) || empty( $status['providers']['bitflows'] ) ) {
	mad4b_governance_runtime_fail( 'workflow provider status is unavailable' );
}
$bitflows = $status['providers']['bitflows'];
if ( ! empty( $bitflows['adapter_available'] ) ) {
	mad4b_governance_runtime_fail( 'generic disposable runtime unexpectedly has Bit Flows available' );
}
if ( 'provider_runtime_unavailable' !== $bitflows['operations']['list']['blocker'] ) {
	mad4b_governance_runtime_fail( 'unavailable workflow provider did not fail closed at operation projection' );
}

$workflow_plan = MAD4B_SCP_Workflow_Providers::plan(
	array(
		'provider' => 'bitflows',
		'operation' => 'list',
		'reason' => 'prove unavailable workflow provider is non-executable',
	)
);
if ( is_wp_error( $workflow_plan ) ) {
	mad4b_governance_runtime_fail( 'workflow plan should describe provider unavailability rather than error' );
}
if ( ! empty( $workflow_plan['execution_ready'] ) || 'provider_runtime_unavailable' !== $workflow_plan['blocker'] ) {
	mad4b_governance_runtime_fail( 'workflow plan incorrectly marked unavailable provider ready' );
}
if ( empty( $workflow_plan['plan_sha256'] ) || ! empty( $workflow_plan['mutation_performed'] ) ) {
	mad4b_governance_runtime_fail( 'workflow plan evidence contract is invalid' );
}

$operating_status = MAD4B_SCP_Operating_Model::status();
if ( is_wp_error( $operating_status ) || 'mad4b.operating-model-contracts.v1' !== (string) ( $operating_status['contract'] ?? '' ) || ! empty( $operating_status['mutation_performed'] ) || ! empty( $operating_status['authority_created'] ) ) {
	mad4b_governance_runtime_fail( 'generic operating-model status is invalid or authorizing' );
}
$diff_a = MAD4B_SCP_Operating_Model::state_diff( array( 'desired' => array( 'archive' => array( 'hero' => 'dynamic' ) ), 'observed' => array( 'archive' => array( 'hero' => 'legacy' ) ) ) );
$diff_b = MAD4B_SCP_Operating_Model::state_diff( array( 'desired' => array( 'archive' => array( 'hero' => 'dynamic' ) ), 'observed' => array( 'archive' => array( 'hero' => 'legacy' ) ) ) );
if ( is_wp_error( $diff_a ) || is_wp_error( $diff_b ) || 1 !== (int) $diff_a['change_count'] || $diff_a['desired_state_sha256'] !== $diff_b['desired_state_sha256'] ) mad4b_governance_runtime_fail( 'desired/observed state diff is not deterministic' );
$operation_plan = MAD4B_SCP_Operating_Model::operation_plan( array(
	'environment' => 'staging',
	'desired_state' => array( 'archive.hero.title' => 'dynamic' ),
	'observed_state' => array( 'archive.hero.title' => 'legacy' ),
	'operations' => array( array( 'id' => 'repair-title', 'capability' => 'elementor/set-dynamic-tag', 'provider' => 'elementor', 'target' => 'template:example', 'reversible' => true, 'mutation' => true, 'expected_state_sha256' => str_repeat( 'a', 64 ) ) ),
	'evidence_dependencies' => array( 'template_parity' ),
) );
if ( is_wp_error( $operation_plan ) || empty( $operation_plan['plan_sha256'] ) || empty( $operation_plan['non_authorizing'] ) || ! empty( $operation_plan['authority_created'] ) ) mad4b_governance_runtime_fail( 'generic operation plan contract is invalid' );
$invalidation = MAD4B_SCP_Operating_Model::evidence_invalidation_plan( array( 'changed' => array( 'template_parity' ) ) );
foreach ( array( 'template_parity', 'browser_acceptance', 'seo_publication', 'production_activation' ) as $required_invalidated ) if ( is_wp_error( $invalidation ) || ! in_array( $required_invalidated, (array) $invalidation['invalidate'], true ) ) mad4b_governance_runtime_fail( 'evidence dependency invalidation did not propagate release-gate staleness' );
$candidate_state = MAD4B_SCP_Operating_Model::candidate_state( array( 'facts' => array( 'candidate_verified' => true, 'bootstrap_preconditions_met' => true, 'candidate_bound' => false, 'write_runtime_ready' => false ) ) );
if ( is_wp_error( $candidate_state ) || 'BINDING_BOOTSTRAP_ALLOWED' !== $candidate_state['state'] || 'BOUND' !== $candidate_state['next_state'] || ! in_array( 'candidate_bound', $candidate_state['blockers'], true ) ) mad4b_governance_runtime_fail( 'candidate state machine reintroduced bootstrap/write-runtime dependency loop' );
$compiled = MAD4B_SCP_Operating_Model::workflow_compile( array(
	'workflow_id' => 'governed-release',
	'steps' => array(
		array( 'id' => 'inspect', 'capability' => 'mad4b/state-diff', 'provider' => 'mad4b', 'target' => 'site:current' ),
		array( 'id' => 'wait-gate', 'capability' => 'mad4b/candidate-state', 'provider' => 'bitflows', 'target' => 'candidate:current', 'mechanic' => 'wait', 'depends_on' => array( 'inspect' ) ),
		array( 'id' => 'mutate', 'capability' => 'elementor/set-dynamic-tag', 'provider' => 'elementor', 'target' => 'template:example', 'mutation' => true, 'reversible' => true, 'depends_on' => array( 'wait-gate' ) ),
	),
) );
if ( is_wp_error( $compiled ) || empty( $compiled['workflow_sha256'] ) || array( 'inspect', 'wait-gate', 'mutate' ) !== $compiled['execution_order'] ) mad4b_governance_runtime_fail( 'workflow compiler did not produce deterministic dependency order' );
if ( 'workflow_provider' !== $compiled['steps'][1]['executor'] || ! empty( $compiled['steps'][1]['workflow_provider_authority'] ) || 'mad4b_capability' !== $compiled['steps'][2]['mutation_executor'] ) mad4b_governance_runtime_fail( 'workflow compiler allowed workflow mechanics to become mutation authority' );
$invariants = MAD4B_SCP_Operating_Model::invariant_evaluate( array( 'facts' => array( 'provider_capability_requested' => true, 'provider_capability_known' => false ) ) );
if ( is_wp_error( $invariants ) || ! in_array( 'unknown_capability_is_unavailable', (array) $invariants['violations'], true ) ) mad4b_governance_runtime_fail( 'unknown provider capability did not fail closed through invariant evaluation' );

echo "mad4b.workflow-lifecycle-runtime.v2: PASS\n";
