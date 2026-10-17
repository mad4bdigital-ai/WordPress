<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g9-restore-convergence.php';

/**
 * G9 non-authorizing closure assessor. Aggregates current source readiness
 * without emitting a signed release, restore, rollback or fleet authority.
 */
final class MAD4B_SCP_G9_Operational_Readiness {
    const CONTRACT = 'mad4b.g9.operational-readiness.v1';

    public static function status() {
        $site = MAD4B_SCP_Resilience_Context::capture();
        if ( is_wp_error( $site ) ) return $site;
        $binding = $site['binding'];
        $anchor = MAD4B_SCP_Resilience_Anchor::read( $binding );
        // An invalid/lost anchor is an actionable operational BLOCKER, not a
        // reason to hide every other read-only closure blocker. Never
        // recreate, repair, or coerce it into a valid zero-revision anchor.
        $anchor_error = is_wp_error( $anchor ) ? $anchor->get_error_code() : '';
        $blockers = array();
        if ( '' !== $anchor_error ) $blockers[] = 'external_fence_unavailable';
        if ( 'staging' !== $binding['environment'] ) $blockers[] = 'staging_site_required';
        if ( '' === $anchor_error
            && ( ! is_int( $anchor['revision'] ?? null ) || $anchor['revision'] < 1 ) )
            $blockers[] = 'external_fence_not_initialized';
        if ( ! empty( $site['identity_blockers'] ) || empty( $site['worker_current'] )
            || empty( $site['restore_bound'] ) )
            $blockers[] = 'runtime_or_restore_generation_unready';
        if ( empty( $site['authority']['eligible'] )
            || ! MAD4B_SCP_Resilience_Context::is_hash(
                $site['authority']['grant_snapshot_sha256'] ?? ''
            ) )
            $blockers[] = 'current_site_grants_or_candidate_unready';
        $site_key = MAD4B_SCP_Resilience_Context::site_key( $binding );
        if ( ! isset( $site['providers'] ) || ! is_array( $site['providers'] )
            || ! $site['providers'] || count( $site['providers'] ) > 32 ) {
            $blockers[] = 'certified_provider_inventory_missing';
        } else {
            foreach ( $site['providers'] as $provider ) {
                if ( ! is_array( $provider )
                    || true !== ( $provider['ready'] ?? null )
                    || false !== ( $provider['revoked'] ?? null )
                    || ( $provider['site_key'] ?? '' ) !== $site_key
                    || ( $provider['generation_sha256'] ?? '' ) !== $binding['runtime_generation_sha256']
                    || ! MAD4B_SCP_Resilience_Context::is_hash( $provider['certification_sha256'] ?? '' ) ) {
                    $blockers[] = 'certified_provider_unready';
                    break;
                }
            }
        }
        if ( true !== ( $site['host']['isolation_verified'] ?? false )
            || true !== ( $site['host']['local_readback_verified'] ?? false )
            || true !== ( $site['host']['single_host_exclusive_verified'] ?? false ) )
            $blockers[] = 'host_isolation_and_exclusive_fence_unverified';
        if ( ! is_array( $site['external_effects'] ?? null )
            || count( $site['external_effects'] ) > 128
            || true !== ( $site['gates']['external_effect_inventory_complete'] ?? null ) ) {
            $blockers[] = 'complete_external_effect_inventory_unverified';
        } else {
            foreach ( $site['external_effects'] as $effect ) {
                if ( ! is_array( $effect )
                    || ! in_array( $effect['state'] ?? null,
                        array( 'verified_no_effect', 'verified_reconciled' ), true )
                    || ( $effect['site_key'] ?? '' ) !== $site_key
                    || ! MAD4B_SCP_Resilience_Context::is_hash( $effect['receipt_sha256'] ?? '' ) ) {
                    $blockers[] = 'external_effect_unreconciled';
                    break;
                }
            }
        }
        $health = $site['health'] ?? array();
        $now = MAD4B_SCP_Resilience_Context::now();
        if ( ! is_array( $health ) || ! is_int( $health['observed_at'] ?? null )
            || $health['observed_at'] > $now || $health['observed_at'] < $now - 120
            || ! is_int( $health['sample_count'] ?? null ) || $health['sample_count'] < 1
            || ! is_int( $health['error_rate_bps'] ?? null )
            || $health['error_rate_bps'] < 0 || $health['error_rate_bps'] > 10000
            || ! is_int( $health['p95_ms'] ?? null ) || $health['p95_ms'] < 0 )
            $blockers[] = 'current_health_window_unverified';
        foreach ( array(
            'provider_inventory_complete',
            'host_inventory_complete',
            'health_sample_window_complete',
        ) as $key ) {
            if ( true !== ( $site['gates'][ $key ] ?? null ) )
                $blockers[] = 'site_evidence_incomplete';
        }
        if ( ! class_exists( 'MAD4B_SCP_G7_Release_Acceptance_Audit' )
            || ! class_exists( 'MAD4B_SCP_G7_Host_Readiness' ) )
            $blockers[] = 'g7_signed_host_and_release_acceptance_integration_missing';
        if ( ! class_exists( 'MAD4B_SCP_G8_Capability_Convergence' )
            || ! class_exists( 'MAD4B_SCP_G8_Supply_Provenance' )
            || ! class_exists( 'MAD4B_SCP_G8_Schema_Migration' ) )
            $blockers[] = 'g8_schema_supply_and_capability_integration_missing';
        // The internal fence currently asks native authorize_mutation for an
        // unregistered G9 reserve Ability. It cannot become executable by
        // merely setting a host feature flag: a reviewed native Capability
        // Descriptor, exact grant and executor dispatch integration are absent.
        // Never provision permissions or synthesize one here.
        $blockers[] = 'native_executor_g9_reservation_binding_unimplemented';
        if ( ! defined( 'MAD4B_SCP_G9_RELEASE_FENCE_ENABLED' )
            || true !== MAD4B_SCP_G9_RELEASE_FENCE_ENABLED )
            $blockers[] = 'g9_reservation_host_feature_disabled';
        if ( ! defined( 'MAD4B_SCP_G9_RELEASE_LIMITS' )
            || ! is_array( MAD4B_SCP_G9_RELEASE_LIMITS ) )
            $blockers[] = 'g9_host_release_threshold_policy_missing';
        // Native signed execution/rollback and post-restore provider/host
        // readbacks are always separate. Repository readiness cannot waive them.
        $blockers[] = 'native_signed_release_and_rollback_acceptance_pending';
        $blockers[] = 'external_effect_and_post_restore_acceptance_pending';
        $blockers[] = 'governed_staging_dr_fault_drill_pending';
        $blockers = array_values( array_unique( $blockers ) );
        return array(
            'contract' => self::CONTRACT,
            'site_key' => MAD4B_SCP_Resilience_Context::site_key( $binding ),
            'snapshot_sha256' => $site['snapshot_sha256'],
            'runtime_generation_sha256' => $binding['runtime_generation_sha256'],
            'restore_epoch' => $binding['restore_epoch'],
            'anchor_revision' => '' === $anchor_error ? $anchor['revision'] : null,
            'anchor_observation_valid' => '' === $anchor_error,
            'anchor_error_code' => $anchor_error,
            'blockers' => $blockers, 'blocker_count' => count( $blockers ),
            'missing_provider_evidence' => in_array( 'certified_provider_inventory_missing', $blockers, true ),
            'previous_execution_assumptions_replayed' => false,
            'prior_release_accepted' => false,
            'fleet_rollout_authorized' => false,
            'post_restore_receipt_issued' => false,
            'operationally_closed' => false, 'ready_for_production' => false,
            'authorizing' => false, 'mutation_performed' => false,
            'blind_retry_allowed' => false, 'reconciliation_required' => true,
        );
    }
}
