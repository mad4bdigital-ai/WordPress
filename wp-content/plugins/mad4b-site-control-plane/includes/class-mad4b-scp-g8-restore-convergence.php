<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only cross-layer post-restore gate. Existing Restore Epoch and Runtime
 * Generation Fences remain authoritative. This is not a restore acknowledgement
 * path and cannot regrant permissions, rebind a candidate, or replay effects.
 */
final class MAD4B_SCP_G8_Restore_Convergence {
	const CONTRACT = 'mad4b.g8-restore-convergence.v1';

	private static function ready( $class, $method ) {
		return class_exists( $class, false ) && method_exists( $class, $method );
	}

	public static function status() {
		$blockers = array();
		if ( ! self::ready( 'MAD4B_SCP_G8_Record', 'staging' ) || ! MAD4B_SCP_G8_Record::staging() )
			$blockers[] = 'exact_enrolled_staging_required';
		$restore = self::ready( 'MAD4B_SCP_Restore_Epoch', 'status' ) ? MAD4B_SCP_Restore_Epoch::status( false, true ) : array();
		if ( ! is_array( $restore ) || true !== ( $restore['ready'] ?? false ) || true === ( $restore['quarantined'] ?? false ) )
			$blockers[] = 'restore_authority_epoch_not_reconciled';
		$generation = self::ready( 'MAD4B_SCP_Runtime_Generation_Fence', 'status' )
			? MAD4B_SCP_Runtime_Generation_Fence::status() : array();
		if ( ! is_array( $generation ) || true !== ( $generation['ready'] ?? false ) )
			$blockers[] = 'runtime_files_config_schema_generation_unverified';
		$binding = self::ready( 'MAD4B_SCP_G8_Record', 'binding' ) ? MAD4B_SCP_G8_Record::binding() : array();
		if ( is_wp_error( $binding ) || ! is_array( $binding ) )
			$blockers[] = 'external_restore_binding_unavailable';
		if ( ! self::ready( 'MAD4B_SCP_G8_Record', 'runtime_binding' ) )
			$runtime = null;
		else $runtime = MAD4B_SCP_G8_Record::runtime_binding();
		if ( ! is_string( $runtime ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $runtime ) )
			$blockers[] = 'current_package_provenance_unverified';

		$packs = self::ready( 'MAD4B_SCP_Certification_Pack_Registry', 'status' )
			? MAD4B_SCP_Certification_Pack_Registry::status() : array();
		$epoch = is_array( $restore ) && is_int( $restore['epoch'] ?? null ) ? $restore['epoch'] : 0;
		if ( is_wp_error( $packs ) || ! is_array( $packs ) || 'mad4b.certification-pack-registry.v1' !== ( $packs['contract'] ?? '' )
			|| $epoch < 1 || $epoch !== (int) ( $packs['restore_epoch'] ?? 0 ) )
			$blockers[] = 'certification_pack_registry_restore_reconciliation_pending';
		$convergence = self::ready( 'MAD4B_SCP_Runtime_Convergence', 'status' )
			? MAD4B_SCP_Runtime_Convergence::status() : array();
		if ( ! is_array( $convergence ) || ! array_key_exists( 'required_blockers', $convergence )
			|| ! empty( $convergence['required_blockers'] ) )
			$blockers[] = 'runtime_convergence_incomplete';
		$automation = self::ready( 'MAD4B_SCP_Automation_SLO', 'status' )
			? MAD4B_SCP_Automation_SLO::status() : array();
		if ( ! is_array( $automation ) || true !== ( $automation['integrity_valid'] ?? false )
			|| (int) ( $automation['pending_count'] ?? -1 ) !== 0 )
			$blockers[] = 'automatic_work_or_unknown_outcomes_pending';
		$migrations = self::ready( 'MAD4B_SCP_G8_Schema_Migration', 'status' )
			? MAD4B_SCP_G8_Schema_Migration::status() : array();
		if ( ! is_array( $migrations ) || 'OBSERVED' !== ( $migrations['state'] ?? '' ) )
			$blockers[] = 'observation_migrations_stale_or_unverified';

		$external = self::ready( 'MAD4B_SCP_Live_Acceptance_Observer', 'external_handshake_attestation_status' )
			? MAD4B_SCP_Live_Acceptance_Observer::external_handshake_attestation_status() : array();
		$client_verified = is_array( $external ) && true === ( $external['verified'] ?? false )
			&& true === ( $external['inventory_match'] ?? false )
			&& true === ( $external['package_identity_match'] ?? false );
		if ( ! $client_verified ) $blockers[] = 'external_client_inventory_acceptance_pending';

		// Browser receipts alone cannot prove that an external payment, email,
		// update or provider action was undone by a local database restore.
		// Post-restore external-effect reconciliation requires a separate governed
		// evidence/approval workflow; never synthesize an acceptance certificate.
		$blockers[] = 'unrewound_external_effect_reconciliation_required';
		$blockers[] = 'post_restore_governed_acceptance_receipt_required';
		return array(
			'contract' => self::CONTRACT,
			'state' => 'BLOCKED_PENDING_GOVERNED_ACCEPTANCE',
			'blockers' => array_values( array_unique( $blockers ) ),
			'local_prerequisites_passed' => 2 === count( array_intersect( $blockers, array(
				'unrewound_external_effect_reconciliation_required',
				'post_restore_governed_acceptance_receipt_required',
			) ) ) && 2 === count( $blockers ),
			'external_inventory_verified' => $client_verified,
			'write_resume_allowed' => false,
			'candidate_rebinding_allowed' => false,
			'owner_attestation_inferred' => false,
			'old_receipts_replayed' => false,
			'new_grants' => array(),
			'production_mutation' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}
}
