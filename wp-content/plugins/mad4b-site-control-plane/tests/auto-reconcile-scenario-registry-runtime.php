<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}
$GLOBALS['mad4b_auto_reconcile_filter'] = null;
$GLOBALS['mad4b_auto_reconcile_signal_filter'] = null;
$GLOBALS['mad4b_auto_reconcile_worker_filter'] = null;
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		if ( 'mad4b_scp_auto_reconcile_scenarios' === $tag && is_callable( $GLOBALS['mad4b_auto_reconcile_filter'] ) ) return call_user_func( $GLOBALS['mad4b_auto_reconcile_filter'], $value );
		if ( 'mad4b_scp_auto_reconcile_signals' === $tag && is_callable( $GLOBALS['mad4b_auto_reconcile_signal_filter'] ) ) return call_user_func( $GLOBALS['mad4b_auto_reconcile_signal_filter'], $value, $args[0] ?? array() );
		if ( 'mad4b_scp_auto_reconcile_worker_error_policies' === $tag && is_callable( $GLOBALS['mad4b_auto_reconcile_worker_filter'] ) ) return call_user_func( $GLOBALS['mad4b_auto_reconcile_worker_filter'], $value );
		return $value;
	}
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-auto-reconcile-scenarios.php';

function check( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, $message . PHP_EOL ); exit( 1 ); }
}

$base = array(
	'environment' => 'staging',
	'runtime_identity_complete' => true,
	'candidate_binding' => array( 'required' => true, 'stored_bound' => true, 'match' => false ),
	'continuation' => array( 'active' => false, 'state' => '' ),
	'maintenance' => array( 'active' => false ),
	'skills_pending' => false,
	'build_changed' => true,
	'current_version' => '0.4.0-rc.96',
	'stored_version' => '0.4.0-rc.95',
	'source' => 'wordpress_upgrader',
	'breakglass_enabled' => false,
);
$self_owned = $base;
$self_owned['maintenance'] = array( 'active' => true, 'owner' => 'adaptive_runtime_observation' );
check( 'SCHEDULE_PROBE' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $self_owned )['decision'], 'observer-owned maintenance lease must not self-defer' );

$foreign_owned = $base;
$foreign_owned['maintenance'] = array( 'active' => true, 'owner' => 'runtime_convergence' );
check( 'DEFER' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $foreign_owned )['decision'], 'foreign maintenance owner must defer' );

$manual = MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $base );
check( 'forward_package_update' === $manual['scenario_id'], 'forward package update must classify explicitly' );
check( 'SCHEDULE_PROBE' === $manual['decision'], 'manual ZIP drift must schedule a bounded probe' );
check( empty( $manual['mutation_allowed'] ) && empty( $manual['authority_expansion_allowed'] ), 'probe must never be authorizing' );
check( ! empty( $manual['zero_delta_required_for_rebind'] ), 'rebind must remain ZERO_DELTA only' );

$same = $base; $same['stored_version'] = $same['current_version'];
$same_result = MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $same );
check( 'same_version_package_replacement' === $same_result['scenario_id'], 'same-version replacement must classify explicitly' );
check( 'SCHEDULE_PROBE' === $same_result['decision'], 'same-version replacement must only schedule a probe' );

$rollback = $base; $rollback['current_version'] = '0.4.0-rc.94'; $rollback['stored_version'] = '0.4.0-rc.95';
$rollback_result = MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $rollback );
check( 'rollback_or_reinstall' === $rollback_result['scenario_id'], 'rollback/reinstall must classify explicitly' );
check( 'SCHEDULE_PROBE' === $rollback_result['decision'], 'rollback/reinstall must remain ZERO_DELTA-probe only' );

$fallback = $base;
$fallback['current_version'] = '';
$fallback['stored_version'] = '';
$fallback['source'] = 'candidate_binding_probe';
$fallback_result = MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $fallback );
check( 'SCHEDULE_PROBE' === $fallback_result['decision'], 'candidate-binding fallback must schedule only a ZERO_DELTA probe when versions are not comparable' );
check( empty( $fallback_result['mutation_allowed'] ) && ! empty( $fallback_result['zero_delta_required_for_rebind'] ), 'candidate-binding fallback widened authority' );

