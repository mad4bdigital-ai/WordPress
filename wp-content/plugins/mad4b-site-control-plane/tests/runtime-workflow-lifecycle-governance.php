<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function mad4b_governance_runtime_fail( $message ) {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

if ( ! class_exists( 'MAD4B_SCP_Plugin_Lifecycle' ) || ! class_exists( 'MAD4B_SCP_Workflow_Providers' ) ) {
	mad4b_governance_runtime_fail( 'workflow/lifecycle governance classes are unavailable' );
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

echo "mad4b.workflow-lifecycle-runtime.v1: PASS\n";
