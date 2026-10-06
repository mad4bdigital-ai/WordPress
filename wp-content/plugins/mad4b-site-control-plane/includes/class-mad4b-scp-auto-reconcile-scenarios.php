<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Declarative, extensible classification for bounded runtime auto-reconciliation.
 *
 * This registry never grants authority. It only decides whether the existing
 * Runtime Convergence worker may be scheduled automatically. Candidate binding
 * mutation remains owned by Post_Update_Continuation and therefore still
 * requires a trusted release, a sealed healthy baseline, exact actor/profile/
 * transport/grant continuity, and a ZERO_DELTA classification.
 */
final class MAD4B_SCP_Auto_Reconcile_Scenarios {
	const CONTRACT = 'mad4b.auto-reconcile-scenario-registry.v1';
	const STATUS_CONTRACT = 'mad4b.auto-reconcile-status.v1';
	const STATUS_OPTION = 'mad4b_scp_auto_reconcile_status_v1';
	const FILTER = 'mad4b_scp_auto_reconcile_scenarios';

	const DECISION_NO_OP = 'NO_OP';
	const DECISION_AUTO_EVALUATE = 'AUTO_EVALUATE';
	const DECISION_DEFER = 'DEFER';
	const DECISION_REVIEW = 'REVIEW_REQUIRED';
	const DECISION_HARD_BLOCK = 'HARD_BLOCK';

	const ZERO_DELTA_POLICY = 'post_update_zero_delta_only';

	public static function registry() {
		$rows = array(
			array(
				'id' => 'continuation_blocked',
				'priority' => 980,
				'decision' => self::DECISION_REVIEW,
				'when' => array( array( 'field' => 'continuation_state', 'op' => 'in', 'value' => array( 'blocked', 'owner_gate' ) ) ),
			),
			array(
				'id' => 'maintenance_busy',
				'priority' => 970,
				'decision' => self::DECISION_DEFER,
				'when' => array( array( 'field' => 'maintenance_busy', 'op' => 'truthy' ) ),
			),
			array(
				'id' => 'continuation_active',
				'priority' => 960,
				'decision' => self::DECISION_NO_OP,
				'when' => array( array( 'field' => 'continuation_active', 'op' => 'truthy' ) ),
			),
			array(
				'id' => 'candidate_and_authority_drift',
				'priority' => 940,
				'decision' => self::DECISION_REVIEW,
				'when' => array(
					array( 'field' => 'candidate_binding_drift', 'op' => 'truthy' ),
					array( 'field' => 'authority_drift', 'op' => 'truthy' ),
				),
			),
			array(
				'id' => 'authority_drift',
				'priority' => 930,
				'decision' => self::DECISION_REVIEW,
				'when' => array( array( 'field' => 'authority_drift', 'op' => 'truthy' ) ),
			),
			array(
				'id' => 'candidate_binding_drift',
				'priority' => 900,
				'decision' => self::DECISION_AUTO_EVALUATE,
				'authority_effect' => 'none',
				'mutation_policy' => self::ZERO_DELTA_POLICY,
				'when' => array( array( 'field' => 'candidate_binding_drift', 'op' => 'truthy' ) ),
			),
			array(
				'id' => 'plugin_version_drift',
				'priority' => 800,
				'decision' => self::DECISION_AUTO_EVALUATE,
				'authority_effect' => 'none',
				'mutation_policy' => self::ZERO_DELTA_POLICY,
				'when' => array( array( 'field' => 'plugin_version_drift', 'op' => 'truthy' ) ),
			),
			array(
				'id' => 'schema_version_drift',
				'priority' => 790,
				'decision' => self::DECISION_AUTO_EVALUATE,
				'authority_effect' => 'none',
				'mutation_policy' => self::ZERO_DELTA_POLICY,
				'when' => array( array( 'field' => 'schema_version_drift', 'op' => 'truthy' ) ),
			),
			array(
				'id' => 'skills_pending',
				'priority' => 500,
				'decision' => self::DECISION_DEFER,
				'when' => array( array( 'field' => 'skills_pending', 'op' => 'truthy' ) ),
			),
			array(
				'id' => 'steady_state',
				'priority' => 0,
				'decision' => self::DECISION_NO_OP,
				'when' => array(),
			),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( self::FILTER, $rows );
			if ( is_array( $filtered ) ) $rows = $filtered;
		}
		return self::normalize_registry( $rows );
	}