foreach ( array( 'grant_inventory_drift', 'write_contract_drift', 'site_profile_drift', 'actor_identity_drift', 'transport_contract_drift', 'baseline_expired' ) as $signal ) {
	$authority_drift = $base;
	$authority_drift['signals'] = array( $signal => true );
	$result = MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $authority_drift );
	check( 'REVIEW_REQUIRED' === $result['decision'], $signal . ' must require review' );
	check( empty( $result['mutation_allowed'] ) && empty( $result['authority_expansion_allowed'] ), $signal . ' widened authority' );
}

$untrusted = $base; $untrusted['signals'] = array( 'untrusted_package' => true );
check( 'HARD_BLOCK' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $untrusted )['decision'], 'untrusted package must hard block' );

foreach ( array( 'continuation_conflict', 'concurrent_permit' ) as $signal ) {
	$concurrent = $base;
	$concurrent['signals'] = array( $signal => true );
	check( 'DEFER' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $concurrent )['decision'], $signal . ' must defer to active owner' );
}

$production = $base; $production['environment'] = 'production';
check( 'HARD_BLOCK' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $production )['decision'], 'Production must hard block' );

$breakglass = $base; $breakglass['breakglass_enabled'] = true;
check( 'HARD_BLOCK' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $breakglass )['decision'], 'Breakglass must hard block auto reconcile' );

$skills = $base; $skills['skills_pending'] = true;
check( 'SCHEDULE_PROBE' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $skills )['decision'], 'pending Skills must schedule the worker that reconciles the dependency before rebind' );

$owner = $base; $owner['continuation'] = array( 'active' => true, 'state' => 'owner_gate' );
check( 'REVIEW_REQUIRED' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $owner )['decision'], 'owner gate must require review' );

$match = $base; $match['candidate_binding']['match'] = true; $match['candidate_binding']['stored_bound'] = true; $match['build_changed'] = false; $match['source'] = 'runtime_change';
check( 'NO_OP' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $match )['decision'], 'already converged state must no-op' );

$fresh = $base;
$fresh['candidate_binding'] = array( 'required' => true, 'stored_bound' => false, 'match' => false );
check( 'SCHEDULE_PROBE' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $fresh )['decision'], 'fresh bootstrap must retain safe runtime convergence' );

$GLOBALS['mad4b_auto_reconcile_filter'] = static function ( $rows ) {
	// Extensions are additions only; returning an empty/hostile set cannot remove core guards.
	return array( array(
		'id' => 'extension_noop_override',
		'priority' => 1000,
		'signals_all' => array( 'environment_production' ),
		'decision' => 'NO_OP',
	) );
};
check( 'HARD_BLOCK' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $production )['decision'], 'extension cannot override Production hard block' );
$GLOBALS['mad4b_auto_reconcile_filter'] = static function ( $rows ) {
	return array( array(
		'id' => 'production_never_auto',
		'priority' => 1000,
		'signals_all' => array( 'environment_production' ),
		'decision' => 'NO_OP',
	) );
};
check( 'HARD_BLOCK' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $production )['decision'], 'extension cannot shadow immutable core scenario id' );

$GLOBALS['mad4b_auto_reconcile_filter'] = null;


$GLOBALS['mad4b_auto_reconcile_filter'] = static function ( $rows ) {
	array_unshift( $rows, array(
		'id' => 'extension_attempts_unsafe_mutation',
		'priority' => 999,
		'signals_all' => array( 'custom_future_signal' ),
		'decision' => 'AUTO_MUTATE',
		'mutation_allowed' => true,
		'authority_expansion_allowed' => true,
	) );
	return $rows;
};
$future = $base;
$future['signals'] = array( 'custom_future_signal' => true );
$future_result = MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $future );
check( 'REVIEW_REQUIRED' === $future_result['decision'], 'unknown extension decision must collapse to review' );
check( empty( $future_result['mutation_allowed'] ) && empty( $future_result['authority_expansion_allowed'] ), 'extension cannot relax central safety' );

