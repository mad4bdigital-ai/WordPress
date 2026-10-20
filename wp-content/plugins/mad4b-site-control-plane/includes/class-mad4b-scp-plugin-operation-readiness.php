<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Pure operation-level discovery. Never invokes callbacks or creates authority. */
final class MAD4B_SCP_Plugin_Operation_Readiness {
	const CONTRACT = 'mad4b.plugin-operation-readiness.v1';
	const MAX_OPERATIONS = 256;

	/** Reduce approved adapter status to bounded, non-secret capability evidence. */
	public static function evidence( array $status ) {
		$cert = isset( $status['capability_certification'] ) && is_array( $status['capability_certification'] ) ? $status['capability_certification'] : array();
		$caps = isset( $cert['capabilities'] ) && is_array( $cert['capabilities'] ) ? $cert['capabilities'] : array();
		$out = array();
		foreach ( array_slice( $caps, 0, self::MAX_OPERATIONS, true ) as $id => $cap ) {
			if ( ! is_array( $cap ) ) continue;
			$names = isset( $cap['abilities'] ) && is_array( $cap['abilities'] ) ? $cap['abilities'] : array();
			$behavior = isset( $cap['behavioral_evidence'] ) && is_array( $cap['behavioral_evidence'] ) ? $cap['behavioral_evidence'] : array();
			foreach ( array_slice( $names, 0, self::MAX_OPERATIONS ) as $name ) {
				if ( ! self::valid_name( $name ) ) continue;
				if ( ! isset( $out[ $name ] ) && count( $out ) >= self::MAX_OPERATIONS ) break;
				// Conflicting contracts for one operation are not a certification.
				if ( isset( $out[ $name ] ) ) { $out[ $name ] = array( 'ambiguous' => true ); continue; }
				$out[ $name ] = array(
					'capability_id' => is_string( $id ) && preg_match( '/^[a-zA-Z0-9._-]{1,191}$/D', $id ) ? $id : '',
					'contract_digest' => self::digest( $cap['capability_contract_digest'] ?? '' ),
					'artifact_fingerprint' => self::digest( $behavior['artifact_fingerprint'] ?? '' ),
					'read_eligible' => true === ( $cap['read_eligible'] ?? false ),
					'write_eligible' => true === ( $cap['write_eligible'] ?? false ),
					'behavior_verified' => true === ( $behavior['behavioral_verified'] ?? false ),
					'rollback_verified' => true === ( $behavior['rollback_verified'] ?? false ),
					'artifact_bound' => true === ( $cap['artifact_authority_bound'] ?? false ),
					'artifact_required' => true === ( $cap['artifact_authority_required'] ?? false ),
				);
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	public static function project( array $plugin, array $registered, $registry_complete, $dependency_gap = false ) {
		$reads = self::names( $plugin['adapter_read_abilities'] ?? array() );
		$writes = self::names( $plugin['adapter_write_abilities'] ?? array() );
		$evidence = isset( $plugin['operation_capabilities'] ) && is_array( $plugin['operation_capabilities'] ) ? $plugin['operation_capabilities'] : array();
		$rollback = isset( $plugin['adapter_reversible_contracts'] ) && is_array( $plugin['adapter_reversible_contracts'] ) ? $plugin['adapter_reversible_contracts'] : array();
		$all = array_values( array_unique( array_merge( $reads, $writes ) ) );
		sort( $all, SORT_STRING );
		$truncated = count( $all ) > self::MAX_OPERATIONS
			|| (int) ( $plugin['adapter_read_ability_count'] ?? count( $reads ) ) > count( $reads )
			|| (int) ( $plugin['adapter_write_ability_count'] ?? count( $writes ) ) > count( $writes );
		$rows = array(); $counts = array( 'read_contract_available' => 0, 'write_contract_available' => 0, 'verification_required' => 0, 'blocked' => 0 );
		$base = array();
		if ( ! $registry_complete ) $base[] = 'ability_registry_incomplete';
		if ( 'standard' !== ( $plugin['package_kind'] ?? 'standard' ) ) $base[] = 'inventory_only_package';
		if ( empty( $plugin['active'] ) ) $base[] = 'plugin_inactive';
		if ( empty( $plugin['adapter_registered'] ) ) $base[] = 'reviewed_adapter_missing';
		if ( empty( $plugin['adapter_runtime_available'] ) ) $base[] = 'adapter_runtime_unavailable';
		if ( $dependency_gap ) $base[] = 'active_dependency_missing';
		if ( ! empty( $plugin['side_channel_blocker'] ) ) $base[] = 'unreviewed_parallel_transport';
		if ( 'excluded_high_risk' === ( $plugin['coverage_state'] ?? '' ) ) $base[] = 'provider_excluded_by_policy';
		foreach ( array_slice( $all, 0, self::MAX_OPERATIONS ) as $name ) {
			$is_read = in_array( $name, $reads, true );
			$conflict = $is_read && in_array( $name, $writes, true );
			$cap = isset( $evidence[ $name ] ) && is_array( $evidence[ $name ] ) ? $evidence[ $name ] : array();
			$blockers = $base;
			if ( ! isset( $registered[ $name ] ) ) $blockers[] = 'ability_not_registered';
			if ( $conflict || ! empty( $cap['ambiguous'] ) ) $blockers[] = 'ambiguous_operation_contract';
			if ( ! $cap ) $blockers[] = 'capability_evidence_unavailable';
			if ( $cap && true !== ( $cap[ $is_read ? 'read_eligible' : 'write_eligible' ] ?? false ) ) $blockers[] = $is_read ? 'read_capability_not_eligible' : 'write_capability_not_eligible';
			if ( ! $is_read && true === ( $cap['artifact_required'] ?? false ) && true !== ( $cap['artifact_bound'] ?? false ) ) $blockers[] = 'exact_artifact_evidence_required';
			$contract = isset( $rollback[ $name ] ) && is_string( $rollback[ $name ] ) && preg_match( '/^mad4b\.rollback\.[a-z0-9._-]+\.v[0-9]+$/D', $rollback[ $name ] ) ? $rollback[ $name ] : '';
			$state = $blockers ? 'blocked' : ( $is_read ? 'read_contract_available' : 'write_contract_available' );
			if ( $is_read && array( 'capability_evidence_unavailable' ) === $blockers ) $state = 'verification_required';
			++$counts[ $state ];
			$rows[] = array(
				'ability' => $name, 'dimension' => $is_read ? 'read' : 'write',
				'state' => $state, 'registered' => isset( $registered[ $name ] ),
				'contract_digest' => $cap['contract_digest'] ?? '',
				'artifact_fingerprint' => $cap['artifact_fingerprint'] ?? '',
				'blockers' => array_values( array_unique( $blockers ) ),
				'next_action' => $blockers ? self::next_action( $blockers ) : ( $is_read ? 'prepare_governed_read' : 'prepare_exact_governed_write' ),
				'dispatcher' => $is_read ? 'mad4b/read-execute' : 'mad4b/write-execute',
				'verification' => array(
					'contract_state' => $is_read ? 'read_result_requires_validation' : 'independent_target_readback_required',
					'candidate_read_abilities' => array_slice( array_values( array_intersect( $reads, array_keys( $registered ) ) ), 0, 8 ),
					'exact_reader_binding_verified' => false,
					'behavior_verified' => true === ( $cap['behavior_verified'] ?? false ),
					'rollback_contract' => $contract,
					'rollback_verified' => true === ( $cap['rollback_verified'] ?? false ),
				),
				'authorization_revalidation_required' => true,
				'execution_allowed_by_discovery' => false, 'blind_retry_allowed' => false,
			);
		}
		$result = array(
			'contract' => self::CONTRACT, 'complete' => (bool) $registry_complete && ! $truncated,
			'truncated' => $truncated, 'counts' => $counts, 'operations' => $rows,
			'integration_action' => empty( $plugin['adapter_registered'] ) ? 'review_and_implement_missing_adapter' : ( empty( $all ) ? 'define_reviewed_operation_contracts' : 'inspect_per_operation_gates' ),
			'automatic_refresh' => 'recompute_on_discovery_from_current_plugin_registry_and_evidence',
			'non_authorizing' => true, 'mutation_performed' => false,
			'site_acceptance_evaluated' => false, 'production_authorized' => false,
		);
		$identity = array( $plugin['plugin_file'] ?? '', $plugin['version'] ?? '', $result );
		$json = wp_json_encode( $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$result['evidence_sha256'] = is_string( $json ) ? hash( 'sha256', $json ) : '';
		if ( ! is_string( $json ) ) {
			$result['complete'] = false;
			$result['integration_action'] = 'repair_operation_evidence_encoding';
		}
		return $result;
	}

	private static function next_action( array $blockers ) {
		if ( in_array( 'ability_registry_incomplete', $blockers, true ) ) return 'refresh_complete_runtime_discovery';
		if ( in_array( 'reviewed_adapter_missing', $blockers, true ) ) return 'review_and_implement_missing_adapter';
		if ( in_array( 'plugin_inactive', $blockers, true ) ) return 'wait_for_explicit_plugin_activation';
		if ( in_array( 'exact_artifact_evidence_required', $blockers, true ) ) return 'verify_exact_provider_artifact';
		return 'inspect_operation_contract_and_certification';
	}

	private static function digest( $value ) {
		return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) ? $value : '';
	}

	private static function names( $values ) {
		$values = is_array( $values ) ? array_values( array_filter( $values, array( __CLASS__, 'valid_name' ) ) ) : array();
		$values = array_values( array_unique( $values ) ); sort( $values, SORT_STRING );
		return $values;
	}
	public static function valid_name( $name ) {
		return is_string( $name ) && 1 === preg_match( '~^[a-z0-9_-]{1,64}/[a-z0-9._/-]{1,191}$~D', $name );
	}
}
