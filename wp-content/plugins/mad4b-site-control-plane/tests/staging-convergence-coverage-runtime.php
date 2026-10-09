<?php
/**
 * Disposable, no-WordPress runtime regression fixture for the pure convergence
 * reducer. No DB, network, host process, WordPress writes or release claims.
 */
define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-certification.php';

$checks = 0;
$check = static function ( $passed, $label ) use ( &$checks ) {
	++$checks;
	if ( ! $passed ) {
		fwrite( STDERR, 'FAILED [' . $checks . ']: ' . $label . PHP_EOL );
		exit( 1 );
	}
};
$gates = array();
$names = array(
	'exact_build', 'safe_boot', 'context_authority',
	'brand_core_context_coverage', 'google_provider_connection',
	'managed_google_broker', 'skills_runtime', 'external_skill_snapshot',
	'write_authority', 'write_runtime', 'browser_runtime', 'performance_budget',
	'admin_query_performance', 'query_monitor_db_attribution',
	'oauth_live_authority_projection', 'rollback_candidate',
	'wp_import_export_exact_artifact', 'new_plugin_family_gate',
);
foreach ( $names as $name ) {
	$gates[ $name ] = array(
		'ready' => false,
		'source' => 'site_live_' . $name,
		'remediation_owner' => 'host_operator',
		'blockers' => array( 'certificate_not_observed' ),
	);
}
// The MCP handshake is already ready; an external snapshot still needs a
// *fresh* handshake action as a prerequisite, not a dangling dependency.
$gates['safe_boot']['ready'] = true;
$actions = array(
	array( 'action_id' => 'external_snapshot_refresh', 'kind' => 'external_evidence',
		'depends_on' => array( 'external_mcp_handshake_refresh' ),
		'automatic_execution_allowed' => false ),
	array( 'action_id' => 'browser_acceptance', 'kind' => 'external_executor_job',
		'automatic_execution_allowed' => true ),
	array( 'action_id' => 'frontend_performance_sampling', 'kind' => 'external_executor_job',
		'automatic_execution_allowed' => true ),
	array( 'action_id' => 'brand_core_convergence', 'kind' => 'hybrid_creation',
		'depends_on' => array(), 'automatic_execution_allowed' => false ),
	array( 'action_id' => 'write_authority_reconcile', 'kind' => 'governed_mutation',
		'automatic_execution_allowed' => false ),
	array( 'action_id' => 'provider_closure_review', 'kind' => 'read_only_followup',
		'automatic_execution_allowed' => true ),
);
$c = MAD4B_SCP_Staging_Certification::complete_convergence_coverage( $gates, $actions );
$check( $c['contract'] === 'mad4b.staging-gate-action-coverage.v1', 'contract identity' );
$check( $c['blocked_gate_count'] === count( $names ) - 1, 'ready gates excluded' );
$check( $c['covered_gate_count'] === count( $names ) - 1, 'all blocked gates covered' );
$check( $c['coverage_complete'] === true, 'coverage exact and non-authorizing' );
$check( $c['plan_integrity_blockers'] === array(), 'no dangling dependencies' );
$check( $c['authorizing'] === false && $c['mutation_performed'] === false, 'pure planning only' );
$check( ! isset( $c['gate_action_coverage']['safe_boot'] ), 'ready site handshake not falsely blocked' );
$check( count( $c['gate_action_coverage']['new_plugin_family_gate'] ) === 1,
	'future unknown gate has a safe review-only remediation' );
$by_id = array();
foreach ( $c['actions'] as $i => $action ) {
	$by_id[ $action['action_id'] ] = array( 'action' => $action, 'index' => $i );
	$check( $action['authorizing'] === false && $action['mutation_performed'] === false,
		'all actions non-authorizing regardless of source flags' );
}
$check( isset( $by_id['external_mcp_handshake_refresh'] ), 'fresh prerequisite generated' );
$check( $by_id['external_mcp_handshake_refresh']['index'] <
	$by_id['external_snapshot_refresh']['index'], 'external snapshot ordered after valid handshake' );
foreach ( array( 'browser_acceptance', 'frontend_performance_sampling' ) as $id ) {
	$row = $by_id[ $id ]['action'];
	$check( $row['automatic_execution_allowed'] === false, 'external executor cannot self-launch: ' . $id );
	$check( $row['external_preflight_required'] === true &&
		in_array( 'signed_replay_safe_receipt', $row['required_evidence'], true ),
		'external signed receipt demanded: ' . $id );
}
$unknown = $by_id['review_gate_new_plugin_family_gate']['action'];
$check( $unknown['automatic_execution_allowed'] === false &&
	$unknown['no_automatic_remediation_available'] === true,
	'unknown provider lane stays fail-closed' );
$check( $unknown['evidence_source'] === 'site_live_new_plugin_family_gate' &&
	$unknown['executor'] === 'host_operator', 'site-derived evidence and owner' );
$check( $by_id['provider_closure_review']['action']['read_only_plan'] === true,
	'existing native follow-up remains read-only' );
