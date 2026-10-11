<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pure, fail-closed recovery admission model. It does NOT execute abilities.
 * Existing Runtime Convergence, Post Update, provider certifiers and Host owners
 * remain the sole authority and effect owners.
 */
final class MAD4B_SCP_Recovery_Lifecycle {
	const CONTRACT = 'mad4b.recovery-lifecycle.v1';
	const MAX_ACTIONS = 64;
	const MAX_DEPENDENCIES = 16;

	/** Capture twice, before and after the deep Staging plan is assembled. */
	public static function capture_identity() {
		$profile = class_exists( 'MAD4B_SCP_Site_Profile', false ) ? MAD4B_SCP_Site_Profile::status() : array();
		// Runtime Convergence::current_identity() is intentionally private.
		// Reuse the established, public provenance observer and require its
		// validated identity instead of bypassing Runtime Convergence internals.
		$runtime = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer', false )
			? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status() : array();
		if ( empty( $runtime['identity_ready'] ) ) $runtime = array();
		$env = class_exists( 'MAD4B_SCP_Site_Profile', false )
			? MAD4B_SCP_Site_Profile::environment_resolution() : array();
		$profile = is_array( $profile ) ? $profile : array();
		$runtime = is_array( $runtime ) ? $runtime : array();
		$env = is_array( $env ) ? $env : array();
		// Cheap event-generation read detects a plugin/provider update racing
		// the plan, even when MAD4B's own Source HEAD did not change.
		$provider_event = get_option( 'mad4b_scp_adaptive_runtime_event_v1', array() );
		$provider_event = is_array( $provider_event ) ? $provider_event : array();
		$provider_generation_sha256 = hash( 'sha256', serialize( $provider_event ) );
		return array(
			'site_uuid' => isset( $profile['site_uuid'] ) ? (string) $profile['site_uuid'] : '',
			'profile_digest' => isset( $profile['profile_digest'] ) ? (string) $profile['profile_digest'] : '',
			'profile_revision' => isset( $profile['revision'] ) ? (int) $profile['revision'] : -1,
			'origin' => isset( $profile['current_origin'] ) ? (string) $profile['current_origin'] : '',
			'environment' => isset( $env['effective_environment'] ) ? (string) $env['effective_environment'] : '',
			'authority_ready' => ! empty( $profile['authority_ready'] ),
			'source_commit_sha' => isset( $runtime['source_commit_sha'] ) ? (string) $runtime['source_commit_sha'] : '',
			'build_fingerprint' => isset( $runtime['build_fingerprint'] ) ? (string) $runtime['build_fingerprint'] : '',
			'package_manifest_digest' => isset( $runtime['package_manifest_digest'] ) ? (string) $runtime['package_manifest_digest'] : '',
			'boot_provenance_sha256' => defined( 'MAD4B_SCP_BOOT_PROVENANCE_SHA256' ) ? (string) MAD4B_SCP_BOOT_PROVENANCE_SHA256 : '',
			'provider_event_generation_sha256' => $provider_generation_sha256,
		);
	}

