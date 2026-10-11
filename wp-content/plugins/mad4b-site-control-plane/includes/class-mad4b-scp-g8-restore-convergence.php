<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only cross-layer post-restore gate. Existing Restore Epoch and Runtime
 * Generation Fences remain authoritative. This is not a restore acknowledgement
 * path and cannot regrant permissions, rebind a candidate, or replay effects.
 */
final class MAD4B_SCP_G8_Restore_Convergence {
	const CONTRACT = 'mad4b.g8-restore-convergence.v1';

	/**
	 * Data-only reconciliation proposal for external side effects. Input records
	 * are hints, never authenticated receipts or a restore-acceptance certificate.
	 * The trusted external provider must later confirm every discrepancy under
	 * the existing owner-governed workflow.
	 */
	public static function compare_external_effects( array $before, array $external ) {
		if ( count( $before ) > 64 || count( $external ) > 64 )
			return new WP_Error( 'mad4b_g8_restore_effects_bounds', 'External effect comparison requires a bounded inventory.' );
		$clean = static function ( array $items ) {
			foreach ( $items as $id => $record ) {
				if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $id )
					|| ! is_array( $record ) || count( $record ) !== 2
					|| ! array_key_exists( 'state', $record ) || ! array_key_exists( 'effect_sha256', $record )
					|| ! in_array( $record['state'] ?? '', array( 'applied', 'compensated', 'unknown' ), true )
					|| ! is_string( $record['effect_sha256'] ?? null )
					|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $record['effect_sha256'] ) ) return false;
			}
			return true;
		};
		if ( ! $clean( $before ) || ! $clean( $external ) )
			return new WP_Error( 'mad4b_g8_restore_effects_invalid', 'External effect records are malformed; no blind replay is permitted.' );
		$ids = array_unique( array_merge( array_keys( $before ), array_keys( $external ) ) );
		sort( $ids, SORT_STRING );
		$issues = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $before[ $id ] ) ) $reason = 'external_effect_absent_from_local_snapshot';
			elseif ( ! isset( $external[ $id ] ) ) $reason = 'unobserved_external_effect';
			elseif ( ! hash_equals( $before[ $id ]['effect_sha256'], $external[ $id ]['effect_sha256'] ) )
				$reason = 'external_effect_identity_mismatch';
			elseif ( $before[ $id ]['state'] !== $external[ $id ]['state'] )
				$reason = 'external_effect_state_changed';
			elseif ( 'unknown' === $external[ $id ]['state'] )
				$reason = 'external_effect_unconfirmed';
			else continue;
			$issues[] = array( 'correlation_sha256' => $id, 'reason' => $reason, 'recovery' => 'governed_provider_readback_and_reconciliation' );
		}
		return array( 'contract' => self::CONTRACT, 'state' => 'UNTRUSTED_RECONCILIATION_PROPOSAL',
			'candidate_issue_count' => count( $issues ), 'issues' => $issues,
			'comparison_inputs_authenticated' => false,
			'governed_provider_confirmation_required' => true,
			'acceptance_receipt_issued' => false, 'automatic_retry_allowed' => false,
			'external_effect_applied' => false, 'authorizing' => false );
	}

	/**
	 * Cross-check optional native signed operation evidence against an external
	 * effect inventory. This is NOT proof that the external provider was rewound:
	 * the independent provider/host acceptance lane must still attest reality.
	 * No receipt is minted and no restored authority can become active here.
	 */
	public static function evidence_pack( array $before, array $external, array $witnesses ) {
		if ( count( $witnesses ) > 64 )
			return new WP_Error( 'mad4b_g8_restore_witness_capacity', 'Bounded native execution witnesses required.' );
		$diff = self::compare_external_effects( $before, $external );
		if ( is_wp_error( $diff ) ) return $diff;
		if ( ! self::ready( 'MAD4B_SCP_G8_Record', 'staging' ) || ! MAD4B_SCP_G8_Record::staging() )
			return new WP_Error( 'mad4b_g8_restore_staging_required', 'A current enrolled Staging site is required.' );
		$binding = MAD4B_SCP_G8_Record::binding();
		$runtime = MAD4B_SCP_G8_Record::runtime_binding();
		if ( is_wp_error( $binding ) || ! is_array( $binding ) || is_wp_error( $runtime )
			|| ! is_string( $runtime ) )
			return new WP_Error( 'mad4b_g8_restore_identity_unavailable', 'Exact current restore and runtime binding are required.' );
		$verified = array(); $rejected = array();
		foreach ( $witnesses as $id => $receipt ) {
			if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $id )
				|| ! isset( $external[ $id ] ) || ! is_array( $receipt ) || count( $receipt ) > 48
				|| ! MAD4B_SCP_G8_Record::inert( $receipt )
				|| strlen( serialize( $receipt ) ) > 65536 ) {
				return new WP_Error( 'mad4b_g8_restore_witness_invalid', 'Witness must describe an inventoried effect.' );
			}
			if ( ! self::ready( 'MAD4B_SCP_Execution_Receipt', 'verify' ) ) {
				$rejected[ $id ] = 'native_signature_verifier_unavailable'; continue;
			}
			try {
				$check = MAD4B_SCP_Execution_Receipt::verify( $receipt );
			} catch ( Throwable $error ) {
				$check = new WP_Error( 'mad4b_g8_native_receipt_verifier_exception', 'Native receipt verification failed.' );
			}
			if ( is_wp_error( $check ) || ! is_array( $check )
				|| true !== ( $check['valid'] ?? false )
				|| true !== ( $check['cryptographic_signature_verified'] ?? false )
				|| ! is_string( $check['receipt_sha256'] ?? null )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $check['receipt_sha256'] )
				|| ! is_string( $receipt['receipt_sha256'] ?? null )
				|| ! hash_equals( $check['receipt_sha256'], $receipt['receipt_sha256'] )
				|| ! is_string( $receipt['request_id'] ?? null )
				|| ! hash_equals( $id, hash( 'sha256', $receipt['request_id'] ) )
				|| ! is_string( $receipt['target_fingerprint'] ?? null )
				|| ! hash_equals( $external[ $id ]['effect_sha256'], $receipt['target_fingerprint'] )
				|| ! is_string( $receipt['provider_id'] ?? null ) || '' === $receipt['provider_id']
				|| ! is_string( $receipt['ability'] ?? null ) || '' === $receipt['ability'] ) {
				$rejected[ $id ] = 'native_receipt_signature_or_operation_binding_invalid'; continue;
			}
			$verified[ $id ] = $check['receipt_sha256'];
		}
		$final_binding = MAD4B_SCP_G8_Record::binding();
		$final_runtime = MAD4B_SCP_G8_Record::runtime_binding();
		if ( ! MAD4B_SCP_G8_Record::staging() || is_wp_error( $final_binding )
			|| is_wp_error( $final_runtime ) || $final_binding !== $binding
			|| ! hash_equals( $runtime, $final_runtime ) )
			return new WP_Error( 'mad4b_g8_restore_evidence_identity_raced', 'Restore/runtime identity changed during evidence review.' );
		ksort( $verified, SORT_STRING ); ksort( $rejected, SORT_STRING );
		$unwitnessed = array_values( array_diff( array_keys( $external ), array_keys( $verified ) ) );
		sort( $unwitnessed, SORT_STRING );
		return array(
			'contract' => 'mad4b.g8-restore-evidence-pack.v1',
			'restore_binding' => $binding,
			'runtime_binding' => $runtime,
			'local_inventory_sha256' => MAD4B_SCP_G8_Record::digest( $before ),
			'external_claim_sha256' => MAD4B_SCP_G8_Record::digest( $external ),
			'comparison' => $diff,
			'signed_native_operation_count' => count( $verified ),
			'signed_native_receipt_sha256_by_effect' => $verified,
			'rejected_witness_reasons' => $rejected,
			'unwitnessed_effect_keys' => $unwitnessed,
			'external_inventory_independently_certified' => false,
			'external_provider_readback_verified' => false,
			'governed_restore_acceptance_issued' => false,
			'acceptance_status' => 'EXTERNAL_PROVIDER_AND_GOVERNED_ACCEPTANCE_PENDING',
			'write_resume_allowed' => false,
			'candidate_rebinding_allowed' => false,
			'automatic_retry_allowed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

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

		// Integration with G9 remains an independent read-only gate. It may
		// be loaded later by Hub #258; absence never implies acceptance.
		$g9 = self::ready( 'MAD4B_SCP_G9_Restore_Convergence', 'status' )
			? MAD4B_SCP_G9_Restore_Convergence::status() : array();
		if ( is_wp_error( $g9 ) || ! is_array( $g9 )
			|| ( $g9['contract'] ?? '' ) !== 'mad4b.g9.restore-convergence.v1'
			|| true !== ( $g9['origin_and_generation_ready'] ?? false )
			|| ( $g9['restore_epoch'] ?? null ) !== $epoch
			|| ( $g9['external_record_sha256'] ?? '' ) !== ( $restore['external_record_sha256'] ?? '' ) )
			$blockers[] = 'g9_independent_restore_fence_pending';

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
