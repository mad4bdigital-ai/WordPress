<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only provider operational-health projection.
 *
 * Compatibility/certification truth remains owned by Provider Contracts and
 * capability certification. Request-local Connector Resilience diagnostics may
 * degrade the displayed health for the current request only. This class never
 * writes circuit state, grants authority, retries mutations, or promotes a
 * provider/release ring.
 */
final class MAD4B_SCP_Provider_Health_View {
	const CONTRACT = 'mad4b.provider-health-view.v1';
	const HEALTHY = 'HEALTHY';
	const DEGRADED = 'DEGRADED';
	const BLOCKED = 'BLOCKED';
	const UNKNOWN = 'UNKNOWN';

	public static function provider( $provider, $available = null, array $diagnostic = array() ) {
		$provider = sanitize_key( (string) $provider );
		if ( '' === $provider ) return new WP_Error( 'mad4b_provider_health_provider_required', 'Provider identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) {
			return new WP_Error( 'mad4b_provider_health_contracts_unavailable', 'Provider contract evidence is unavailable.' );
		}
		$status = MAD4B_SCP_Provider_Contracts::runtime_status( $provider, $available );
		if ( ! is_array( $status ) ) return new WP_Error( 'mad4b_provider_health_status_invalid', 'Provider runtime status is invalid.' );
		$violations = MAD4B_SCP_Provider_Contracts::violations_for_status( $status );
		$capabilities = class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification', false ) && MAD4B_SCP_Provider_Compatibility_Certification::supports_provider( $provider )
			? MAD4B_SCP_Provider_Compatibility_Certification::capability_certification( array( 'provider_id' => $provider ) ) : array();
		return self::normalize( $status, $violations, $diagnostic, $capabilities );
	}

	/**
	 * Shared, non-authorizing capability-first read projection.
	 *
	 * Existing Provider Compatibility Certification remains the only source of
	 * capability evidence. A changed plugin version never promotes a write grant,
	 * and a missing/foreign/malformed certification never becomes a healthy read.
	 * This small pure projection can be used by every existing provider without
	 * an extra registry, provider version pin or runtime callback execution.
	 */
	public static function capability_first( $provider, array $runtime, array $evidence = array() ) {
		$provider = sanitize_key( (string) $provider );
		$drift = in_array( (string) ( $runtime['status'] ?? '' ), array( 'version_drift', 'component_drift', 'component_identity_drift' ), true );
		$result = array(
			'contract' => 'mad4b.capability-first-provider-health.v1',
			'provider_id' => $provider,
			'evidence_state' => 'unavailable',
			'read_state' => 'UNKNOWN',
			'runtime_version_drift' => $drift,
			'capabilities' => array(),
			'compatible_read_capabilities' => array(),
			'isolated_capabilities' => array(),
			'mutation_authorized' => false,
			'write_grants_created' => false,
			'owner_gate_bypassed' => false,
			'capability_presence_authorizes' => false,
			'provider_version_pin_required_for_read_observation' => false,
			'governed_dispatch_required_for_mutation' => true,
			'read_only' => true,
			'authorizing' => false,
		);
		// Empty provider, unsupported contract, foreign evidence or excessive
		// inventory: fail soft to UNKNOWN for observation and fail closed for write.
		if ( '' === $provider || empty( $evidence ) ) return $result;
		$valid = ( $evidence['contract'] ?? '' ) === 'mad4b.provider-capability-certification-result.v1'
			&& ( $evidence['provider_id'] ?? '' ) === $provider
			&& isset( $evidence['capabilities'] ) && is_array( $evidence['capabilities'] )
			&& count( $evidence['capabilities'] ) <= 64;
		if ( ! $valid ) {
			$result['evidence_state'] = 'invalid_or_foreign';
			return $result;
		}
		$result['evidence_state'] = 'observed';
		$partial = false;
		foreach ( $evidence['capabilities'] as $id => $row ) {
			if ( ! is_string( $id ) || ! preg_match( '~^[a-zA-Z0-9_.\/-]{1,128}$~D', $id )
				|| ! is_array( $row )
				|| ! isset( $row['surface_exposed'], $row['structural_compatible'] )
				|| ! is_bool( $row['surface_exposed'] ) || ! is_bool( $row['structural_compatible'] ) ) {
				// Do not silently discard malformed members and claim full health.
				$result['evidence_state'] = 'partial_invalid';
				$partial = true;
				continue;
			}
			$exposed = $row['surface_exposed'];
			$structural = $row['structural_compatible'];
			$read = $exposed && $structural && true === ( $row['read_eligible'] ?? false );
			$write_claim = $exposed && $structural && true === ( $row['write_eligible'] ?? false );
			$state = ! $exposed ? 'NOT_EXPOSED'
				: ( ! $structural ? 'ISOLATED'
					: ( $read ? 'READ_COMPATIBLE'
						: ( $write_claim ? 'WRITE_CANDIDATE_UNAUTHORIZED' : 'UNKNOWN' ) ) );
			$result['capabilities'][ $id ] = array(
				'state' => $state,
				'read_compatibility_observed' => $read,
				'write_certification_claim_observed' => $write_claim,
				'mutation_authorized' => false,
				'authorization_rechecked' => false,
				'read_permission_rechecked' => false,
				'version_drift_requires_reobservation' => $drift,
			);
			if ( $read ) $result['compatible_read_capabilities'][] = $id;
			elseif ( $exposed ) $result['isolated_capabilities'][] = $id;
			if ( ! $exposed || ! $structural ) $partial = true;
		}
		$result['read_state'] = $result['compatible_read_capabilities']
			? ( $partial ? 'PARTIAL' : 'OBSERVED_COMPATIBLE' ) : 'UNKNOWN';
		if ( 'partial_invalid' === $result['evidence_state'] ) $result['read_state'] = 'PARTIAL_UNVERIFIED';
		return $result;
	}