	public static function classify( array $signals ) {
		$signals = self::normalize_signals( $signals );

		// Central invariants override every registered scenario, including filters.
		if ( 'production' === $signals['environment'] ) {
			return self::result( 'production_observe_only', self::DECISION_HARD_BLOCK, $signals, array( 'production_environment' ) );
		}
		if ( $signals['breakglass_active'] ) {
			return self::result( 'breakglass_active', self::DECISION_HARD_BLOCK, $signals, array( 'breakglass_active' ) );
		}
		if ( in_array( $signals['continuation_state'], array( 'blocked', 'owner_gate' ), true ) ) {
			return self::result( 'continuation_requires_review', self::DECISION_REVIEW, $signals, array( 'continuation_requires_review' ) );
		}
		if ( $signals['authority_drift'] ) {
			return self::result( 'authority_drift', self::DECISION_REVIEW, $signals, array( 'authority_drift' ) );
		}
		if ( $signals['maintenance_busy'] ) {
			return self::result( 'maintenance_busy', self::DECISION_DEFER, $signals, array( 'maintenance_busy' ) );
		}
		if ( $signals['continuation_active'] ) {
			return self::result( 'continuation_active', self::DECISION_NO_OP, $signals, array() );
		}
		$drift_present = $signals['candidate_binding_drift'] || $signals['plugin_version_drift'] || $signals['schema_version_drift'] || $signals['authority_drift'];
		if ( $drift_present && ! $signals['identity_complete'] ) {
			return self::result( 'exact_identity_incomplete', self::DECISION_DEFER, $signals, array( 'exact_runtime_identity_incomplete' ) );
		}

		foreach ( self::registry() as $scenario ) {
			if ( ! self::matches( $scenario, $signals ) ) continue;
			return self::result( $scenario['id'], $scenario['decision'], $signals, array() );
		}
		return self::result( 'registry_no_match', self::DECISION_REVIEW, $signals, array( 'scenario_registry_no_match' ) );
	}

	public static function persist( array $classification, array $extra = array() ) {
		$row = array(
			'contract' => self::STATUS_CONTRACT,
			'scenario_registry_contract' => self::CONTRACT,
			'scenario_id' => isset( $classification['scenario_id'] ) ? sanitize_key( (string) $classification['scenario_id'] ) : '',
			'decision' => isset( $classification['decision'] ) ? (string) $classification['decision'] : self::DECISION_REVIEW,
			'automatic_worker_allowed' => ! empty( $classification['automatic_worker_allowed'] ),
			'automatic_candidate_binding_allowed' => false,
			'zero_delta_required' => true,
			'trusted_release_required' => true,
			'authority_expansion_allowed' => false,
			'production_mutation_allowed' => false,
			'breakglass_allowed' => false,
			'reasons' => isset( $classification['reasons'] ) && is_array( $classification['reasons'] ) ? array_slice( array_values( array_unique( array_map( 'sanitize_key', $classification['reasons'] ) ) ), 0, 32 ) : array(),
			'updated_at' => gmdate( 'c' ),
			'mutation_performed' => false,
		);
		foreach ( array( 'source', 'worker_error_code', 'target_source_commit_sha' ) as $field ) {
			if ( isset( $extra[ $field ] ) ) $row[ $field ] = substr( sanitize_text_field( (string) $extra[ $field ] ), 0, 191 );
		}
		update_option( self::STATUS_OPTION, $row, false );
		return $row;
	}

	public static function status() {
		$row = get_option( self::STATUS_OPTION, array() );
		if ( ! is_array( $row ) || self::STATUS_CONTRACT !== ( isset( $row['contract'] ) ? (string) $row['contract'] : '' ) ) {
			return array(
				'contract' => self::STATUS_CONTRACT,
				'scenario_registry_contract' => self::CONTRACT,
				'scenario_id' => 'unobserved',
				'decision' => self::DECISION_NO_OP,
				'automatic_worker_allowed' => false,
				'automatic_candidate_binding_allowed' => false,
				'zero_delta_required' => true,
				'trusted_release_required' => true,
				'authority_expansion_allowed' => false,
				'production_mutation_allowed' => false,
				'breakglass_allowed' => false,
				'reasons' => array(),
				'mutation_performed' => false,
			);
		}
		return $row;
	}

	public static function record_worker_error( $code, array $extra = array() ) {
		$code = sanitize_key( (string) $code );
		$decision = self::DECISION_REVIEW;
		if ( in_array( $code, array(
			'mad4b_observed_update_baseline_required',
			'mad4b_post_update_continuation_skills_certification_required',
			'mad4b_runtime_convergence_busy',
		), true ) ) $decision = self::DECISION_DEFER;
		if ( in_array( $code, array(
			'mad4b_observed_update_package_integrity_required',
			'mad4b_observed_update_target_mismatch',
			'mad4b_post_update_continuation_integrity_failed',
		), true ) ) $decision = self::DECISION_HARD_BLOCK;
		$classification = array(
			'scenario_id' => 'worker_' . ( '' !== $code ? $code : 'unknown_error' ),
			'decision' => $decision,
			'automatic_worker_allowed' => false,
			'reasons' => array( '' !== $code ? $code : 'worker_error' ),
		);
		$extra['worker_error_code'] = $code;
		return self::persist( $classification, $extra );
	}