check( 'HARD_BLOCK' === MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error( 'mad4b_post_update_continuation_release_untrusted' )['decision'], 'untrusted release must hard block' );
check( 'DEFER' === MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error( 'mad4b_post_update_continuation_skills_certification_required' )['decision'], 'skills dependency must defer' );
check( 'REVIEW_REQUIRED' === MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error( 'mad4b_observed_update_authority_delta' )['decision'], 'authority delta must require review' );
check( 'DEFER' === MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error( 'mad4b_runtime_convergence_busy' )['decision'], 'runtime maintenance contention must dynamically defer' );
check( 'DEFER' === MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error( 'mad4b_runtime_convergence_skills_persisted_identity_stale' )['decision'], 'current-build Skill certification lag must dynamically defer' );
check( 'DEFER' === MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error( 'installed_package_manifest_unverified' )['decision'], 'package readback race must remain bounded-defer rather than blind retry' );
check( 'DEFER' === MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error( 'runtime_observation_failed' )['decision'], 'observer transport/runtime failure must remain bounded-defer' );

// Future signal providers can add evidence without overriding core safety signals.
$GLOBALS['mad4b_auto_reconcile_filter'] = static function ( $rows ) {
	return array( array( 'id' => 'extension_future_dependency', 'priority' => 840, 'signals_all' => array( 'future_dependency_ready' ), 'decision' => 'SCHEDULE_PROBE' ) );
};
$GLOBALS['mad4b_auto_reconcile_signal_filter'] = static function ( $signals, $context ) {
	$signals['future_dependency_ready'] = true;
	$signals['environment_production'] = false;
	$signals['breakglass_enabled'] = false;
	check( isset( $context['candidate_binding']['required'] ), 'bounded extension context missing candidate binding summary' );
	return $signals;
};
$extension_context = $match;
$extension_context['environment'] = 'staging';
$extension_context['breakglass_enabled'] = false;
$extension_result = MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $extension_context );
check( 'extension_future_dependency' === $extension_result['scenario_id'], 'dynamic signal provider must activate additive scenario' );
check( 'SCHEDULE_PROBE' === $extension_result['decision'], 'dynamic extension scenario must remain probe-only' );
check( 'HARD_BLOCK' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $production )['decision'], 'extension signal provider cannot erase Production hard block' );
check( 'HARD_BLOCK' === MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate( $breakglass )['decision'], 'extension signal provider cannot erase Breakglass hard block' );

$GLOBALS['mad4b_auto_reconcile_filter'] = null;
$GLOBALS['mad4b_auto_reconcile_signal_filter'] = null;
$GLOBALS['mad4b_auto_reconcile_worker_filter'] = static function ( $rows ) {
	return array(
		array( 'id' => 'future_dependency_wait', 'error_codes' => array( 'mad4b_future_dependency_wait' ), 'decision' => 'DEFER' ),
		array( 'id' => 'hostile_core_downgrade', 'error_codes' => array( 'mad4b_post_update_continuation_release_untrusted' ), 'decision' => 'NO_OP' ),
		array( 'id' => 'unsafe_unknown_decision', 'error_codes' => array( 'mad4b_future_unsafe' ), 'decision' => 'AUTO_MUTATE' )
	);
};
$future_worker = MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error( 'mad4b_future_dependency_wait' );
check( 'DEFER' === $future_worker['decision'] && 'extension' === $future_worker['policy_source'], 'future worker error policy must be dynamically classifiable' );
$core_worker = MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error( 'mad4b_post_update_continuation_release_untrusted' );
check( 'HARD_BLOCK' === $core_worker['decision'] && 'core' === $core_worker['policy_source'], 'extension worker policy cannot downgrade core hard block' );
$unsafe_worker = MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error( 'mad4b_future_unsafe' );
check( 'REVIEW_REQUIRED' === $unsafe_worker['decision'], 'unsafe extension worker decision must collapse to review' );
$GLOBALS['mad4b_auto_reconcile_worker_filter'] = null;

echo "AUTO_RECONCILE_SCENARIO_REGISTRY_RUNTIME: PASS\n";