	private static function identity_valid( $identity ) {
		if ( ! is_array( $identity ) ) return false;
		foreach ( array( 'site_uuid', 'profile_digest', 'origin', 'environment', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $key )
			if ( ! isset( $identity[ $key ] ) || ! is_string( $identity[ $key ] ) || '' === $identity[ $key ] ) return false;
		return ! empty( $identity['authority_ready'] )
			&& 'staging' === $identity['environment']
			&& isset( $identity['profile_revision'] ) && is_int( $identity['profile_revision'] ) && $identity['profile_revision'] >= 1
			&& 1 === preg_match( '/^[a-f0-9]{40}$/D', $identity['source_commit_sha'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $identity['profile_digest'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $identity['build_fingerprint'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $identity['package_manifest_digest'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', isset( $identity['provider_event_generation_sha256'] ) ? $identity['provider_event_generation_sha256'] : '' );
	}

	/**
	 * Caller supplies the exact identity snapshots taken around the plan.
	 * Never promotes plan actions into write privileges or creates a dispatcher.
	 */
	public static function compile( $plan, $start_identity, $end_identity ) {
		$valid_plan = is_array( $plan ) && 'mad4b.staging-convergence-plan.v1' === ( $plan['contract'] ?? '' )
			&& true === ( $plan['read_only'] ?? false )
			&& false === ( $plan['mutation_performed'] ?? true )
			&& false === ( $plan['production_mutation_performed'] ?? true )
			&& isset( $plan['actions'] ) && is_array( $plan['actions'] )
			&& count( $plan['actions'] ) <= self::MAX_ACTIONS;
		$same_identity = is_array( $start_identity ) && is_array( $end_identity )
			&& $start_identity === $end_identity;
		$identity_ok = self::identity_valid( $start_identity ) && $same_identity;
		$reasons = array();
		if ( ! $valid_plan ) $reasons[] = 'plan_contract_invalid_or_oversized';
		if ( ! $same_identity ) $reasons[] = 'identity_changed_during_planning';
		if ( ! self::identity_valid( $start_identity ) ) $reasons[] = 'staging_identity_unverified';
		$nodes = array();
		$invalid = false;
		if ( $valid_plan ) {
			foreach ( $plan['actions'] as $raw ) {
				if ( ! is_array( $raw ) || ! is_string( $raw['action_id'] ?? null )
					|| 1 !== preg_match( '/^[a-z0-9_]{1,100}$/D', $raw['action_id'] )
					|| isset( $nodes[ $raw['action_id'] ] ) ) { $invalid = true; break; }
				$depends = $raw['depends_on'] ?? array();
				if ( ! is_array( $depends ) || count( $depends ) > self::MAX_DEPENDENCIES ) { $invalid = true; break; }
				foreach ( $depends as $dep ) if ( ! is_string( $dep ) || 1 !== preg_match( '/^[a-z0-9_]{1,100}$/D', $dep ) ) { $invalid = true; break; }
				if ( $invalid ) break;
				$nodes[ $raw['action_id'] ] = array(
					'id' => $raw['action_id'],
					'kind' => is_string( $raw['kind'] ?? null ) ? substr( $raw['kind'], 0, 80 ) : 'unknown',
					'executor' => is_string( $raw['executor'] ?? null ) ? substr( $raw['executor'], 0, 80 ) : 'unknown',
					'depends_on' => array_values( array_unique( $depends ) ),
					'owner_approval' => ! empty( $raw['human_decision_required'] ),
					'non_executable' => true,
					'plan_ability' => is_string( $raw['plan_ability'] ?? null ) ? substr( $raw['plan_ability'], 0, 160 ) : '',
					'readback_ability' => is_string( $raw['readback_ability'] ?? null ) ? substr( $raw['readback_ability'], 0, 160 ) : '',
					'provider_id' => is_string( $raw['provider_id'] ?? null ) ? substr( $raw['provider_id'], 0, 80 ) : '',
					'capability_id' => is_string( $raw['capability_id'] ?? null ) ? substr( $raw['capability_id'], 0, 100 ) : '',
					'ability' => is_string( $raw['ability'] ?? null ) ? substr( $raw['ability'], 0, 160 ) : '',
				);
			}
		}
		if ( $invalid ) $reasons[] = 'invalid_action_or_dependency_contract';
		// Validate the entire dependency graph, not merely each row.
		$visiting = array(); $visited = array(); $order = array();
		$visit = static function ( $id ) use ( &$visit, &$visiting, &$visited, &$order, &$nodes ) {
			if ( isset( $visiting[ $id ] ) || ! isset( $nodes[ $id ] ) ) return false;
			if ( isset( $visited[ $id ] ) ) return true;
			$visiting[ $id ] = true;
			foreach ( $nodes[ $id ]['depends_on'] as $dep ) if ( ! $visit( $dep ) ) return false;
			unset( $visiting[ $id ] );
			$visited[ $id ] = true; $order[] = $id;
			return true;
		};
		if ( $valid_plan && ! $invalid ) {
			foreach ( array_keys( $nodes ) as $id ) if ( ! $visit( $id ) ) { $reasons[] = 'dependency_missing_or_cyclic'; break; }
		}
		$admitted = $valid_plan && $identity_ok && empty( $reasons );
		$out = array();
		if ( $admitted ) {
			foreach ( $order as $id ) {
				$row = $nodes[ $id ];
				$row['stage'] = $row['owner_approval'] ? 'AWAIT_OWNER_REVIEW' : (
					in_array( $row['kind'], array( 'host_bootstrap_review', 'host_isolation_review', 'external_evidence', 'external_oauth_reauthorization', 'external_executor_job' ), true )
					? 'EXTERNAL_PREREQUISITE' : 'AWAIT_GOVERNED_EXECUTOR' );
				$row['authorization_granted'] = false;
				$row['verification_complete'] = false;
				$row['compensation_complete'] = false;
				$out[] = $row;
			}
		}
		$encoded = json_encode( $start_identity );
		return array(
			'contract' => self::CONTRACT,
			'state' => $admitted ? ( empty( $plan['blocking_gates'] ) && ! empty( $plan['current_ready'] ) && empty( $out ) ? 'OBSERVED_READY' : 'REVIEW_REQUIRED' )
				: ( ! $same_identity ? 'STALE' : 'BLOCKED' ),
			'identity_bound' => $admitted,
			'identity_sha256' => $admitted && is_string( $encoded ) ? hash( 'sha256', $encoded ) : '',
			'reasons' => array_values( array_unique( $reasons ) ),
			'ordered_actions' => $out,
			'action_count' => count( $out ),
			'authorizing' => false,
			'execution_performed' => false,
			'verification_performed' => false,
			'certification_issued' => false,
			'production_mutation_allowed' => false,
			'breakglass_allowed' => false,
		);
	}
}
