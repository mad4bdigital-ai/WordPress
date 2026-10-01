<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical projection helpers for runtime truth.
 *
 * Source classes own facts. Composite/status classes may only project those
 * facts; they must not independently re-derive ready/verified/fresh state.
 */
final class MAD4B_SCP_Truth_Projection {
	const CONTRACT = 'mad4b.truth-projection.v1';

	public static function tri_state( $source, $key ) {
		if ( ! is_array( $source ) || ! array_key_exists( $key, $source ) ) return null;
		if ( null === $source[ $key ] ) return null;
		return (bool) $source[ $key ];
	}

	public static function mcp_registration_identity( array $fact ) {
		$actual_registered = ! empty( $fact['actual_registered'] );
		$expected = ! empty( $fact['expected_server'] );
		$observed_error = isset( $fact['observed_registration_error'] ) ? sanitize_key( (string) $fact['observed_registration_error'] ) : '';
		$synthetic_deferred_error = in_array( $observed_error, array( '', 'not_registered', 'mcp_chatgpt_not_registered' ), true );
		$bridge_ready = ! empty( $fact['bridge_booted'] )
			&& ! empty( $fact['server_hook_bound'] )
			&& ! empty( $fact['core_ability_hook_bound'] )
			&& ! empty( $fact['registry_ability_hook_bound'] )
			&& ! empty( $fact['core_category_hook_bound'] )
			&& ! empty( $fact['registry_category_hook_bound'] );
		$identity_ready = $actual_registered || ( $expected && $bridge_ready && $synthetic_deferred_error );
		$blocking_error = ( ! $actual_registered && ! $synthetic_deferred_error ) ? $observed_error : '';

		return array_merge( $fact, array(
			'projection_contract' => self::CONTRACT,
			'actual_registered' => (bool) $actual_registered,
			'identity_ready' => (bool) $identity_ready,
			'state' => $actual_registered ? 'registered' : ( '' !== $blocking_error ? 'registration_error' : ( $identity_ready ? 'deferred_identity_ready' : 'not_ready' ) ),
			'blocking_registration_error' => $blocking_error,
			'deep_registration_deferred' => ! $actual_registered && $identity_ready,
			'bridge_ready' => (bool) $bridge_ready,
		) );
	}

