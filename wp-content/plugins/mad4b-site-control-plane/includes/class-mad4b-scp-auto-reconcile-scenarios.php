<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Declarative, fail-closed scenario registry for bounded Staging auto-reconciliation.
 *
 * This registry never mutates authority. It can only decide whether the existing
 * Runtime Convergence worker should inspect a state. Candidate binding mutation
 * remains exclusively owned by Post_Update_Continuation after exact ZERO_DELTA
 * proof. Extensions may add scenarios, but cannot opt into authority expansion.
 */
final class MAD4B_SCP_Auto_Reconcile_Scenarios {
	const CONTRACT = 'mad4b.auto-reconcile-scenarios.v1';
	const DECISION_NO_OP = 'NO_OP';
	const DECISION_SCHEDULE_PROBE = 'SCHEDULE_PROBE';
	const DECISION_DEFER = 'DEFER';
	const DECISION_REVIEW = 'REVIEW_REQUIRED';
	const DECISION_HARD_BLOCK = 'HARD_BLOCK';

	private static function builtins() {
		return array(
			array( 'id' => 'production_never_auto', 'priority' => 1000, 'signals_all' => array( 'environment_production' ), 'decision' => self::DECISION_HARD_BLOCK, 'reason' => 'production_is_never_auto_reconciled' ),
			array( 'id' => 'untrusted_package_never_auto', 'priority' => 995, 'signals_all' => array( 'untrusted_package' ), 'decision' => self::DECISION_HARD_BLOCK, 'reason' => 'package_provenance_must_be_trusted_before_reconciliation' ),
			array( 'id' => 'breakglass_never_auto', 'priority' => 990, 'signals_all' => array( 'breakglass_enabled' ), 'decision' => self::DECISION_HARD_BLOCK, 'reason' => 'breakglass_state_requires_explicit_operator_control' ),
			array( 'id' => 'authority_evidence_drift', 'priority' => 985, 'signals_any' => array( 'grant_inventory_drift', 'write_contract_drift', 'site_profile_drift', 'actor_identity_drift', 'transport_contract_drift', 'baseline_expired' ), 'decision' => self::DECISION_REVIEW, 'reason' => 'authority_affecting_evidence_changed' ),
			array( 'id' => 'identity_incomplete', 'priority' => 980, 'signals_all' => array( 'reconcile_needed', 'runtime_identity_incomplete' ), 'decision' => self::DECISION_HARD_BLOCK, 'reason' => 'exact_runtime_identity_required' ),
			array( 'id' => 'continuation_blocked', 'priority' => 970, 'signals_all' => array( 'continuation_blocked' ), 'decision' => self::DECISION_HARD_BLOCK, 'reason' => 'terminal_continuation_blocker_requires_repair' ),
			array( 'id' => 'continuation_owner_gate', 'priority' => 960, 'signals_all' => array( 'continuation_owner_gate' ), 'decision' => self::DECISION_REVIEW, 'reason' => 'owner_gate_cannot_be_auto_satisfied' ),
			array( 'id' => 'maintenance_busy', 'priority' => 950, 'signals_all' => array( 'maintenance_busy' ), 'decision' => self::DECISION_DEFER, 'reason' => 'single_writer_maintenance_lane_busy' ),
			array( 'id' => 'concurrent_reconciliation', 'priority' => 945, 'signals_any' => array( 'continuation_conflict', 'concurrent_permit' ), 'decision' => self::DECISION_DEFER, 'reason' => 'another_governed_reconciliation_owner_is_active' ),
			array( 'id' => 'continuation_executing', 'priority' => 940, 'signals_all' => array( 'continuation_executing' ), 'decision' => self::DECISION_DEFER, 'reason' => 'continuation_already_owns_execution' ),
			array( 'id' => 'skills_dependency_pending', 'priority' => 930, 'signals_all' => array( 'reconcile_needed', 'skills_pending', 'environment_staging', 'runtime_identity_complete' ), 'decision' => self::DECISION_SCHEDULE_PROBE, 'reason' => 'runtime_worker_must_reconcile_skills_before_zero_delta_rebind' ),
			array( 'id' => 'same_version_package_replacement', 'priority' => 925, 'signals_all' => array( 'environment_staging', 'candidate_binding_drift', 'runtime_identity_complete', 'same_version_identity_drift' ), 'decision' => self::DECISION_SCHEDULE_PROBE, 'reason' => 'same_version_replacement_requires_zero_delta_probe' ),
			array( 'id' => 'forward_package_update', 'priority' => 920, 'signals_all' => array( 'environment_staging', 'candidate_binding_drift', 'runtime_identity_complete', 'version_forward' ), 'decision' => self::DECISION_SCHEDULE_PROBE, 'reason' => 'forward_update_requires_zero_delta_probe' ),
			array( 'id' => 'rollback_or_reinstall', 'priority' => 915, 'signals_all' => array( 'environment_staging', 'candidate_binding_drift', 'runtime_identity_complete', 'version_rollback' ), 'decision' => self::DECISION_SCHEDULE_PROBE, 'reason' => 'rollback_or_reinstall_requires_zero_delta_probe' ),
			array( 'id' => 'fresh_bootstrap_unbound', 'priority' => 910, 'signals_all' => array( 'environment_staging', 'build_changed', 'runtime_identity_complete', 'stored_binding_absent' ), 'decision' => self::DECISION_SCHEDULE_PROBE, 'reason' => 'fresh_bootstrap_requires_safe_runtime_convergence_without_authority_carry_forward' ),
			array( 'id' => 'manual_or_same_version_package_drift', 'priority' => 900, 'signals_all' => array( 'environment_staging', 'candidate_binding_drift', 'runtime_identity_complete' ), 'signals_any' => array( 'source_wordpress_upgrader', 'source_build_stamp_drift', 'source_manual_replacement', 'source_plugin_activation', 'build_changed' ), 'decision' => self::DECISION_SCHEDULE_PROBE, 'reason' => 'candidate_binding_drift_requires_zero_delta_probe' ),
			array( 'id' => 'native_or_release_set_continuation', 'priority' => 890, 'signals_all' => array( 'environment_staging', 'continuation_pending', 'runtime_identity_complete' ), 'decision' => self::DECISION_SCHEDULE_PROBE, 'reason' => 'resume_existing_exact_post_update_continuation' ),
			array( 'id' => 'trusted_reinstall_or_rollback_probe', 'priority' => 880, 'signals_all' => array( 'environment_staging', 'candidate_binding_drift', 'runtime_identity_complete', 'stored_binding_present' ), 'decision' => self::DECISION_SCHEDULE_PROBE, 'reason' => 'exact_release_identity_changed_with_prior_bound_authority' ),
			array( 'id' => 'version_or_schema_drift', 'priority' => 870, 'signals_all' => array( 'environment_staging', 'runtime_identity_complete' ), 'signals_any' => array( 'version_drift', 'schema_drift' ), 'decision' => self::DECISION_SCHEDULE_PROBE, 'reason' => 'runtime_lifecycle_drift_requires_bounded_convergence' ),
			array( 'id' => 'already_converged', 'priority' => 500, 'signals_all' => array( 'candidate_binding_match' ), 'signals_none' => array( 'version_drift', 'schema_drift', 'continuation_pending' ), 'decision' => self::DECISION_NO_OP, 'reason' => 'current_runtime_already_matches_bound_authority' ),
			array( 'id' => 'unclassified_reconcile_need', 'priority' => 100, 'signals_all' => array( 'reconcile_needed' ), 'decision' => self::DECISION_REVIEW, 'reason' => 'unclassified_drift_never_auto_mutates' ),
			array( 'id' => 'no_reconcile_needed', 'priority' => 0, 'decision' => self::DECISION_NO_OP, 'reason' => 'no_runtime_reconciliation_signal' ),
		);
	}

