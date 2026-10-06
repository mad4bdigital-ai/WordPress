<?php
/**
 * Extensible, fail-closed runtime reconciliation scenario registry.
 *
 * Scenario descriptors only classify observed drift. They never grant authority.
 * Candidate binding can be refreshed automatically only by the existing
 * Post_Update_Continuation ZERO_DELTA proof path.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class MAD4B_SCP_Runtime_Reconciliation_Scenarios {
	const CONTRACT = 'mad4b.runtime-reconciliation-scenarios.v1';
	const FILTER = 'mad4b_scp_runtime_reconciliation_scenarios';

	const DISPOSITION_NOOP = 'no_op';
	const DISPOSITION_AUTO_SAFE = 'auto_safe_phases';
	const DISPOSITION_AUTO_ZERO_DELTA = 'auto_evaluate_zero_delta';
	const DISPOSITION_DEFER = 'defer';
	const DISPOSITION_REVIEW = 'review_required';
	const DISPOSITION_HARD_BLOCK = 'hard_block';

	public static function registry( array $context = array() ) {
		$rows = array(
			'untrusted_package' => array(
				'priority' => 320,
				'all_of' => array( 'untrusted_package' ),
				'description' => 'Installed package identity cannot be proven against a trusted release source.',
			),
			'authority_contract_drift' => array(
				'priority' => 310,
				'any_of' => array( 'grant_inventory_drift', 'write_contract_drift', 'site_profile_drift', 'actor_identity_drift', 'transport_contract_drift', 'baseline_expired' ),
				'description' => 'Authority-affecting evidence changed and requires explicit review.',
			),
			'concurrent_reconciliation' => array(
				'priority' => 300,
				'any_of' => array( 'continuation_conflict', 'maintenance_busy', 'concurrent_permit' ),
				'description' => 'Another governed continuation or maintenance lease owns reconciliation.',
			),
			'same_version_package_replacement' => array(
				'priority' => 180,
				'all_of' => array( 'candidate_binding_drift', 'same_version_identity_drift' ),
				'description' => 'Same-version package replacement with exact candidate identity drift.',
			),
			'forward_package_replacement' => array(
				'priority' => 170,
				'all_of' => array( 'candidate_binding_drift', 'version_forward' ),
				'description' => 'Forward package replacement with candidate identity drift.',
			),
			'rollback_or_reinstall' => array(
				'priority' => 160,
				'all_of' => array( 'candidate_binding_drift', 'version_rollback' ),
				'description' => 'Trusted rollback/reinstall candidate requiring exact ZERO_DELTA evaluation.',
			),
			'interrupted_continuation' => array(
				'priority' => 150,
				'all_of' => array( 'continuation_pending' ),
				'description' => 'A previously persisted post-update continuation is awaiting safe resumption.',
			),
			'candidate_binding_identity_drift' => array(
				'priority' => 140,
				'all_of' => array( 'candidate_binding_drift' ),
				'description' => 'Candidate binding differs from the exact installed runtime identity.',
			),
			'plugin_version_drift' => array(
				'priority' => 80,
				'all_of' => array( 'plugin_version_drift' ),
				'none_of' => array( 'candidate_binding_drift' ),
				'description' => 'Plugin version checkpoint drift without authority-candidate drift.',
			),
			'schema_version_drift' => array(
				'priority' => 70,
				'all_of' => array( 'schema_version_drift' ),
				'none_of' => array( 'candidate_binding_drift' ),
				'description' => 'Schema version drift without authority-candidate drift.',
			),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( self::FILTER, $rows, $context );
			if ( is_array( $filtered ) ) $rows = $filtered;
		}
		$normalized = array();
		foreach ( $rows as $id => $row ) {
			$id = self::key( $id );
			if ( '' === $id || ! is_array( $row ) ) continue;
			$normalized[ $id ] = array(
				'id' => $id,
				'priority' => isset( $row['priority'] ) ? max( -1000, min( 1000, (int) $row['priority'] ) ) : 0,
				'all_of' => self::signals( isset( $row['all_of'] ) ? $row['all_of'] : array() ),
				'any_of' => self::signals( isset( $row['any_of'] ) ? $row['any_of'] : array() ),
				'none_of' => self::signals( isset( $row['none_of'] ) ? $row['none_of'] : array() ),
				'description' => isset( $row['description'] ) ? substr( trim( (string) $row['description'] ), 0, 240 ) : '',
			);
		}
		uasort( $normalized, static function ( $left, $right ) {
			$priority = (int) $right['priority'] <=> (int) $left['priority'];
			return 0 !== $priority ? $priority : strcmp( (string) $left['id'], (string) $right['id'] );
		} );
		return $normalized;
	}

	public static function classify( array $context ) {
		$signals = self::signals( isset( $context['signals'] ) ? $context['signals'] : array() );
		$scenario_id = 'unclassified_runtime_drift';
		$description = 'Observed runtime drift does not match a registered scenario.';
		foreach ( self::registry( $context ) as $id => $row ) {
			if ( self::matches( $row, $signals ) ) {
				$scenario_id = $id;
				$description = $row['description'];
				break;
			}
		}

		$environment = self::key( isset( $context['environment'] ) ? $context['environment'] : '' );
		$continuation_state = self::key( isset( $context['continuation_state'] ) ? $context['continuation_state'] : '' );
		$binding = isset( $context['candidate_binding'] ) && is_array( $context['candidate_binding'] ) ? $context['candidate_binding'] : array();
		$identity_complete = ! empty( $context['identity_complete'] );
		$disposition = self::central_disposition( $signals, $environment, $continuation_state, $binding, $identity_complete );

		$candidate_drift = in_array( 'candidate_binding_drift', $signals, true );
		return array(
			'contract' => self::CONTRACT,
			'scenario_id' => $scenario_id,
			'description' => $description,
			'signals' => $signals,
			'disposition' => $disposition,
			'zero_delta_required' => $candidate_drift,
			'candidate_binding_mutation_via_continuation_only' => $candidate_drift,
			'authority_mutation_allowed' => false,
			'grant_mutation_allowed' => false,
			'production_mutation_allowed' => false,
			'descriptor_can_override_safety' => false,
			'extension_filter' => self::FILTER,
			'extensible' => true,
		);
	}

	private static function central_disposition( array $signals, $environment, $continuation_state, array $binding, $identity_complete ) {
		if ( 'production' === $environment ) return self::DISPOSITION_HARD_BLOCK;
		if ( 'staging' !== $environment ) return self::DISPOSITION_REVIEW;
		if ( in_array( 'breakglass_active', $signals, true ) || in_array( 'untrusted_package', $signals, true ) ) return self::DISPOSITION_HARD_BLOCK;
		foreach ( array( 'grant_inventory_drift', 'write_contract_drift', 'site_profile_drift', 'actor_identity_drift', 'transport_contract_drift', 'baseline_expired' ) as $review_signal ) {
			if ( in_array( $review_signal, $signals, true ) ) return self::DISPOSITION_REVIEW;
		}
		foreach ( array( 'continuation_conflict', 'maintenance_busy', 'concurrent_permit' ) as $defer_signal ) {
			if ( in_array( $defer_signal, $signals, true ) ) return self::DISPOSITION_DEFER;
		}
		if ( in_array( $continuation_state, array( 'blocked', 'owner_gate', 'executing' ), true ) ) return self::DISPOSITION_REVIEW;
		if ( ! $identity_complete ) return self::DISPOSITION_DEFER;
		if ( in_array( 'candidate_binding_drift', $signals, true ) ) {
			if ( empty( $binding['stored_bound'] ) ) return self::DISPOSITION_REVIEW;
			return self::DISPOSITION_AUTO_ZERO_DELTA;
		}
		if ( in_array( 'continuation_pending', $signals, true )
			|| in_array( 'plugin_version_drift', $signals, true )
			|| in_array( 'schema_version_drift', $signals, true ) ) {
			return self::DISPOSITION_AUTO_SAFE;
		}
		return empty( $signals ) ? self::DISPOSITION_NOOP : self::DISPOSITION_REVIEW;
	}

	private static function matches( array $row, array $signals ) {
		foreach ( $row['all_of'] as $signal ) if ( ! in_array( $signal, $signals, true ) ) return false;
		if ( ! empty( $row['any_of'] ) ) {
			$found = false;
			foreach ( $row['any_of'] as $signal ) if ( in_array( $signal, $signals, true ) ) { $found = true; break; }
			if ( ! $found ) return false;
		}
		foreach ( $row['none_of'] as $signal ) if ( in_array( $signal, $signals, true ) ) return false;
		return ! empty( $row['all_of'] ) || ! empty( $row['any_of'] );
	}

	private static function signals( $values ) {
		if ( ! is_array( $values ) ) $values = array( $values );
		$out = array();
		foreach ( $values as $value ) {
			$key = self::key( $value );
			if ( '' !== $key ) $out[] = $key;
		}
		return array_values( array_unique( $out ) );
	}

	private static function key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_replace( '/[^a-z0-9_\-]/', '', $value );
	}
}
