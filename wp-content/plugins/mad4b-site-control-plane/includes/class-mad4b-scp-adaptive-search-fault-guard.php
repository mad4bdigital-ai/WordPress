<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Composed fail-closed guard for Adaptive Search observation execution.
 * It does not execute providers; it reduces material runtime dependencies into
 * an explicit execution/reconciliation/degraded state.
 */
final class MAD4B_SCP_Adaptive_Search_Fault_Guard {
	const CONTRACT = 'mad4b.adaptive-search-fault-guard.v1';

	public static function evaluate( array $state ) {
		$blockers = array();
		$reconcile = array();
		$warnings = array();

		if ( empty( $state['profile_generation_match'] ) ) $blockers[] = 'profile_drift';
		if ( empty( $state['provider_generation_match'] ) ) $blockers[] = 'provider_drift';
		if ( empty( $state['surface_fingerprint_match'] ) ) $blockers[] = 'surface_drift';

		$target_type = sanitize_key( (string) ( $state['target_type'] ?? 'owned_rank_tracking' ) );
		if ( 'owned_rank_tracking' === $target_type && empty( $state['language_live'] ) ) $blockers[] = 'language_drift';

		if ( empty( $state['budget_cycle_match'] ) ) $blockers[] = 'budget_cycle_drift';
		if ( empty( $state['budget_reservation_valid'] ) ) $blockers[] = 'budget_reservation_invalid';
		if ( empty( $state['lease_valid'] ) ) $blockers[] = 'lease_lost';

		$effect = sanitize_key( (string) ( $state['provider_effect_state'] ?? 'none' ) );
		if ( 'unknown' === $effect || 'mutated_but_evidence_uncertain' === $effect ) $reconcile[] = 'provider_effect_uncertain';

		$capture_complete = ! empty( $state['capture_complete'] );
		$target_found = ! empty( $state['target_found'] );
		$loss_signal_allowed = $capture_complete && ! $target_found;
		if ( ! $capture_complete ) $warnings[] = 'capture_incomplete';

		$cache_comparable = ! empty( $state['cache_context_comparable'] );
		$cache_fresh = ! empty( $state['cache_fresh'] );
		$cache_reusable = $cache_comparable && $cache_fresh;
		if ( $cache_fresh && ! $cache_comparable ) $warnings[] = 'cache_context_incomparable';
		if ( $cache_comparable && ! $cache_fresh ) $warnings[] = 'cache_stale';

		$experience = 'ACTIVE';
		if ( ! empty( $reconcile ) ) {
			$experience = 'RECONCILIATION_REQUIRED';
		} elseif ( in_array( 'profile_drift', $blockers, true ) || in_array( 'provider_drift', $blockers, true ) || in_array( 'surface_drift', $blockers, true ) || in_array( 'language_drift', $blockers, true ) ) {
			$experience = 'PROFILE_DRIFT';
		} elseif ( in_array( 'budget_cycle_drift', $blockers, true ) || in_array( 'budget_reservation_invalid', $blockers, true ) ) {
			$experience = 'DEGRADED_BUDGET';
		} elseif ( ! $cache_fresh && ! $capture_complete ) {
			$experience = 'EVIDENCE_STALE';
		}

		return array(
			'contract' => self::CONTRACT,
			'execution_allowed' => empty( $blockers ) && empty( $reconcile ),
			'provider_retry_allowed' => empty( $reconcile ),
			'loss_signal_allowed' => $loss_signal_allowed,
			'cache_reusable' => $cache_reusable,
			'experience_state' => $experience,
			'blockers' => array_values( array_unique( $blockers ) ),
			'reconciliation_reasons' => array_values( array_unique( $reconcile ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
			'authorizing' => false,
		);
	}
}