	public static function registry() {
		$core = self::builtins();
		$extensions = array();
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'mad4b_scp_auto_reconcile_scenarios', array() );
			if ( is_array( $filtered ) ) $extensions = array_slice( $filtered, 0, 50 );
		}
		$core_ids = array();
		$rows = array();
		foreach ( $core as $core_row ) {
			if ( ! is_array( $core_row ) || empty( $core_row['id'] ) ) continue;
			$core_id = sanitize_key( (string) $core_row['id'] );
			if ( '' === $core_id ) continue;
			$core_ids[ $core_id ] = true;
			$core_row['_source'] = 'core';
			$rows[] = $core_row;
		}
		foreach ( $extensions as $extension_row ) {
			if ( ! is_array( $extension_row ) || empty( $extension_row['id'] ) ) continue;
			$extension_id = sanitize_key( (string) $extension_row['id'] );
			if ( '' === $extension_id || isset( $core_ids[ $extension_id ] ) ) continue;
			$extension_row['_source'] = 'extension';
			$rows[] = $extension_row;
		}
		$out = array();
		$seen_ids = array();
		foreach ( array_slice( $rows, 0, 150 ) as $row ) {
			if ( ! is_array( $row ) ) continue;
			$id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			if ( '' === $id || isset( $seen_ids[ $id ] ) ) continue;
			$seen_ids[ $id ] = true;
			$decision = isset( $row['decision'] ) ? strtoupper( sanitize_text_field( (string) $row['decision'] ) ) : self::DECISION_REVIEW;
			if ( ! in_array( $decision, array( self::DECISION_NO_OP, self::DECISION_SCHEDULE_PROBE, self::DECISION_DEFER, self::DECISION_REVIEW, self::DECISION_HARD_BLOCK ), true ) ) {
				$decision = self::DECISION_REVIEW;
			}
			$is_core = 'core' === ( isset( $row['_source'] ) ? (string) $row['_source'] : '' );
			$priority = max( -1000, min( $is_core ? 1000 : 850, isset( $row['priority'] ) ? (int) $row['priority'] : 0 ) );
			$out[] = array(
				'id' => $id,
				'source' => $is_core ? 'core' : 'extension',
				'priority' => $priority,
				'signals_all' => self::signal_list( isset( $row['signals_all'] ) ? $row['signals_all'] : array() ),
				'signals_any' => self::signal_list( isset( $row['signals_any'] ) ? $row['signals_any'] : array() ),
				'signals_none' => self::signal_list( isset( $row['signals_none'] ) ? $row['signals_none'] : array() ),
				'decision' => $decision,
				'reason' => isset( $row['reason'] ) ? sanitize_key( (string) $row['reason'] ) : 'scenario_policy',
				// Immutable central safety properties. Extension descriptors cannot relax these.
				'mutation_allowed' => false,
				'authority_expansion_allowed' => false,
				'zero_delta_required_for_rebind' => true,
				'rebind_executor' => 'MAD4B_SCP_Post_Update_Continuation::evaluate_and_rebind',
			);
		}
		usort( $out, static function ( $left, $right ) {
			$priority = (int) $right['priority'] <=> (int) $left['priority'];
			return 0 !== $priority ? $priority : strcmp( (string) $left['id'], (string) $right['id'] );
		} );
		return $out;
	}

	private static function signal_list( $values ) {
		if ( ! is_array( $values ) ) $values = array( $values );
		$out = array();
		foreach ( array_slice( $values, 0, 50 ) as $value ) {
			$key = sanitize_key( (string) $value );
			if ( '' !== $key ) $out[ $key ] = true;
		}
		return array_keys( $out );
	}

	private static function match( array $scenario, array $signals ) {
		foreach ( $scenario['signals_all'] as $signal ) if ( empty( $signals[ $signal ] ) ) return false;
		if ( ! empty( $scenario['signals_any'] ) ) {
			$matched = false;
			foreach ( $scenario['signals_any'] as $signal ) if ( ! empty( $signals[ $signal ] ) ) { $matched = true; break; }
			if ( ! $matched ) return false;
		}
		foreach ( $scenario['signals_none'] as $signal ) if ( ! empty( $signals[ $signal ] ) ) return false;
		return true;
	}

	public static function evaluate( array $context ) {
		$signals = self::signals( $context );
		foreach ( self::registry() as $scenario ) {
			if ( ! self::match( $scenario, $signals ) ) continue;
			return array(
				'contract' => self::CONTRACT,
				'scenario_id' => $scenario['id'],
				'decision' => $scenario['decision'],
				'reason' => $scenario['reason'],
				'signals' => array_keys( array_filter( $signals ) ),
				'mutation_allowed' => false,
				'authority_expansion_allowed' => false,
				'zero_delta_required_for_rebind' => true,
				'rebind_executor' => $scenario['rebind_executor'],
				'authorizing' => false,
				'production_mutation' => false,
			);
		}
		return array(
			'contract' => self::CONTRACT,
			'scenario_id' => 'registry_fallback',
			'decision' => self::DECISION_REVIEW,
			'reason' => 'no_scenario_matched',
			'signals' => array_keys( array_filter( $signals ) ),
			'mutation_allowed' => false,
			'authority_expansion_allowed' => false,
			'zero_delta_required_for_rebind' => true,
			'authorizing' => false,
			'production_mutation' => false,
		);
	}

	private static function signals( array $context ) {
		$environment = sanitize_key( isset( $context['environment'] ) ? (string) $context['environment'] : '' );
		$binding = isset( $context['candidate_binding'] ) && is_array( $context['candidate_binding'] ) ? $context['candidate_binding'] : array();
		$continuation = isset( $context['continuation'] ) && is_array( $context['continuation'] ) ? $context['continuation'] : array();
		$maintenance = isset( $context['maintenance'] ) && is_array( $context['maintenance'] ) ? $context['maintenance'] : array();
		$source = sanitize_key( isset( $context['source'] ) ? (string) $context['source'] : '' );
		$continuation_state = sanitize_key( isset( $continuation['state'] ) ? (string) $continuation['state'] : '' );
		$candidate_drift = ! empty( $binding['required'] ) && ! empty( $binding['stored_bound'] ) && empty( $binding['match'] );
		$version_drift = ! empty( $context['version_drift'] );
		$schema_drift = ! empty( $context['schema_drift'] );
		$build_changed = ! empty( $context['build_changed'] );
		$current_version = trim( (string) ( isset( $context['current_version'] ) ? $context['current_version'] : '' ) );
		$stored_version = trim( (string) ( isset( $context['stored_version'] ) ? $context['stored_version'] : '' ) );
		$same_version_identity_drift = $candidate_drift && '' !== $current_version && '' !== $stored_version && hash_equals( $current_version, $stored_version );
		$version_forward = $candidate_drift && '' !== $current_version && '' !== $stored_version && function_exists( 'version_compare' ) && version_compare( $current_version, $stored_version, '>' );
		$version_rollback = $candidate_drift && '' !== $current_version && '' !== $stored_version && function_exists( 'version_compare' ) && version_compare( $current_version, $stored_version, '<' );
		$continuation_pending = ! empty( $continuation['active'] ) && in_array( $continuation_state, array( 'exact_readback_verified', 'pending_convergence', 'prepared', 'claimed' ), true );
		$signals = array(
			'environment_staging' => 'staging' === $environment,
			'environment_production' => 'production' === $environment,
			'breakglass_enabled' => ! empty( $context['breakglass_enabled'] ),
			'runtime_identity_complete' => ! empty( $context['runtime_identity_complete'] ),
			'runtime_identity_incomplete' => empty( $context['runtime_identity_complete'] ),
			'candidate_binding_required' => ! empty( $binding['required'] ),
			'stored_binding_present' => ! empty( $binding['stored_bound'] ),
			'stored_binding_absent' => empty( $binding['stored_bound'] ),
			'candidate_binding_drift' => $candidate_drift,
			'candidate_binding_match' => ! empty( $binding['match'] ),
			'version_drift' => $version_drift,
			'schema_drift' => $schema_drift,
			'build_changed' => $build_changed,
			'same_version_identity_drift' => $same_version_identity_drift,
			'version_forward' => $version_forward,
			'version_rollback' => $version_rollback,
			'continuation_pending' => $continuation_pending,
			'continuation_blocked' => in_array( $continuation_state, array( 'blocked', 'authority_blocked' ), true ),
			'continuation_owner_gate' => 'owner_gate' === $continuation_state,
			'continuation_executing' => 'executing' === $continuation_state,
			'skills_pending' => ! empty( $context['skills_pending'] ),
			'maintenance_busy' => ! empty( $maintenance['active'] ) && 'adaptive_runtime_observation' !== sanitize_key( isset( $maintenance['owner'] ) ? (string) $maintenance['owner'] : '' ),
			'reconcile_needed' => $candidate_drift || $version_drift || $schema_drift || $build_changed || $continuation_pending,
			'source_wordpress_upgrader' => 'wordpress_upgrader' === $source,
			'source_build_stamp_drift' => 'build_stamp_drift' === $source,
			'source_manual_replacement' => 'manual_replacement' === $source,
			'source_plugin_activation' => 'plugin_activation' === $source,
			'source_native_self_update' => 'self_update' === $source,
			'source_release_set' => 'runtime_release_set' === $source,
		);
		if ( isset( $context['signals'] ) && is_array( $context['signals'] ) ) {
			foreach ( array_slice( $context['signals'], 0, 50, true ) as $key => $enabled ) {
				$key = sanitize_key( (string) $key );
				if ( '' !== $key && ! array_key_exists( $key, $signals ) ) $signals[ $key ] = (bool) $enabled;
			}
		}
		return $signals;
	}

	public static function classify_worker_error( $error_code ) {
		$code = sanitize_key( (string) $error_code );
		$hard = array(
			'mad4b_observed_update_package_integrity_required',
			'mad4b_observed_update_target_mismatch',
			'mad4b_post_update_continuation_release_untrusted',
			'mad4b_post_update_continuation_staging_only',
			'mad4b_post_update_continuation_current_identity_unavailable',
			'mad4b_post_update_continuation_integrity_failed',
			'mad4b_post_update_continuation_target_identity_invalid',
		);
		$defer = array(
			'mad4b_observed_update_baseline_required',
			'mad4b_observed_update_permit_raced',
			'mad4b_post_update_continuation_active_permit_exists',
			'mad4b_post_update_continuation_cas_conflict',
			'mad4b_post_update_continuation_consume_fence_lost',
			'mad4b_post_update_continuation_generation_conflict',
			'mad4b_post_update_continuation_lease_required',
			'mad4b_post_update_continuation_not_claimed',
			'mad4b_post_update_continuation_skills_certification_required',
		);
		$review = array(
			'mad4b_observed_update_authority_delta',
			'mad4b_post_update_continuation_actor_mismatch',
			'mad4b_post_update_continuation_actor_required',
			'mad4b_post_update_continuation_actor_user_mismatch',
			'mad4b_post_update_continuation_context_mismatch',
			'mad4b_post_update_continuation_live_delta_changed',
			'mad4b_post_update_continuation_postcondition_drift',
			'mad4b_post_update_continuation_previous_authority_required',
			'mad4b_post_update_continuation_profile_ineligible',
			'mad4b_post_update_continuation_request_proof_required',
			'mad4b_post_update_continuation_target_drift',
			'mad4b_post_update_continuation_write_snapshot_unavailable',
		);
		$decision = in_array( $code, $hard, true ) ? self::DECISION_HARD_BLOCK
			: ( in_array( $code, $defer, true ) ? self::DECISION_DEFER
				: ( in_array( $code, $review, true ) ? self::DECISION_REVIEW : self::DECISION_REVIEW ) );
		return array(
			'contract' => self::CONTRACT,
			'error_code' => $code,
			'decision' => $decision,
			'mutation_allowed' => false,
			'authority_expansion_allowed' => false,
			'zero_delta_required_for_rebind' => true,
			'authorizing' => false,
		);
	}
}