	public static function normalize( array $status, array $violations = array(), array $diagnostic = array(), array $capability_evidence = array() ) {
		$provider = isset( $status['provider'] ) ? sanitize_key( (string) $status['provider'] ) : '';
		$runtime_status = isset( $status['status'] ) ? sanitize_key( (string) $status['status'] ) : '';
		$violations = array_values( array_unique( array_filter( array_map( 'sanitize_key', $violations ) ) ) );
		$runtime_ok = ! empty( $status['runtime_contract_ok'] ) && 'certified' === $runtime_status && empty( $violations );

		$health = self::UNKNOWN;
		$reason = 'provider_runtime_status_unknown';
		$recommended_action = 'inspect_provider_runtime';
		$confidence = 'conservative';

		if ( $runtime_ok ) {
			$health = self::HEALTHY;
			$reason = 'provider_runtime_contract_certified';
			$recommended_action = 'none';
			$confidence = 'exact_contract';
		} elseif ( in_array( $runtime_status, array( 'version_drift', 'component_drift' ), true ) ) {
			$health = self::UNKNOWN;
			$reason = 'artifact_changed_contract_not_observed';
			$recommended_action = 'observe_capability_contracts';
		} elseif ( '' !== $runtime_status && (
			! empty( $violations )
			|| in_array( $runtime_status, array( 'unavailable', 'version_drift', 'component_drift', 'uncertified_provider' ), true )
		) ) {
			$health = self::BLOCKED;
			$reason = ! empty( $violations ) ? (string) reset( $violations ) : $runtime_status;
			$recommended_action = 'recertify_or_restore_provider_before_mutation';
			$confidence = 'exact_contract';
		}
		$read_eligible = array(); $write_eligible = array(); $isolated = array();
		$capability_scope = 'mad4b.provider-capability-certification-result.v1' === ( $capability_evidence['contract'] ?? '' )
			&& $provider === ( $capability_evidence['provider_id'] ?? '' ) && ! empty( $capability_evidence['capabilities'] );
		if ( $capability_scope ) {
			foreach ( $capability_evidence['capabilities'] as $id => $row ) {
				if ( ! is_array( $row ) || empty( $row['surface_exposed'] ) ) continue;
				if ( ! empty( $row['structural_compatible'] ) && ! empty( $row['read_eligible'] ) ) $read_eligible[] = $id;
				elseif ( ! empty( $row['structural_compatible'] ) && ! empty( $row['write_eligible'] ) ) $write_eligible[] = $id;
				else $isolated[] = $id;
			}
			$health = $read_eligible || $write_eligible ? self::HEALTHY : self::BLOCKED;
			$reason = self::HEALTHY === $health ? 'capability_local_runtime_compatibility' : 'no_eligible_exposed_capability';
			$recommended_action = $isolated ? 'reconcile_only_isolated_capabilities' : 'none';
			$confidence = 'capability_contract';
		}

		$diagnostic_state = '';
		$session_breaker_open = false;
		$diagnostic_partial = false;
		if ( ! empty( $diagnostic ) ) {
			$diagnostic_contract = isset( $diagnostic['contract'] ) ? (string) $diagnostic['contract'] : '';
			$expected_contract = class_exists( 'MAD4B_SCP_Connector_Resilience' ) ? MAD4B_SCP_Connector_Resilience::CONTRACT : 'mad4b.connector-resilience.v2';
			if ( ! hash_equals( $expected_contract, $diagnostic_contract ) ) {
				return new WP_Error( 'mad4b_provider_health_diagnostic_contract_invalid', 'Provider diagnostic evidence contract is unsupported.' );
			}
			$diagnostic_state = isset( $diagnostic['state'] ) ? sanitize_key( (string) $diagnostic['state'] ) : '';
			$session_breaker_open = ! empty( $diagnostic['session_breaker_open'] );
			$diagnostic_partial = ! empty( $diagnostic['partial'] ) || 'partial' === $diagnostic_state;
			if ( self::HEALTHY === $health && ( $session_breaker_open || $diagnostic_partial ) ) {
				$health = self::DEGRADED;
				$reason = $session_breaker_open ? 'request_local_session_breaker_open' : 'request_local_provider_diagnostics_partial';
				$recommended_action = $session_breaker_open ? 'reconnect_then_recheck_provider' : 'recheck_provider_diagnostics';
				$confidence = 'request_local';
			}
		}

		if ( self::UNKNOWN === $health && ! in_array( $runtime_status, array( 'version_drift', 'component_drift' ), true ) ) {
			$recommended_action = 'inspect_provider_runtime_before_mutation';
		}

		return array(
			'contract' => self::CONTRACT,
			'provider' => $provider,
			'capability_first' => self::capability_first( $provider, $status, $capability_evidence ),
			'health_state' => $health,
			'runtime_status' => $runtime_status,
			'runtime_contract_mutation_eligible' => (bool) $runtime_ok && ( ! $capability_scope || empty( $isolated ) ),
			'capability_scope' => $capability_scope,
			'read_eligible_capabilities' => $read_eligible,
			'write_eligible_capabilities' => $write_eligible,
			'isolated_capabilities' => $isolated,
			'violations' => $violations,
			'request_diagnostic_state' => $diagnostic_state,
			'request_diagnostic_partial' => $diagnostic_partial,
			'request_local_session_breaker_open' => $session_breaker_open,
			'persistent_circuit_state_created' => false,
			'persistent_circuit_enforced' => false,
			'mutation_authorized' => false,
			'authority_effect' => 'none',
			'blind_retry_allowed' => false,
			'recommended_action' => $recommended_action,
			'reason' => sanitize_key( (string) $reason ),
			'confidence' => sanitize_key( (string) $confidence ),
			'read_only' => true,
			'mutation_performed' => false,
		);
	}
}