$reversed = MAD4B_SCP_Staging_Certification::complete_convergence_coverage(
	$gates, array_reverse( $actions )
);
$check( $reversed['actions'] === $c['actions'], 'deterministic ordering independent of input enumeration' );
$cycle = MAD4B_SCP_Staging_Certification::complete_convergence_coverage( array(), array(
	array( 'action_id' => 'alpha', 'depends_on' => array( 'beta' ), 'automatic_execution_allowed' => true ),
	array( 'action_id' => 'beta', 'depends_on' => array( 'alpha' ), 'automatic_execution_allowed' => true ),
) );
$check( $cycle['coverage_complete'] === false &&
	! empty( $cycle['plan_integrity_blockers'] ), 'cyclic remediation graph denied' );
foreach ( $cycle['actions'] as $item )
	$check( $item['automatic_execution_allowed'] === false, 'cyclic graph never executes' );
$missing = MAD4B_SCP_Staging_Certification::complete_convergence_coverage( array(), array(
	array( 'action_id' => 'alpha', 'depends_on' => array( 'missing_host_provider' ),
		'automatic_execution_allowed' => true ),
) );
$check( $missing['coverage_complete'] === false &&
	! empty( $missing['plan_integrity_blockers'] ), 'unresolvable provider prerequisite denied' );
$check( $missing['actions'][0]['automatic_execution_allowed'] === false,
	'missing host executor does not become automatic' );
$duplicate = MAD4B_SCP_Staging_Certification::complete_convergence_coverage( array(), array(
	array( 'action_id' => 'alpha', 'automatic_execution_allowed' => true ),
	array( 'action_id' => 'alpha', 'automatic_execution_allowed' => true ),
) );
$check( ! empty( $duplicate['plan_integrity_blockers'] ) &&
	$duplicate['actions'][0]['automatic_execution_allowed'] === false,
	'duplicate action identity denied' );
$empty = MAD4B_SCP_Staging_Certification::complete_convergence_coverage( array(), array() );
$check( $empty['coverage_complete'] === true &&
	$empty['blocked_gate_count'] === 0 &&
	$empty['actions'] === array(), 'empty ready site yields clean no-op plan' );

// Independent release acceptance is opt-in and must never be filled from
// Staging-only certificate booleans.
$release = MAD4B_SCP_Staging_Certification::merge_live_acceptance_gates(
	array( 'safe_boot' => array( 'ready' => true ) ),
	array( 'ready' => false, 'gates' => array(
		'environment_guard' => array( 'ready' => true, 'effective_ready' => true,
			'freshness_required' => true, 'fresh' => true ),
		'external_wpml' => array( 'ready' => false, 'effective_ready' => false,
			'state' => 'route_not_registered', 'source_contract' => 'external-wpml',
			'blockers' => array( 'route_not_registered' ) ),
		'browser_attestation' => array( 'ready' => true, 'effective_ready' => true,
			'freshness_required' => true, 'fresh' => false ),
	) )
);
$check( $release['included'] === true && $release['ready'] === false,
	'live acceptance cannot be self-certified' );
$check( $release['gate_count'] === 3, 'independent acceptance families preserved' );
$check( $release['gates']['live_acceptance_environment_guard']['ready'] === true,
	'fresh external success remains success' );
$check( $release['gates']['live_acceptance_external_wpml']['ready'] === false,
	'missing external WPML remains blocked' );
$check( $release['gates']['live_acceptance_browser_attestation']['ready'] === false,
	'stale signed browser evidence never becomes ready' );
$expanded = MAD4B_SCP_Staging_Certification::complete_convergence_coverage(
	$release['gates'], array()
);
$check( $expanded['coverage_complete'] === true &&
	isset( $expanded['gate_action_coverage']['live_acceptance_external_wpml'] ),
	'live acceptance blocker gets safe generic review' );
$check( $expanded['actions'][0]['authorizing'] === false,
	'live acceptance gate cannot mint authority' );
$empty_external = MAD4B_SCP_Staging_Certification::merge_live_acceptance_gates(
	array(), array( 'ready' => true, 'gates' => array() )
);
$check( $empty_external['ready'] === false &&
	isset( $empty_external['gates']['live_acceptance_evidence_unavailable'] ),
	'absent independent evidence cannot be accepted as ready' );
$invalid = MAD4B_SCP_Staging_Certification::complete_convergence_coverage(
	array( 'bad/name' => array( 'ready' => false ) ), array()
);
$check( $invalid['coverage_complete'] === false &&
	! empty( $invalid['plan_integrity_blockers'] ),
	'invalid gate identity cannot silently disappear' );
$mutating = MAD4B_SCP_Staging_Certification::complete_convergence_coverage(
	array(), array(
		array( 'action_id' => 'governed_write', 'kind' => 'governed_mutation',
			'automatic_execution_allowed' => true ),
		array( 'action_id' => 'external_reconnect', 'kind' => 'external_oauth_reauthorization',
			'automatic_execution_allowed' => true ),
	)
);
foreach ( $mutating['actions'] as $action ) {
	$check( $action['automatic_execution_allowed'] === false &&
		$action['independent_governed_preflight_required'] === true,
		'no effectful action may inherit automatic authority' );
}
echo 'STAGING_CONVERGENCE_COVERAGE_RUNTIME: PASS ' . $checks . PHP_EOL;
