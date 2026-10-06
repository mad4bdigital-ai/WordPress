<?php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['scenario_filter'] = null;
function apply_filters( $tag, $value, ...$args ) {
	if ( 'mad4b_scp_runtime_reconciliation_scenarios' === $tag && is_callable( $GLOBALS['scenario_filter'] ) ) {
		return call_user_func( $GLOBALS['scenario_filter'], $value, ...$args );
	}
	return $value;
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-reconciliation-scenarios.php';
function check( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }

$binding = array( 'stored_bound' => true, 'match' => false );
$base = array(
	'environment' => 'staging',
	'identity_complete' => true,
	'candidate_binding' => $binding,
	'continuation_state' => 'absent',
);

$same = MAD4B_SCP_Runtime_Reconciliation_Scenarios::classify( array_merge( $base, array(
	'signals' => array( 'candidate_binding_drift', 'same_version_identity_drift' ),
) ) );
check( 'same_version_package_replacement' === $same['scenario_id'], 'same-version replacement not classified' );
check( 'auto_evaluate_zero_delta' === $same['disposition'], 'same-version replacement should enter ZERO_DELTA evaluation' );
check( $same['zero_delta_required'], 'candidate drift must require ZERO_DELTA' );
check( ! $same['authority_mutation_allowed'] && ! $same['grant_mutation_allowed'], 'classification widened authority' );

$forward = MAD4B_SCP_Runtime_Reconciliation_Scenarios::classify( array_merge( $base, array(
	'signals' => array( 'candidate_binding_drift', 'version_forward', 'plugin_version_drift' ),
) ) );
check( 'forward_package_replacement' === $forward['scenario_id'], 'forward replacement not classified' );

$rollback = MAD4B_SCP_Runtime_Reconciliation_Scenarios::classify( array_merge( $base, array(
	'signals' => array( 'candidate_binding_drift', 'version_rollback', 'plugin_version_drift' ),
) ) );
check( 'rollback_or_reinstall' === $rollback['scenario_id'], 'rollback/reinstall not classified' );
check( 'auto_evaluate_zero_delta' === $rollback['disposition'], 'rollback must only enter ZERO_DELTA evaluation' );

$schema = MAD4B_SCP_Runtime_Reconciliation_Scenarios::classify( array_merge( $base, array(
	'candidate_binding' => array( 'stored_bound' => true, 'match' => true ),
	'signals' => array( 'schema_version_drift' ),
) ) );
check( 'auto_safe_phases' === $schema['disposition'], 'schema-only drift should use safe phases' );

$unbound = MAD4B_SCP_Runtime_Reconciliation_Scenarios::classify( array_merge( $base, array(
	'candidate_binding' => array( 'stored_bound' => false, 'match' => false ),
	'signals' => array( 'candidate_binding_drift' ),
) ) );
check( 'review_required' === $unbound['disposition'], 'unbound candidate must not auto-bind' );

$production = MAD4B_SCP_Runtime_Reconciliation_Scenarios::classify( array_merge( $base, array(
	'environment' => 'production',
	'signals' => array( 'candidate_binding_drift' ),
) ) );
check( 'hard_block' === $production['disposition'] && ! $production['production_mutation_allowed'], 'Production drift auto-promoted' );

$review_signals = array(
	'grant_inventory_drift',
	'write_contract_drift',
	'site_profile_drift',
	'actor_identity_drift',
	'transport_contract_drift',
	'baseline_expired',
);
foreach ( $review_signals as $signal ) {
	$review = MAD4B_SCP_Runtime_Reconciliation_Scenarios::classify( array_merge( $base, array(
		'signals' => array( 'candidate_binding_drift', $signal ),
	) ) );
	check( 'review_required' === $review['disposition'], $signal . ' must require review' );
	check( ! $review['authority_mutation_allowed'] && ! $review['grant_mutation_allowed'], $signal . ' widened authority' );
}

$untrusted = MAD4B_SCP_Runtime_Reconciliation_Scenarios::classify( array_merge( $base, array(
	'signals' => array( 'candidate_binding_drift', 'untrusted_package' ),
) ) );
check( 'hard_block' === $untrusted['disposition'], 'untrusted package must hard-block reconciliation' );

foreach ( array( 'continuation_conflict', 'maintenance_busy', 'concurrent_permit' ) as $signal ) {
	$deferred = MAD4B_SCP_Runtime_Reconciliation_Scenarios::classify( array_merge( $base, array(
		'signals' => array( $signal ),
	) ) );
	check( 'defer' === $deferred['disposition'], $signal . ' must defer to the active owner' );
}

$GLOBALS['scenario_filter'] = static function ( $rows ) {
	$rows['custom_future_case'] = array(
		'priority' => 999,
		'all_of' => array( 'custom_future_signal' ),
		'description' => 'Extension-provided classification only.',
		'authority_mutation_allowed' => true,
		'requested_disposition' => 'auto_evaluate_zero_delta',
	);
	return $rows;
};
$custom = MAD4B_SCP_Runtime_Reconciliation_Scenarios::classify( array_merge( $base, array(
	'candidate_binding' => array( 'stored_bound' => true, 'match' => true ),
	'signals' => array( 'custom_future_signal' ),
) ) );
check( 'custom_future_case' === $custom['scenario_id'], 'custom scenario was not discoverable' );
check( 'review_required' === $custom['disposition'], 'extension descriptor overrode central safety' );
check( ! $custom['authority_mutation_allowed'] && ! $custom['descriptor_can_override_safety'], 'extension descriptor widened authority' );

echo "runtime reconciliation scenarios: PASS\n";
