<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g9-resilience-gates.php';

/**
 * G9 passive disaster-recovery convergence and acceptance prerequisites.
 *
 * Does not acknowledge a restore or touch restored grants, leases, approvals,
 * projection or backups. Those operations belong to the existing Restore Epoch,
 * guarded executor, reconciliation and acceptance subsystems.
 */
final class MAD4B_SCP_G9_Restore_Convergence {
    const CONTRACT = 'mad4b.g9.restore-convergence.v1';
    const MAX_EFFECTS = 128;

    private static function blocked( $code, $message ) {
        return new WP_Error( 'mad4b_g9_restore_' . $code, $message, array(
            'authorizing' => false, 'blind_retry_allowed' => false,
            'reconciliation_required' => true, 'mutation_performed' => false,
        ) );
    }

    /**
     * Compare a formerly captured exact site baseline with a fresh local
     * observation and independent bounded external-effect evidence.
     */
    public static function inspect( array $baseline, array $external_effects = array() ) {
        if ( count( $external_effects ) > self::MAX_EFFECTS )
            return self::blocked( 'effects_unbounded', 'External effect inventory exceeds safety bounds.' );
        $current = MAD4B_SCP_Resilience_Context::capture();
        if ( is_wp_error( $current ) ) return $current;
        $report = MAD4B_SCP_G9_Resilience_Gates::restore_preview(
            $baseline, $current, $external_effects
        );
        if ( is_wp_error( $report ) ) return $report;
        $old = $baseline['binding'];
        $new = $current['binding'];
        $drift = ! empty( $report['drift_facets'] ) || ! empty( $report['epoch_changed'] )
            || $old['runtime_generation_sha256'] !== $new['runtime_generation_sha256']
            || $old['site_profile_revision'] !== $new['site_profile_revision']
            || $old['registry_revision'] !== $new['registry_revision'];

        $required = array();
        if ( $drift ) {
            $required = array(
                'quarantine_stale_mutable_receipts_and_leases',
                'invalidate_candidate_bindings',
                'rebuild_local_provider_artifact_and_host_graph',
                'reconcile_exact_local_grants_and_registry',
            );
        }
        if ( ! empty( $report['unresolved_external_effect_keys'] ) )
            $required[] = 'independently_reconcile_unrewound_external_effects';
        if ( ! empty( $report['current_identity_blockers'] ) )
            $required[] = 'repair_current_site_identity_in_quarantine';
        $required[] = 'governed_staging_readback_and_post_restore_acceptance';

        // The status is advisory; none of the steps is automatically delegated.
        $result = array(
            'contract' => self::CONTRACT,
            'site_key' => $report['site_key'],
            'baseline_snapshot_sha256' => $baseline['snapshot_sha256'],
            'current_snapshot_sha256' => $current['snapshot_sha256'],
            'restore_epoch_changed' => (bool) $report['epoch_changed'],
            'changed_facets' => $report['drift_facets'],
            'requires_quarantine' => $drift
                || ! empty( $report['unresolved_external_effect_keys'] )
                || ! empty( $report['current_identity_blockers'] ),
            'required_stages' => array_values( array_unique( $required ) ),
            'unresolved_effect_keys' => $report['unresolved_external_effect_keys'],
            'external_effects_verified' => empty( $report['unresolved_external_effect_keys'] ),
            'immutable_audit_is_evidence_only' => true,
            'stale_authority_replayed' => false,
            'write_reenabled' => false,
            'post_restore_acceptance_issued' => false,
            'production_mutation_performed' => false,
            'authorizing' => false, 'mutation_performed' => false,
        );
        $result['evidence_sha256'] = MAD4B_SCP_Resilience_Context::digest( $result );
        return $result;
    }

    /** Local passive status uses the existing external epoch and anchor. */
    public static function status() {
        $snapshot = MAD4B_SCP_Resilience_Context::capture();
        if ( is_wp_error( $snapshot ) ) return $snapshot;
        $anchor = MAD4B_SCP_Resilience_Anchor::read( $snapshot['binding'] );
        if ( is_wp_error( $anchor ) ) return $anchor;
        $ready = ! empty( $snapshot['restore_bound'] )
            && ! empty( $snapshot['worker_current'] )
            && empty( $snapshot['identity_blockers'] )
            && is_int( $anchor['revision'] ) && $anchor['revision'] > 0;
        return array(
            'contract' => self::CONTRACT,
            'site_key' => MAD4B_SCP_Resilience_Context::site_key( $snapshot['binding'] ),
            'snapshot_sha256' => $snapshot['snapshot_sha256'],
            'restore_epoch' => $snapshot['binding']['restore_epoch'],
            'external_record_sha256' => $snapshot['binding']['external_record_sha256'],
            'anchor_revision' => $anchor['revision'],
            'origin_and_generation_ready' => (bool) $ready,
            'restored_grants_inferred' => false,
            'write_reenabled' => false,
            'post_restore_acceptance_issued' => false,
            'authorizing' => false, 'mutation_performed' => false,
        );
    }
}