	public static function candidate_identity_bound_ready( array $persisted, array $current, $mismatch_blocker = 'candidate_identity_unproven', $historical_state = 'historical_evidence' ) {
		$recorded_ready = ! empty( $persisted['ready'] );
		$recorded_sha = isset( $persisted['source_commit_sha'] ) ? strtolower( trim( (string) $persisted['source_commit_sha'] ) ) : '';
		$recorded_fingerprint = isset( $persisted['build_fingerprint'] ) ? strtolower( trim( (string) $persisted['build_fingerprint'] ) ) : '';
		$recorded_manifest = isset( $persisted['package_manifest_digest'] ) ? strtolower( trim( (string) $persisted['package_manifest_digest'] ) ) : '';
		$recorded_artifact = isset( $persisted['artifact_identity'] ) ? trim( (string) $persisted['artifact_identity'] ) : '';
		$current_sha = isset( $current['source_commit_sha'] ) ? strtolower( trim( (string) $current['source_commit_sha'] ) ) : '';
		$current_fingerprint = isset( $current['build_fingerprint'] ) ? strtolower( trim( (string) $current['build_fingerprint'] ) ) : '';
		$current_manifest = isset( $current['package_manifest_digest'] ) ? strtolower( trim( (string) $current['package_manifest_digest'] ) ) : '';
		$current_artifact = isset( $current['artifact_identity'] ) ? trim( (string) $current['artifact_identity'] ) : '';
		$candidate_match = 1 === preg_match( '/^[a-f0-9]{40}$/', $recorded_sha )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $recorded_fingerprint )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $recorded_manifest )
			&& '' !== $recorded_artifact && strlen( $recorded_artifact ) <= 191
			&& 1 === preg_match( '/^[a-f0-9]{40}$/', $current_sha )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $current_fingerprint )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $current_manifest )
			&& '' !== $current_artifact && strlen( $current_artifact ) <= 191
			&& hash_equals( $recorded_sha, $current_sha )
			&& hash_equals( $recorded_fingerprint, $current_fingerprint )
			&& hash_equals( $recorded_manifest, $current_manifest )
			&& hash_equals( $recorded_artifact, $current_artifact );
		$effective_ready = $recorded_ready && $candidate_match;
		$blockers = isset( $persisted['blockers'] ) && is_array( $persisted['blockers'] ) ? array_values( array_unique( array_map( 'strval', $persisted['blockers'] ) ) ) : array();
		if ( $recorded_ready && ! $candidate_match ) $blockers[] = sanitize_key( (string) $mismatch_blocker );
		$blockers = array_values( array_unique( array_filter( array_map( 'sanitize_key', $blockers ) ) ) );
		$state = isset( $persisted['state'] ) ? sanitize_key( (string) $persisted['state'] ) : 'not_ready';
		if ( $recorded_ready && ! $candidate_match ) $state = sanitize_key( (string) $historical_state );
		elseif ( $effective_ready ) $state = 'ready';
		return array(
			'projection_contract' => self::CONTRACT,
			'recorded_ready' => $recorded_ready,
			'recorded_source_commit_sha' => $recorded_sha,
			'recorded_build_fingerprint' => $recorded_fingerprint,
			'recorded_package_manifest_digest' => $recorded_manifest,
			'recorded_artifact_identity' => $recorded_artifact,
			'current_source_commit_sha' => $current_sha,
			'current_build_fingerprint' => $current_fingerprint,
			'current_package_manifest_digest' => $current_manifest,
			'current_artifact_identity' => $current_artifact,
			'current_candidate_match' => $candidate_match,
			'effective_ready' => $effective_ready,
			'state' => $state,
			'blockers' => $blockers,
		);
	}

	public static function governed_write_grant_snapshot( array $fact ) {
		$persisted_ready = ! empty( $fact['persisted_ready'] );
		$eligible = ! empty( $fact['eligible'] );
		$agent_present = ! empty( $fact['agent_present'] );
		$write_tool_count = isset( $fact['write_tool_count'] ) ? max( 0, (int) $fact['write_tool_count'] ) : 0;
		$exact_existing = isset( $fact['exact_grants_existing'] ) ? max( 0, (int) $fact['exact_grants_existing'] ) : 0;
		$blockers = array();

		if ( ! $persisted_ready ) $blockers[] = 'persisted_write_authority_not_ready';
		if ( ! $eligible ) $blockers[] = 'write_authority_not_eligible';
		if ( ! $agent_present ) $blockers[] = 'governed_write_agent_missing';
		if ( $write_tool_count < 1 ) $blockers[] = 'write_inventory_empty';
		if ( $write_tool_count > 0 && $exact_existing !== $write_tool_count ) $blockers[] = 'exact_write_grants_incomplete';

		$zero_drift = array(
			'exact_grants_missing_count' => 'exact_write_grants_missing',
			'stale_allow_grants_count' => 'stale_write_grants_present',
			'unreviewed_stale_allow_grants_count' => 'unreviewed_stale_write_authority',
			'broad_environment_grants_count' => 'broad_environment_grants_present',
			'duplicate_exact_allow_grants_count' => 'duplicate_exact_grants_present',
			'current_agent_wildcard_grants' => 'current_agent_wildcard_grants',
			'global_registry_wildcard_grants' => 'global_registry_wildcard_grants',
		);
		foreach ( $zero_drift as $key => $blocker ) {
			if ( ! array_key_exists( $key, $fact ) || 0 !== (int) $fact[ $key ] ) $blockers[] = $blocker;
		}
		if ( ! empty( $fact['breakglass_included'] ) ) $blockers[] = 'breakglass_leak';

		$blockers = array_values( array_unique( array_map( 'sanitize_key', $blockers ) ) );
		return array(
			'projection_contract' => self::CONTRACT,
			'persisted_ready' => $persisted_ready,
			'current_ready' => empty( $blockers ),
			'state' => empty( $blockers ) ? 'ready' : ( $eligible ? 'blocked' : 'ineligible' ),
			'blockers' => $blockers,
		);
	}

	public static function candidate_binding_bound_ready( array $persisted, array $binding, $mismatch_blocker = 'runtime_authority_candidate_not_reconciled' ) {
		$persisted_ready = ! empty( $persisted['ready'] );
		$binding_required = ! empty( $binding['required'] );
		$binding_match = ! empty( $binding['match'] );
		$effective_ready = $persisted_ready && ( ! $binding_required || $binding_match );
		$blockers = isset( $persisted['blockers'] ) && is_array( $persisted['blockers'] ) ? array_values( array_unique( array_map( 'strval', $persisted['blockers'] ) ) ) : array();
		$blocker = isset( $persisted['blocker'] ) ? sanitize_key( (string) $persisted['blocker'] ) : '';
		if ( $binding_required && ! $binding_match ) {
			$blocker = sanitize_key( (string) $mismatch_blocker );
			$blockers[] = $blocker;
		}
		$blockers = array_values( array_unique( array_filter( array_map( 'sanitize_key', $blockers ) ) ) );
		$eligible = ! empty( $persisted['eligible'] );
		return array(
			'projection_contract' => self::CONTRACT,
			'persisted_ready' => $persisted_ready,
			'candidate_binding_required' => $binding_required,
			'candidate_binding_match' => $binding_match,
			'effective_ready' => $effective_ready,
			'state' => $effective_ready ? 'ready' : ( $eligible ? 'blocked' : 'ineligible' ),
			'blocker' => $effective_ready ? '' : $blocker,
			'blockers' => $blockers,
			'current_source_commit_sha' => isset( $binding['current_source_commit_sha'] ) ? strtolower( (string) $binding['current_source_commit_sha'] ) : '',
			'candidate_source_commit_sha' => isset( $binding['stored_source_commit_sha'] ) ? strtolower( (string) $binding['stored_source_commit_sha'] ) : ( isset( $persisted['source_commit_sha'] ) ? strtolower( (string) $persisted['source_commit_sha'] ) : '' ),
		);
	}

	public static function session_connection_identity( array $fact ) {
		$blockers = array();
		if ( empty( $fact['profile_configured'] ) ) $blockers[] = 'site_profile_unconfigured';
		if ( empty( $fact['profile_environment_match'] ) ) $blockers[] = 'site_profile_environment_drift';
		if ( empty( $fact['profile_origin_match'] ) ) $blockers[] = 'site_profile_origin_drift';
		if ( empty( $fact['adapter_available'] ) ) $blockers[] = 'mcp_adapter_unavailable';
		if ( ! empty( $fact['adapter_available'] ) && empty( $fact['adapter_identity_ok'] ) ) $blockers[] = 'mcp_adapter_identity_not_certified';
		// Session-safe diagnostics execute through the real MCP transport. Unlike
		// passive admin projections, actual registration is mandatory here.
		if ( empty( $fact['chatgpt_actual_registered'] ) ) $blockers[] = 'mcp_chatgpt_not_registered';
		$blockers = array_values( array_unique( array_map( 'sanitize_key', $blockers ) ) );
		return array(
			'projection_contract' => self::CONTRACT,
			'ready' => empty( $blockers ),
			'state' => empty( $blockers ) ? 'ready_identity' : 'blocked_identity',
			'blockers' => $blockers,
		);
	}

	public static function session_reconnect_identity( array $fact ) {
		$blockers = array();
		if ( empty( $fact['profile_configured'] ) ) $blockers[] = 'site_profile_unconfigured';
		if ( empty( $fact['profile_origin_match'] ) ) $blockers[] = 'site_profile_origin_drift';
		if ( empty( $fact['profile_environment_match'] ) ) $blockers[] = 'site_profile_environment_drift';
		if ( empty( $fact['chatgpt_actual_registered'] ) ) $blockers[] = 'mcp_chatgpt_not_registered';
		$blockers = array_values( array_unique( array_map( 'sanitize_key', $blockers ) ) );
		return array(
			'projection_contract' => self::CONTRACT,
			'ready' => empty( $blockers ),
			'state' => empty( $blockers ) ? 'ready_identity' : 'blocked_identity',
			'blockers' => $blockers,
		);
	}

	public static function canonical_external_wpml_receipt() {
		if ( class_exists( 'MAD4B_SCP_External_WPML_Acceptance_Finalizer' )
			&& method_exists( 'MAD4B_SCP_External_WPML_Acceptance_Finalizer', 'external_wpml_receipt_status' ) ) {
			$receipt = MAD4B_SCP_External_WPML_Acceptance_Finalizer::external_wpml_receipt_status();
		} elseif ( class_exists( 'MAD4B_SCP_WPML_Response_Contract' )
			&& method_exists( 'MAD4B_SCP_WPML_Response_Contract', 'receipt_status' ) ) {
			$receipt = MAD4B_SCP_WPML_Response_Contract::receipt_status();
		} else {
			$receipt = array();
		}
		if ( ! is_array( $receipt ) ) $receipt = array();

		$bounded = array();
		foreach ( array(
			'contract', 'authority_contract', 'observed', 'verified', 'success',
			'success_semantics', 'stale', 'state', 'route_registered',
			'response_status', 'classification', 'status', 'get_parameters',
			'observed_at', 'build_fingerprint', 'current_build_fingerprint',
		) as $key ) {
			if ( array_key_exists( $key, $receipt ) ) $bounded[ $key ] = $receipt[ $key ];
		}
		if ( ! array_key_exists( 'verified', $bounded ) ) $bounded['verified'] = false;
		if ( ! array_key_exists( 'stale', $bounded ) ) $bounded['stale'] = true;
		if ( ! isset( $bounded['state'] ) ) $bounded['state'] = 'pending_external_evidence';
		return $bounded;
	}

	public static function external_wpml( $required = true ) {
		$required = (bool) $required;
		$receipt = self::canonical_external_wpml_receipt();
		$verified = ! $required || ( ! empty( $receipt['verified'] ) && empty( $receipt['stale'] ) );
		return array(
			'contract' => self::CONTRACT,
			'required' => $required,
			'verified' => $verified,
			'state' => ! $required ? 'not_required' : ( $verified ? 'verified' : 'pending' ),
			'source_contract' => isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '',
			'receipt' => $receipt,
		);
	}

	public static function gate_effective_ready( array $gate ) {
		$freshness_required = array_key_exists( 'freshness_required', $gate )
			? ! empty( $gate['freshness_required'] )
			: array_key_exists( 'fresh', $gate );
		return ! empty( $gate['ready'] )
			&& ( ! $freshness_required || ! empty( $gate['fresh'] ) );
	}

	public static function gate( $ready, $state, $fresh, $contract, array $blockers = array(), $observed_at = '', $freshness_required = true ) {
		$gate = array(
			'state' => sanitize_key( (string) $state ),
			'ready' => (bool) $ready,
			'fresh' => (bool) $fresh,
			'freshness_required' => (bool) $freshness_required,
			'source_contract' => (string) $contract,
			'blockers' => array_values( array_unique( array_filter( array_map( 'strval', $blockers ) ) ) ),
			'observed_at' => '' !== (string) $observed_at ? (string) $observed_at : gmdate( 'c' ),
		);
		$gate['effective_ready'] = self::gate_effective_ready( $gate );
		return $gate;
	}
}
