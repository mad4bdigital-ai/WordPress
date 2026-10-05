<?php

define( 'ABSPATH', __DIR__ );
$repo = dirname( __DIR__, 4 );

function mad4b_release_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "ASSERTION_FAILED: " . $message . PHP_EOL ); exit( 1 ); }
}
function mad4b_release_json( $path ) {
	$value = json_decode( file_get_contents( $path ), true );
	mad4b_release_assert( is_array( $value ), 'invalid json: ' . $path );
	return $value;
}
function mad4b_release_counts( array $rows ) {
	$out = array( 'total' => 0, 'DONE' => 0, 'PARTIAL' => 0, 'OPEN' => 0, 'DEFERRED' => 0 );
	foreach ( $rows as $row ) { $out['total']++; $s = isset( $row['status'] ) ? (string) $row['status'] : ''; if ( isset( $out[ $s ] ) ) $out[ $s ]++; }
	return $out;
}
function mad4b_release_count_equal( array $expected, array $actual, $label ) {
	foreach ( array( 'total', 'DONE', 'PARTIAL', 'OPEN', 'DEFERRED' ) as $key ) mad4b_release_assert( (int) $expected[ $key ] === (int) $actual[ $key ], $label . ':' . $key );
}

$feature = mad4b_release_json( $repo . '/specs/007-content-intelligence-workflow-platform/feature.json' );
$freeze = isset( $feature['release_closure_scope_freeze'] ) ? $feature['release_closure_scope_freeze'] : array();
mad4b_release_assert( 'FROZEN_FOR_RELEASE_CLOSURE' === ( isset( $freeze['state'] ) ? $freeze['state'] : '' ), 'scope freeze state' );
mad4b_release_assert( 39 === (int) $freeze['required_phase_count'] && 38 === (int) $freeze['max_phase'], 'phase freeze' );
mad4b_release_assert( false === $freeze['new_required_phases_allowed'] && false === $freeze['new_capability_families_allowed'], 'scope growth denied' );
mad4b_release_assert( true === $freeze['future_capability_work_requires_new_pr'], 'future capability new PR' );
mad4b_release_assert( false === $freeze['task_ledger_ratio_is_readiness_metric'], 'ledger ratio not readiness' );
mad4b_release_assert( 837 === (int) $freeze['frozen_task_count'], 'frozen task count' );
mad4b_release_assert( 34 === (int) $freeze['frozen_workstream_count'], 'frozen workstream count' );
mad4b_release_assert( 80 === (int) $freeze['frozen_phase38_task_count'], 'frozen phase38 task count' );
mad4b_release_assert( 35 === (int) $freeze['max_change_slice_files'], 'slice budget frozen' );

$external = mad4b_release_json( $repo . '/wp-content/plugins/mad4b-site-control-plane/config/external-machine-diagnostic-policy.json' );
mad4b_release_assert( false === $external['authorizing'] && 'none' === $external['bypass_mode'], 'diagnostic non-authorizing no-bypass' );
mad4b_release_assert( false === $external['public_widening_allowed'] && false === $external['origin_direct_bypass_allowed'], 'no access widening' );
mad4b_release_assert( true === $external['machine_identity_required'], 'machine identity required' );
mad4b_release_assert( 'INCONCLUSIVE_FAIL_CLOSED' === $external['acceptance']['edge_challenge_403'], '403 fails closed' );
mad4b_release_assert( 8 === count( $external['endpoint_classes'] ), 'bounded endpoint classes' );

$policy = mad4b_release_json( $repo . '/wp-content/plugins/mad4b-site-control-plane/config/production-readiness-policy.json' );
$closure = mad4b_release_json( $repo . '/specs/007-content-intelligence-workflow-platform/implementation-closure.json' );
$ledger = mad4b_release_json( $repo . '/specs/007-content-intelligence-workflow-platform/task-ledger.generated.json' );
$projection = mad4b_release_json( $repo . '/specs/007-content-intelligence-workflow-platform/release-closure-readiness.json' );
mad4b_release_assert( false === $projection['semantics']['task_ledger_ratio_is_readiness_metric'], 'profile readiness semantics' );
mad4b_release_assert( false === $projection['production_authorized'], 'projection non-authorizing' );

