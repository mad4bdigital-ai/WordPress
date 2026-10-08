<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Release update acceptance evidence envelope. Safe, honest and additive to
 * native post-update continuation/Skills/catalog certification, never a writer.
 */
final class MAD4B_SCP_G7_Release_Acceptance_Audit {
    const CONTRACT = 'mad4b.feature007-g7-release-acceptance-audit.v1';

    public static function assess( array $before, array $after ) {
        if ( ! class_exists( 'MAD4B_SCP_G7_Update_Acceptance' ) ) return self::error( 'comparison_unavailable' );
        $comparison = MAD4B_SCP_G7_Update_Acceptance::compare( $before, $after );
        if ( is_wp_error( $comparison ) ) return $comparison;
        if ( ! is_array( $comparison ) || ( $comparison['contract'] ?? '' ) !== 'mad4b.feature007-g7-update-comparison.v1' ||
            ! MAD4B_SCP_Adaptive_Operations_Context::sha( $comparison['comparison_sha256'] ?? '' ) ) {
            return self::error( 'comparison_invalid' );
        }
        $reasons = array();
        if ( 'NO_UPDATE_OBSERVED' !== ( $comparison['state'] ?? '' ) ) {
            $reasons[] = 'exact_post_update_authority_readback_required';
            $reasons[] = 'current_catalog_and_skills_recertification_required';
            $reasons[] = 'schema_and_topology_parity_evidence_required';
            $reasons[] = 'provider_side_effect_reconciliation_required';
            $reasons[] = 'signed_existing_release_execution_and_rollback_rehearsal_required';
        }
        if ( ! empty( $comparison['graph_changed'] ) ) $reasons[] = 'capability_graph_drift_requires_impact_review';
        if ( 'RECONCILIATION_REQUIRED' === ( $comparison['state'] ?? '' ) ) $reasons[] = 'update_state_uncertain';
        $native = class_exists( 'MAD4B_SCP_Post_Update_Continuation' ) &&
            method_exists( 'MAD4B_SCP_Post_Update_Continuation', 'status' )
            ? MAD4B_SCP_Post_Update_Continuation::status() : array();
        $native_valid = class_exists( 'MAD4B_SCP_Post_Update_Continuation' ) &&
            is_array( $native ) &&
            ( $native['contract'] ?? '' ) === MAD4B_SCP_Post_Update_Continuation::CONTRACT;
        if ( ! $native_valid ) $reasons[] = 'native_continuation_status_unavailable';
        return array(
            'contract' => self::CONTRACT,
            'state' => 'NO_UPDATE_OBSERVED' === ( $comparison['state'] ?? '' )
                ? 'NO_UPDATE_OBSERVED' : 'RECONCILIATION_REQUIRED',
            'comparison_state' => $comparison['state'],
            'comparison_sha256' => $comparison['comparison_sha256'],
            'native_continuation_status_observed' => $native_valid,
            'native_continuation_state' => $native_valid && is_string( $native['state'] ?? null )
                ? substr( $native['state'], 0, 60 ) : 'unavailable',
            'blocking_evidence' => array_values( array_unique( $reasons ) ),
            'read_only' => true, 'authorizing' => false, 'mutation_performed' => false,
            'release_acceptance_receipt_issued' => false,
            'live_staging_certified' => false, 'external_effects_verified' => false,
            'skills_rebound' => false, 'credentials_changed' => false,
            'grant_mutation_performed' => false,
            'automatic_rollback_allowed' => false,
            'preauthorized_recovery_plan_required' => true
        );
    }

    private static function error( $reason ) {
        return new WP_Error( 'mad4b_g7_release_audit_' . $reason,
            'Governed release acceptance needs independently verified exact post-update evidence.',
            array( 'authorizing' => false, 'mutation_performed' => false ) );
    }
}
