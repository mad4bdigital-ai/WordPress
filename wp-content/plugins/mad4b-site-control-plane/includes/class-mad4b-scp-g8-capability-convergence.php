<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only capability-local convergence. Reuses the existing provider
 * certification truth; an artifact hash never substitutes for behavioral
 * recertification, reversible canary or governed permission.
 */
final class MAD4B_SCP_G8_Capability_Convergence {
	const CONTRACT = 'mad4b.g8-capability-convergence.v1';
	const MAX_CAPABILITIES = 128;

	private static function identifier( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9_.-]{1,80}$/D', $value );
	}

	/** Read a current source-of-truth snapshot; no provider callback is executed. */
	public static function observe( $provider ) {
		if ( ! self::identifier( $provider ) || ! class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification', false ) )
			return new WP_Error( 'mad4b_g8_convergence_provider_invalid', 'Registered provider inspection required.' );
		// Identity admission must precede provider inspection, not follow it.
		if ( ! class_exists( 'MAD4B_SCP_G8_Record', false ) || ! MAD4B_SCP_G8_Record::staging() )
			return new WP_Error( 'mad4b_g8_convergence_staging_required', 'Enrolled Staging required for exact scope observation.' );
		$binding = MAD4B_SCP_G8_Record::binding();
		$profile = MAD4B_SCP_G8_Record::profile();
		if ( is_wp_error( $binding ) || ! is_array( $binding )
			|| ! is_string( $profile ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $profile ) )
			return new WP_Error( 'mad4b_g8_convergence_site_binding_unavailable', 'Restore epoch and exact Site Profile are required.' );
		$assessment = MAD4B_SCP_Provider_Compatibility_Certification::assess_provider( $provider );
		if ( is_wp_error( $assessment ) ) return $assessment;
		if ( ! is_array( $assessment ) || ! is_array( $assessment['capabilities'] ?? null )
			|| count( $assessment['capabilities'] ) > self::MAX_CAPABILITIES )
			return new WP_Error( 'mad4b_g8_convergence_assessment_invalid', 'Bounded current provider assessment required.' );
		$fingerprint = $assessment['artifact']['runtime_artifact_fingerprint'] ?? '';
		if ( ! is_string( $fingerprint ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) )
			return new WP_Error( 'mad4b_g8_convergence_artifact_unverified', 'Runtime artifact identity unavailable.' );
		$capabilities = array();
		foreach ( $assessment['capabilities'] as $id => $row ) {
			if ( ! self::identifier( $id ) || ! is_array( $row ) )
				return new WP_Error( 'mad4b_g8_convergence_capability_invalid', 'Capability scope invalid.' );
			$behavior = is_array( $row['behavioral_evidence'] ?? null ) ? $row['behavioral_evidence'] : array();
			$contract_hash = $row['capability_contract_digest'] ?? '';
			if ( ! is_string( $contract_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $contract_hash ) )
				return new WP_Error( 'mad4b_g8_convergence_contract_unverified', 'Capability contract digest unavailable.' );
			$capabilities[ $id ] = array(
				'contract_sha256' => $contract_hash,
				'structural_compatible' => true === ( $row['structural_compatible'] ?? false ),
				'behavioral_verified' => true === ( $behavior['behavioral_verified'] ?? false ),
				'rollback_verified' => true === ( $behavior['rollback_verified'] ?? false ),
				'read_eligible' => true === ( $row['read_eligible'] ?? false ),
				'write_eligible' => true === ( $row['write_eligible'] ?? false ),
				'certification_level' => is_string( $row['certification_level'] ?? null ) ? substr( $row['certification_level'], 0, 50 ) : 'UNKNOWN',
			);
		}
		ksort( $capabilities, SORT_STRING );
		return array( 'contract' => self::CONTRACT, 'site_profile_sha256' => $profile,
			'restore_binding' => $binding, 'provider' => $provider,
			'artifact_sha256' => $fingerprint, 'capabilities' => $capabilities,
			'candidate_promotion_allowed' => false, 'mutation_performed' => false, 'authorizing' => false );
	}

	/** A pure report; only impacted capability rows are quarantined. */
	public static function diff( array $old, array $new ) {
		if ( ! self::valid_snapshot( $old ) || ! self::valid_snapshot( $new ) || $old['provider'] !== $new['provider']
			|| ! hash_equals( $old['site_profile_sha256'], $new['site_profile_sha256'] )
			|| $old['restore_binding'] !== $new['restore_binding'] )
			return new WP_Error( 'mad4b_g8_convergence_snapshot_invalid', 'Same-provider bounded snapshots required.' );
		$identity_drift = ! hash_equals( $old['artifact_sha256'], $new['artifact_sha256'] );
		$before = $old['capabilities']; $after = $new['capabilities'];
		$names = array_values( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) );
		sort( $names, SORT_STRING ); $changed = array(); $preserved = array();
		foreach ( $names as $id ) {
			$a = $before[ $id ] ?? null; $b = $after[ $id ] ?? null;
			if ( null === $a ) $state = 'NEW_UNCERTIFIED';
			elseif ( null === $b ) $state = 'CAPABILITY_REMOVED';
			elseif ( ! hash_equals( $a['contract_sha256'], $b['contract_sha256'] ) || $a['structural_compatible'] !== $b['structural_compatible'] )
				$state = 'STRUCTURE_CHANGED';
			elseif ( ( ! $a['write_eligible'] && $b['write_eligible'] ) || ( ! $a['read_eligible'] && $b['read_eligible'] ) )
				$state = 'ELIGIBILITY_EXPANSION_REQUIRES_GOVERNED_REVIEW';
			elseif ( ! $b['behavioral_verified'] && $a['behavioral_verified'] || ! $b['rollback_verified'] && $a['rollback_verified'] )
				$state = 'BEHAVIOR_RECHECK_REQUIRED';
			elseif ( $a['read_eligible'] && ! $b['read_eligible'] ) $state = 'READ_FENCED';
			elseif ( ! $b['write_eligible'] && $a['write_eligible'] ) $state = 'WRITE_FENCED';
			elseif ( $a['certification_level'] !== $b['certification_level']
				|| $a['behavioral_verified'] !== $b['behavioral_verified']
				|| $a['rollback_verified'] !== $b['rollback_verified'] )
				$state = 'CERTIFICATION_EVIDENCE_CHANGED';
			elseif ( $identity_drift && ( $a['write_eligible'] || $b['write_eligible'] ) ) $state = 'IDENTITY_CHANGED_RECHECK_REQUIRED';
			elseif ( ! $b['read_eligible'] && ! $b['write_eligible'] ) $state = 'INELIGIBLE_CAPABILITY';
			elseif ( ! $b['structural_compatible'] ) $state = 'STRUCTURE_UNVERIFIED';
			else $state = 'UNCHANGED';
			if ( 'UNCHANGED' === $state ) $preserved[] = $id;
			else $changed[ $id ] = array( 'state' => $state, 'quarantine_scope' => $old['provider'] . ':' . $id,
				'canary_required_for_promotion' => true, 'governed_authority_required' => true );
		}
		return array(
			'contract' => self::CONTRACT,
			'provider' => $old['provider'],
			'artifact_identity_changed' => $identity_drift,
			'changed_capabilities' => $changed,
			'unrelated_compatible_capabilities' => $preserved,
			'provider_wide_quarantine' => false,
			'whole_provider_deactivation' => false,
			'candidate_promotion_allowed' => false,
			'new_grants' => array(),
			'authorizing' => false,
		);
	}

	private static function valid_snapshot( array $snapshot ) {
		if ( ( $snapshot['contract'] ?? '' ) !== self::CONTRACT
			|| ! is_string( $snapshot['site_profile_sha256'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot['site_profile_sha256'] )
			|| ! is_array( $snapshot['restore_binding'] ?? null )
			|| ! is_int( $snapshot['restore_binding']['epoch'] ?? null )
			|| $snapshot['restore_binding']['epoch'] < 1
			|| ! self::identifier( $snapshot['provider'] ?? null )
			|| ! is_string( $snapshot['artifact_sha256'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot['artifact_sha256'] )
			|| ! is_array( $snapshot['capabilities'] ?? null )
			|| count( $snapshot['capabilities'] ) > self::MAX_CAPABILITIES
			|| false !== ( $snapshot['authorizing'] ?? true ) ) return false;
		foreach ( $snapshot['capabilities'] as $id => $row ) {
			if ( ! self::identifier( $id ) || ! is_array( $row )
				|| ! is_string( $row['contract_sha256'] ?? null )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $row['contract_sha256'] )
				|| ! is_bool( $row['structural_compatible'] ?? null )
				|| ! is_bool( $row['behavioral_verified'] ?? null )
				|| ! is_bool( $row['rollback_verified'] ?? null )
				|| ! is_bool( $row['read_eligible'] ?? null )
				|| ! is_bool( $row['write_eligible'] ?? null )
				|| ! is_string( $row['certification_level'] ?? null )
				|| strlen( $row['certification_level'] ) > 50 ) return false;
		}
		return true;
	}

	/** Verifies independent live evidence; never manufactures it from repository CI. */
	public static function live_acceptance() {
		$observer = 'MAD4B_SCP_Live_Acceptance_Observer';
		$external = class_exists( $observer, false ) && method_exists( $observer, 'external_handshake_attestation_status' )
			? $observer::external_handshake_attestation_status() : array();
		$performance = class_exists( $observer, false ) && method_exists( $observer, 'frontend_performance_status' )
			? $observer::frontend_performance_status() : array();
		$valid_external = class_exists( 'MAD4B_SCP_G8_Record', false ) && MAD4B_SCP_G8_Record::staging()
			&& is_array( $external ) && true === ( $external['verified'] ?? false )
			&& true === ( $external['inventory_match'] ?? false )
			&& true === ( $external['package_identity_match'] ?? false );
		$samples = is_array( $performance ) ? (int) ( $performance['evaluation_window']['sample_count'] ?? 0 ) : 0;
		$valid_frontend = is_array( $performance ) && true === ( $performance['ready'] ?? false ) && $samples >= 3;
		return array( 'contract' => self::CONTRACT, 'state' => $valid_external && $valid_frontend ? 'PASSIVE_EVIDENCE_READY' : 'EXTERNAL_ACCEPTANCE_PENDING',
			'verified_external_inventory' => $valid_external, 'verified_frontend_samples' => $samples,
			'frontend_baseline_ready' => $valid_frontend,
			'browser_canary_rollback_receipts_verified' => false,
			'release_acceptance' => false,
			'candidate_promotion_allowed' => false, 'authorizing' => false );
	}
}