$tasks = isset( $ledger['tasks'] ) && is_array( $ledger['tasks'] ) ? $ledger['tasks'] : array();
$phase38 = array_values( array_filter( $tasks, function( $row ) { return 38 === (int) $row['phase']; } ) );
mad4b_release_count_equal( $projection['task_ledger'], mad4b_release_counts( $tasks ), 'task ledger' );
mad4b_release_count_equal( $projection['phase_38_adaptive_search'], mad4b_release_counts( $phase38 ), 'phase38' );
mad4b_release_assert( 80 === count( $phase38 ) && 80 === mad4b_release_counts( $phase38 )['DONE'], 'phase38 80/80' );

$workstreams = isset( $closure['workstreams'] ) && is_array( $closure['workstreams'] ) ? $closure['workstreams'] : array();
mad4b_release_assert( (int) $freeze['frozen_task_count'] === count( $tasks ), 'task count remains frozen' );
mad4b_release_assert( (int) $freeze['frozen_workstream_count'] === count( $workstreams ), 'workstream count remains frozen' );
mad4b_release_assert( (int) $freeze['frozen_phase38_task_count'] === count( $phase38 ), 'phase38 count remains frozen' );
$core_ids = array_flip( $policy['profiles']['control_plane_core']['required_workstream_ids'] );
$optional_ids = array_flip( $policy['profiles']['control_plane_core']['optional_workstream_ids'] );
$blocking_priorities = array_flip( $policy['required_blocking_priorities'] );
$maturity_priorities = array_flip( $policy['non_blocking_maturity_priorities'] );
$core = $optional = $blocking = $maturity = array();
foreach ( $workstreams as $row ) {
	if ( isset( $core_ids[ $row['id'] ] ) ) $core[] = $row;
	if ( isset( $optional_ids[ $row['id'] ] ) ) $optional[] = $row;
	if ( isset( $blocking_priorities[ $row['priority'] ] ) ) $blocking[] = $row;
	if ( isset( $maturity_priorities[ $row['priority'] ] ) ) $maturity[] = $row;
}
mad4b_release_count_equal( $projection['profiles']['control_plane_core_required']['counts'], mad4b_release_counts( $core ), 'core' );
mad4b_release_count_equal( $projection['profiles']['control_plane_core_optional_fail_closed']['counts'], mad4b_release_counts( $optional ), 'optional' );
mad4b_release_count_equal( $projection['profiles']['full_feature007_blocking']['counts'], mad4b_release_counts( $blocking ), 'full feature' );
mad4b_release_count_equal( $projection['profiles']['long_term_maturity']['counts'], mad4b_release_counts( $maturity ), 'maturity' );

$golden = file_get_contents( $repo . '/wp-content/plugins/mad4b-site-control-plane/docs/CAPABILITY-GOLDEN-PATH.md' );
foreach ( array( 'Define', 'Register', 'Certify', 'Plan', 'Execute', 'Evidence', 'Reconcile' ) as $stage ) mad4b_release_assert( false !== strpos( $golden, $stage ), 'golden stage ' . $stage );

require_once $repo . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-control-center.php';
$base = array( 'repository_green'=>true,'runtime_identity_match'=>true,'live_evidence_ready'=>true,'optional_capabilities_fail_closed'=>true,'external_machine_ingress_reachable'=>true,'mutation_uncertain'=>false,'recovery_required'=>false );
mad4b_release_assert( 'HEALTHY' === MAD4B_SCP_Operator_Control_Center::reduce( $base )['state'], 'healthy' );
$d = $base; $d['live_evidence_ready'] = false; mad4b_release_assert( 'DEGRADED' === MAD4B_SCP_Operator_Control_Center::reduce( $d )['state'], 'degraded' );
$b = $base; $b['runtime_identity_match'] = false; mad4b_release_assert( 'BLOCKED' === MAD4B_SCP_Operator_Control_Center::reduce( $b )['state'], 'blocked' );
$r = $base; $r['mutation_uncertain'] = true; mad4b_release_assert( 'RECOVERY_REQUIRED' === MAD4B_SCP_Operator_Control_Center::reduce( $r )['state'], 'recovery' );

echo "mad4b.feature007-release-closure-hardening.v1: PASS" . PHP_EOL;