	private static function normalize_registry( $rows ) {
		$out = array();
		$allowed = array( self::DECISION_NO_OP, self::DECISION_AUTO_EVALUATE, self::DECISION_DEFER, self::DECISION_REVIEW, self::DECISION_HARD_BLOCK );
		foreach ( array_slice( is_array( $rows ) ? $rows : array(), 0, 64 ) as $row ) {
			if ( ! is_array( $row ) ) continue;
			$id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			$decision = isset( $row['decision'] ) ? strtoupper( trim( (string) $row['decision'] ) ) : '';
			if ( '' === $id || ! in_array( $decision, $allowed, true ) ) continue;
			$when = isset( $row['when'] ) && is_array( $row['when'] ) ? array_slice( $row['when'], 0, 16 ) : array();
			// Extension rows may schedule evaluation, never authority expansion.
			if ( self::DECISION_AUTO_EVALUATE === $decision ) {
				if ( ! array_key_exists( 'authority_effect', $row ) || ! array_key_exists( 'mutation_policy', $row ) ) continue;
				$authority_effect = sanitize_key( (string) $row['authority_effect'] );
				$mutation_policy = sanitize_key( (string) $row['mutation_policy'] );
				if ( 'none' !== $authority_effect || self::ZERO_DELTA_POLICY !== $mutation_policy ) continue;
			}
			$out[] = array(
				'id' => $id,
				'priority' => isset( $row['priority'] ) ? max( -10000, min( 10000, (int) $row['priority'] ) ) : 0,
				'decision' => $decision,
				'when' => $when,
			);
		}
		usort( $out, static function ( $a, $b ) {
			if ( $a['priority'] === $b['priority'] ) return strcmp( $a['id'], $b['id'] );
			return $a['priority'] > $b['priority'] ? -1 : 1;
		} );
		return $out;
	}

	private static function normalize_signals( array $signals ) {
		$bools = array(
			'breakglass_active', 'identity_complete', 'continuation_active',
			'candidate_binding_drift', 'plugin_version_drift', 'schema_version_drift',
			'authority_drift', 'skills_pending', 'maintenance_busy', 'lifecycle_hint_present',
		);
		$out = array(
			'environment' => isset( $signals['environment'] ) ? sanitize_key( (string) $signals['environment'] ) : 'unknown',
			'continuation_state' => isset( $signals['continuation_state'] ) ? sanitize_key( (string) $signals['continuation_state'] ) : '',
		);
		foreach ( $bools as $field ) $out[ $field ] = ! empty( $signals[ $field ] );
		return $out;
	}

	private static function matches( array $scenario, array $signals ) {
		$conditions = isset( $scenario['when'] ) && is_array( $scenario['when'] ) ? $scenario['when'] : array();
		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) ) return false;
			$field = isset( $condition['field'] ) ? sanitize_key( (string) $condition['field'] ) : '';
			$op = isset( $condition['op'] ) ? sanitize_key( (string) $condition['op'] ) : 'eq';
			if ( '' === $field || ! array_key_exists( $field, $signals ) ) return false;
			$actual = $signals[ $field ];
			$expected = isset( $condition['value'] ) ? $condition['value'] : null;
			if ( 'truthy' === $op && ! $actual ) return false;
			if ( 'falsy' === $op && $actual ) return false;
			if ( 'eq' === $op && (string) $actual !== (string) $expected ) return false;
			if ( 'in' === $op && ( ! is_array( $expected ) || ! in_array( $actual, $expected, true ) ) ) return false;
		}
		return true;
	}

	private static function result( $scenario_id, $decision, array $signals, array $reasons ) {
		$auto = self::DECISION_AUTO_EVALUATE === $decision;
		return array(
			'contract' => self::CONTRACT,
			'scenario_id' => sanitize_key( (string) $scenario_id ),
			'decision' => (string) $decision,
			'automatic_worker_allowed' => $auto,
			'automatic_candidate_binding_allowed' => false,
			'automatic_binding_requires' => 'trusted_release_plus_sealed_baseline_plus_zero_delta',
			'zero_delta_required' => true,
			'trusted_release_required' => true,
			'authority_expansion_allowed' => false,
			'production_mutation_allowed' => false,
			'breakglass_allowed' => false,
			'dynamic_registry' => true,
			'reasons' => array_values( array_unique( array_map( 'sanitize_key', $reasons ) ) ),
			'signals' => $signals,
			'mutation_performed' => false,
		);
	}
}
