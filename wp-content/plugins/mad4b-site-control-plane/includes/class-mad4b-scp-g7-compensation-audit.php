<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-adaptive-operations-context.php';

/**
 * Cross-checks native signed receipts without creating an Undo executor.
 * Matching signed receipts and local readback evidence are necessary but NOT
 * sufficient to prove reversal of remote/irreversible side effects.
 */
final class MAD4B_SCP_G7_Compensation_Audit {
    const CONTRACT = 'mad4b.feature007-g7-compensation-audit.v1';

    public static function assess( array $original, array $compensation, array $context ) {
        $base = array(
            'contract' => self::CONTRACT, 'state' => 'RECONCILIATION_REQUIRED',
            'signed_receipts_verified' => false, 'exact_resource_lineage' => false,
            'readback_stages_present' => false, 'external_effects_verified' => false,
            'receipt_site_binding_verified' => false,
            'independent_evidence_verified' => false,
            'evidence_consistent' => false,
            'caller_hash_claims_consistent' => false,
            'compensation_performed' => false, 'undo_certified' => false,
            'history_only_rollback_denied' => true, 'execution_performed' => false,
            'authorizing' => false, 'mutation_performed' => false
        );
        if ( ! class_exists( 'MAD4B_SCP_Execution_Receipt' ) ) return self::result( $base, 'receipt_verifier_unavailable' );
        foreach ( array( $original, $compensation ) as $receipt ) {
            if ( ( $receipt['contract'] ?? '' ) !== MAD4B_SCP_Execution_Receipt::CONTRACT ) {
                return self::result( $base, 'receipt_contract_invalid' );
            }
            $verified = MAD4B_SCP_Execution_Receipt::verify( $receipt );
            if ( is_wp_error( $verified ) || ! is_array( $verified ) ||
                true !== ( $verified['valid'] ?? false ) ||
                true !== ( $verified['cryptographic_signature_verified'] ?? false ) ||
                ! is_string( $verified['receipt_sha256'] ?? null ) ||
                ! MAD4B_SCP_Adaptive_Operations_Context::sha( $verified['receipt_sha256'] ) ||
                ! MAD4B_SCP_Adaptive_Operations_Context::sha( $receipt['receipt_sha256'] ?? null ) ||
                ! hash_equals( $receipt['receipt_sha256'], $verified['receipt_sha256'] ) ) {
                return self::result( $base, 'signature_or_receipt_invalid' );
            }
        }
        $base['signed_receipts_verified'] = true;
        $target = $original['target_fingerprint'] ?? null;
        $set = $original['resource_set_sha256'] ?? null;
        if ( ! is_string( $target ) || '' === $target ||
            ! MAD4B_SCP_Adaptive_Operations_Context::sha( $set ) ||
            $target !== ( $compensation['target_fingerprint'] ?? null ) ||
            ! hash_equals( $set, (string) ( $compensation['resource_set_sha256'] ?? '' ) ) ) {
            return self::result( $base, 'target_or_resource_lineage_mismatch' );
        }
        if ( ( $original['receipt_sha256'] ?? '' ) === ( $compensation['receipt_sha256'] ?? '' ) ) {
            return self::result( $base, 'self_compensation_denied' );
        }
        $base['exact_resource_lineage'] = true;
        foreach ( array( $original, $compensation ) as $receipt ) {
            if ( 'PASS' !== ( $receipt['stages']['readback_reconciliation']['status'] ?? '' ) ||
                'PASS' !== ( $receipt['stages']['terminal_outcome']['status'] ?? '' ) ) {
                return self::result( $base, 'native_readback_or_terminal_evidence_missing' );
            }
        }
        $base['readback_stages_present'] = true;
        if ( ! isset( $context['binding'] ) || ! is_array( $context['binding'] ) ) {
            return self::result( $base, 'site_binding_missing' );
        }
        $current = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( is_wp_error( $current ) ) return self::result( $base, 'current_context_unavailable' );
        $same = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $context['binding'], $current );
        if ( is_wp_error( $same ) ) return self::result( $base, 'site_restore_or_runtime_binding_changed' );
        foreach ( array( 'original_before_sha256', 'original_after_sha256', 'compensation_before_sha256', 'compensation_after_sha256' ) as $field ) {
            if ( ! MAD4B_SCP_Adaptive_Operations_Context::sha( $context[ $field ] ?? '' ) ) {
                return self::result( $base, 'reversal_diff_incomplete' );
            }
        }
        if ( ! hash_equals( $context['original_after_sha256'], $context['compensation_before_sha256'] ) ||
            ! hash_equals( $context['original_before_sha256'], $context['compensation_after_sha256'] ) ) {
            return self::result( $base, 'reversal_diffs_not_inverse' );
        }
        if ( true !== ( $context['external_effects_reconciled'] ?? null ) ||
            true !== ( $context['independent_post_restore_readback'] ?? null ) ||
            true !== ( $context['original_outcome_committed'] ?? null ) ||
            true !== ( $context['compensation_outcome_committed'] ?? null ) ) {
            return self::result( $base, 'external_effect_or_independent_readback_unproven' );
        }
        // Caller-declared booleans and hashes are only consistency CLAIMS.
        // They cannot become independent evidence or site-bound Undo proof.
        $base['state'] = 'APPROVAL_REQUIRED';
        $base['caller_hash_claims_consistent'] = true;
        return self::result( $base, 'independent_executor_and_external_effect_receipts_required' );
    }

    private static function result( array $base, $reason ) {
        $base['reason'] = $reason;
        $base['next_action'] = 'review_existing_typed_compensation_executor_and_exact_readback';
        return $base;
    }
}
