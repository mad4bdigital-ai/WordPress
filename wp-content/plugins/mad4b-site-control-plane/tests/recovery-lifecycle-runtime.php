<?php
/** Native PHP 7.4+ fail-closed lifecycle contract checks, no WordPress required. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-recovery-lifecycle.php';

function mad4b_recovery_assert( $pass, $message ) {
	if ( ! $pass ) { fwrite( STDERR, "FAIL: " . $message . "\n" ); exit( 1 ); }
}
function mad4b_recovery_identity() {
	return array(
		'site_uuid' => 'f1e1c39e-1e18-4e88-b113-0f0cd0a1a010',
		'profile_digest' => str_repeat( 'a', 64 ),
		'profile_revision' => 3,
		'origin' => 'https://stage.example.test',
		'environment' => 'staging',
		'authority_ready' => true,
		'source_commit_sha' => str_repeat( 'b', 40 ),
		'build_fingerprint' => str_repeat( 'c', 64 ),
		'package_manifest_digest' => str_repeat( 'd', 64 ),
		'boot_provenance_sha256' => str_repeat( 'e', 64 ),
		'provider_event_generation_sha256' => str_repeat( 'f', 64 ),
	);
}
function mad4b_recovery_plan( $actions = array() ) {
	return array( 'contract' => 'mad4b.staging-convergence-plan.v1', 'read_only' => true,
		'mutation_performed' => false, 'production_mutation_performed' => false,
		'actions' => $actions, 'blocking_gates' => array( 'skills_runtime' ), 'current_ready' => false );
}
function mad4b_recovery_action( $id, $deps = array() ) {
	return array( 'action_id' => $id, 'kind' => 'bounded_native_convergence',
		'executor' => 'existing_runtime_convergence_worker', 'depends_on' => $deps,
		'readback_ability' => 'mad4b/skill-runtime-certification',
		'automatic_execution_allowed' => false, 'human_decision_required' => false );
}
$id = mad4b_recovery_identity();
$plan = mad4b_recovery_plan( array( mad4b_recovery_action( 'phase_b', array( 'phase_a' ) ), mad4b_recovery_action( 'phase_a' ) ) );
$good = MAD4B_SCP_Recovery_Lifecycle::compile( $plan, $id, $id );
mad4b_recovery_assert( $good['identity_bound'] && 2 === $good['action_count'], 'valid site identity and two actions' );
mad4b_recovery_assert( 'phase_a' === $good['ordered_actions'][0]['id'] && 'phase_b' === $good['ordered_actions'][1]['id'], 'topological dependency order' );
mad4b_recovery_assert( ! $good['authorizing'] && ! $good['execution_performed'] && ! $good['certification_issued'], 'never converts plan to authority or receipt' );
$changed = $id; $changed['build_fingerprint'] = str_repeat( 'f', 64 );
$changed_provider = $id; $changed_provider['provider_event_generation_sha256'] = str_repeat( '1', 64 );
mad4b_recovery_assert( 'STALE' === MAD4B_SCP_Recovery_Lifecycle::compile( $plan, $id, $changed_provider )['state'], 'provider changed without MAD4B build change' );
mad4b_recovery_assert( 'STALE' === MAD4B_SCP_Recovery_Lifecycle::compile( $plan, $id, $changed )['state'], 'race between plan captures' );
$production = $id; $production['environment'] = 'production';
mad4b_recovery_assert( 'BLOCKED' === MAD4B_SCP_Recovery_Lifecycle::compile( $plan, $production, $production )['state'], 'production never admitted' );
$misbound = $id; $misbound['authority_ready'] = false;
mad4b_recovery_assert( 'BLOCKED' === MAD4B_SCP_Recovery_Lifecycle::compile( $plan, $misbound, $misbound )['state'], 'foreign site never admitted' );
$missing = mad4b_recovery_plan( array( mad4b_recovery_action( 'phase_a', array( 'unknown' ) ) ) );
mad4b_recovery_assert( 'BLOCKED' === MAD4B_SCP_Recovery_Lifecycle::compile( $missing, $id, $id )['state'], 'missing dependency' );
$cycle = mad4b_recovery_plan( array( mad4b_recovery_action( 'phase_a', array( 'phase_b' ) ), mad4b_recovery_action( 'phase_b', array( 'phase_a' ) ) ) );
mad4b_recovery_assert( 'BLOCKED' === MAD4B_SCP_Recovery_Lifecycle::compile( $cycle, $id, $id )['state'], 'dependency cycle' );
$duplicate = mad4b_recovery_plan( array( mad4b_recovery_action( 'phase_a' ), mad4b_recovery_action( 'phase_a' ) ) );
mad4b_recovery_assert( 'BLOCKED' === MAD4B_SCP_Recovery_Lifecycle::compile( $duplicate, $id, $id )['state'], 'duplicate action id' );
$oversize = mad4b_recovery_plan( array_fill( 0, 65, mad4b_recovery_action( 'phase_a' ) ) );
mad4b_recovery_assert( 'BLOCKED' === MAD4B_SCP_Recovery_Lifecycle::compile( $oversize, $id, $id )['state'], 'oversized plan' );
$mutated = $plan; $mutated['mutation_performed'] = true;
mad4b_recovery_assert( 'BLOCKED' === MAD4B_SCP_Recovery_Lifecycle::compile( $mutated, $id, $id )['state'], 'mutating source plan refused' );
$empty_ready = mad4b_recovery_plan(); $empty_ready['blocking_gates'] = array(); $empty_ready['current_ready'] = true;
mad4b_recovery_assert( 'OBSERVED_READY' === MAD4B_SCP_Recovery_Lifecycle::compile( $empty_ready, $id, $id )['state'], 'ready requires no gates or actions' );
fwrite( STDOUT, "PASS: lifecycle identity/DAG/denial invariants (12 scenarios)\n" );
