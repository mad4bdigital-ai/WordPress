<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-resilience-context.php';
require_once __DIR__ . '/class-mad4b-scp-resilience-anchor.php';

/**
 * G9 site-local, non-authorizing rollout and restore decisions.
 * Does not switch blog, contact remote sites, restore a backup, invoke providers,
 * create execution grants or issue an executable release/restore receipt.
 */
final class MAD4B_SCP_G9_Resilience_Gates {
    const RING_CONTRACT = 'mad4b.g9.release-ring-preview.v1';
    const FLEET_CONTRACT = 'mad4b.g9.fleet-observation.v1';
    const RESTORE_CONTRACT = 'mad4b.g9.restore-convergence-preview.v1';
    const MAX_COHORT = 16;

    private static function deny( $code, $message ) {
        return new WP_Error( 'mad4b_g9_' . $code, $message, array(
            'authorizing' => false, 'mutation_performed' => false,
            'blind_retry_allowed' => false, 'reconciliation_required' => true,
        ) );
    }

    /** Passive evidence is verified for shape and identity, never treated as an authority token. */
    private static function check_snapshot( array $observation ) {
        if ( ( $observation['contract'] ?? '' ) !== MAD4B_SCP_Resilience_Context::CONTRACT
            || ! isset( $observation['binding'] ) || ! is_array( $observation['binding'] ) )
            return self::deny( 'snapshot_contract', 'Expected one exact site-local resilience observation.' );
        $binding = $observation['binding'];
        $valid = MAD4B_SCP_Resilience_Context::validate_binding( $binding );
        if ( is_wp_error( $valid ) ) return $valid;
        if ( ! MAD4B_SCP_Resilience_Context::is_hash( $observation['binding_sha256'] ?? '' )
            || ! hash_equals( MAD4B_SCP_Resilience_Context::digest( $binding ), $observation['binding_sha256'] )
            || ! MAD4B_SCP_Resilience_Context::is_hash( $observation['snapshot_sha256'] ?? '' )
            || ! hash_equals( MAD4B_SCP_Resilience_Context::snapshot_digest( $observation ), $observation['snapshot_sha256'] ) )
            return self::deny( 'snapshot_digest', 'Site evidence changed after its capture.' );
        if ( ! isset( $observation['authority'] ) || ! is_array( $observation['authority'] )
            || ( $observation['authority']['site_uuid'] ?? '' ) !== $binding['site_uuid']
            || ( $observation['authority']['environment'] ?? '' ) !== $binding['environment']
            || ( $observation['authority']['runtime_generation_sha256'] ?? '' ) !== $binding['runtime_generation_sha256']
            || ( $observation['authority']['restore_epoch'] ?? 0 ) !== $binding['restore_epoch'] )
            return self::deny( 'authority_site_mismatch', 'Authority observation belongs to a different site or epoch.' );
        return true;
    }

    /** Every supplied fleet member is passive, independently bound and bounded. */
    public static function fleet_inventory( array $observations ) {
        if ( ! $observations || count( $observations ) > self::MAX_COHORT ) {
            return self::deny( 'cohort_bounds', 'Fleet inspection requires 1 to 16 explicit site-local observations.' );
        }
        $sites = array();
        $uuids = array();
        $origins = array();
        foreach ( $observations as $observation ) {
            if ( ! is_array( $observation ) ) return self::deny( 'observation_invalid', 'Fleet member is not a typed observation.' );
            $valid = self::check_snapshot( $observation );
            if ( is_wp_error( $valid ) ) return $valid;
            $binding = $observation['binding'];
            $site_key = MAD4B_SCP_Resilience_Context::site_key( $binding );
            if ( isset( $sites[ $site_key ] ) ) return self::deny( 'duplicate_site', 'A site cannot occur twice in one cohort.' );
            if ( isset( $uuids[ $binding['site_uuid'] ] ) )
                return self::deny( 'cloned_site_uuid', 'The same site UUID cannot claim multiple fleet origins or blogs.' );
            $origin_key = MAD4B_SCP_Resilience_Context::digest( array( $binding['canonical_origin'], $binding['blog_id'] ) );
            if ( isset( $origins[ $origin_key ] ) )
                return self::deny( 'cloned_origin', 'Two site identities cannot claim the same canonical origin and blog.' );
            $uuids[ $binding['site_uuid'] ] = true;
            $origins[ $origin_key ] = true;
            $sites[ $site_key ] = array(
                'site_key' => $site_key,
                'environment' => $binding['environment'],
                'binding_sha256' => $observation['binding_sha256'],
                'snapshot_sha256' => $observation['snapshot_sha256'],
                'runtime_generation_sha256' => $binding['runtime_generation_sha256'],
                'registry_sha256' => $binding['registry_sha256'],
                'grant_snapshot_sha256' => (string) ( $observation['authority']['grant_snapshot_sha256'] ?? '' ),
                'eligible_for_execution' => false,
                'local_readback_required' => true,
            );
        }
        ksort( $sites, SORT_STRING );
        return array( 'contract' => self::FLEET_CONTRACT, 'sites' => array_values( $sites ),
            'site_count' => count( $sites ), 'authorizing' => false,
            'mutation_performed' => false, 'cross_site_grants_inferred' => false );
    }

    /**
     * Pure cohort diff: independent site/grant/provider state is never copied
     * into the next cohort. This is a planning aid, not a promotion decision.
     */
    public static function fleet_diff( array $old_observations, array $new_observations ) {
        $old = self::fleet_inventory( $old_observations );
        if ( is_wp_error( $old ) ) return $old;
        $new = self::fleet_inventory( $new_observations );
        if ( is_wp_error( $new ) ) return $new;
        $previous = array();
        $current = array();
        foreach ( $old['sites'] as $row ) $previous[ $row['site_key'] ] = $row;
        foreach ( $new['sites'] as $row ) $current[ $row['site_key'] ] = $row;
        $added = array(); $removed = array(); $changed = array(); $unchanged = array();
        foreach ( $previous as $key => $row ) {
            if ( ! isset( $current[ $key ] ) ) { $removed[] = $key; continue; }
            $fingerprint = MAD4B_SCP_Resilience_Context::digest( $row );
            $updated = MAD4B_SCP_Resilience_Context::digest( $current[ $key ] );
            if ( hash_equals( $fingerprint, $updated ) ) $unchanged[] = $key;
            else $changed[] = $key;
        }
        foreach ( $current as $key => $row ) if ( ! isset( $previous[ $key ] ) ) $added[] = $key;
        return array(
            'contract' => 'mad4b.g9.fleet-diff.v1',
            'added' => $added, 'removed' => $removed,
            'changed' => $changed, 'unchanged' => $unchanged,
            'local_per_site_readback_required' => true,
            'promotion_authorized' => false, 'cross_site_authority_inferred' => false,
            'authorizing' => false, 'mutation_performed' => false,
        );
    }

    /** A green pilot only permits consideration by the existing governed executor. */
    public static function release_preview( array $observation, array $target, array $limits ) {
        $valid = self::check_snapshot( $observation );
        if ( is_wp_error( $valid ) ) return $valid;
        $binding = $observation['binding'];
        $now = MAD4B_SCP_Resilience_Context::now();
        if ( ! is_int( $observation['captured_at'] ?? null )
            || $observation['captured_at'] > $now
            || $observation['captured_at'] < $now - 120 )
            return self::deny( 'observation_stale', 'Release evidence must be captured locally within 120 seconds.' );
        if ( 'production' === $binding['environment'] )
            return self::deny( 'production_ring_denied', 'A nonproduction ring can never imply Production promotion.' );
        if ( ( $target['contract'] ?? '' ) !== self::RING_CONTRACT
            || ! in_array( $target['ring'] ?? '', array( 'pilot', 'canary', 'general' ), true )
            || ! is_string( $target['cohort_id'] ?? null )
            || ! preg_match( '/^[a-z0-9][a-z0-9._-]{2,63}$/D', $target['cohort_id'] )
            || ( $target['site_key'] ?? '' ) !== MAD4B_SCP_Resilience_Context::site_key( $binding )
            || ( $target['binding_sha256'] ?? '' ) !== $observation['binding_sha256']
            || ( $target['baseline_snapshot_sha256'] ?? '' ) !== $observation['snapshot_sha256'] )
            return self::deny( 'stale_cohort', 'Cohort does not bind the exact current local site and generation.' );
        if ( empty( $observation['worker_current'] ) || empty( $observation['restore_bound'] )
            || ! empty( $observation['identity_blockers'] )
            || empty( $observation['authority']['eligible'] )
            || ! MAD4B_SCP_Resilience_Context::is_hash( $observation['authority']['grant_snapshot_sha256'] ?? '' ) )
            return self::deny( 'local_authority_unready', 'Current site identity, authority or restore binding is unready.' );
        // All completeness flags belong to the pinned code-owned observer.
        // Empty or omitted inventories are never proof that no risk exists.
        foreach ( array(
            'provider_inventory_complete',
            'host_inventory_complete',
            'external_effect_inventory_complete',
            'health_sample_window_complete',
        ) as $complete ) {
            if ( true !== ( $observation['gates'][ $complete ] ?? null ) )
                return self::deny( 'inventory_incomplete', 'Pilot lacks complete current site-local evidence.');
        }
        // Missing/foreign/revoked providers and uncertain external effects are
        // blockers; a green WordPress health probe cannot override them.
        if ( ! isset( $observation['providers'] ) || ! is_array( $observation['providers'] )
            || ! $observation['providers'] || count( $observation['providers'] ) > 32 )
            return self::deny( 'provider_evidence_missing', 'No bounded, site-local provider certification evidence was captured.' );
        $site_key = MAD4B_SCP_Resilience_Context::site_key( $binding );
        foreach ( $observation['providers'] as $provider ) {
            if ( ! is_array( $provider )
                || empty( $provider['ready'] ) || ! empty( $provider['revoked'] )
                || ( $provider['site_key'] ?? '' ) !== $site_key
                || ( $provider['generation_sha256'] ?? '' ) !== $binding['runtime_generation_sha256']
                || ! MAD4B_SCP_Resilience_Context::is_hash( $provider['certification_sha256'] ?? '' ) )
                return self::deny( 'provider_revoked_or_foreign', 'A provider is unready, revoked, stale or belongs to another site.' );
        }
        if ( empty( $observation['host']['isolation_verified'] ) || empty( $observation['host']['local_readback_verified'] ) )
            return self::deny( 'host_isolation_unknown', 'Pilot requires independently verified local host isolation and readback.' );
        // flock on a file is NOT a distributed lease when different PHP hosts
        // use separate disks. No shared/quorum fence is implemented here.
        if ( true !== ( $observation['host']['single_host_exclusive_verified'] ?? null ) )
            return self::deny( 'distributed_fence_unavailable', 'Site must have independently certified single-host exclusive deployment before using its local external fence.' );
        if ( ! isset( $observation['external_effects'] ) || ! is_array( $observation['external_effects'] )
            || count( $observation['external_effects'] ) > 128 )
            return self::deny( 'external_effect_evidence_missing', 'External effect inventory is incomplete or unbounded.' );
        foreach ( $observation['external_effects'] as $effect ) {
            if ( ! is_array( $effect )
                || ! in_array( $effect['state'] ?? '', array( 'verified_no_effect', 'verified_reconciled' ), true )
                || ( $effect['site_key'] ?? '' ) !== $site_key
                || ! MAD4B_SCP_Resilience_Context::is_hash( $effect['receipt_sha256'] ?? '' ) )
                return self::deny( 'external_effect_uncertain', 'Unrewound or foreign external effect must be reconciled before a pilot.' );
        }
        if ( 'pilot' !== $target['ring']
            && ( ! MAD4B_SCP_Resilience_Context::is_hash( $target['prior_ring_receipt_sha256'] ?? '' )
                || empty( $observation['gates']['prior_ring_health_accepted'] ) ) )
            return self::deny( 'prior_ring_missing', 'Wider rings require evidence from an already accepted prior ring.' );
        $health = $observation['health'] ?? array();
        if ( ! is_int( $health['observed_at'] ?? null )
            || $health['observed_at'] > $now
            || $health['observed_at'] < $now - 120 )
            return self::deny( 'health_stale', 'Pilot health readings are missing or older than 120 seconds.' );
        foreach ( array( 'sample_count', 'error_rate_bps', 'p95_ms' ) as $field ) {
            if ( ! isset( $health[ $field ] ) || ! is_int( $health[ $field ] ) || $health[ $field ] < 0 )
                return self::deny( 'health_missing', 'Pilot requires typed current local health readings.' );
        }
        foreach ( array( 'min_samples', 'max_error_rate_bps', 'max_p95_ms' ) as $field ) {
            if ( ! isset( $limits[ $field ] ) || ! is_int( $limits[ $field ] ) || $limits[ $field ] < 0 )
                return self::deny( 'health_policy_missing', 'Release threshold must be a bounded integer.' );
        }
        if ( $limits['min_samples'] < 1 || $limits['max_error_rate_bps'] > 10000
            || $limits['max_p95_ms'] < 1 || $health['error_rate_bps'] > 10000
            || $health['sample_count'] < $limits['min_samples']
            || $health['error_rate_bps'] > $limits['max_error_rate_bps']
            || $health['p95_ms'] > $limits['max_p95_ms'] )
            return self::deny( 'pilot_health_failed', 'Current pilot health is outside the explicit bounded threshold.' );
        $anchor = MAD4B_SCP_Resilience_Anchor::read( $binding );
        if ( is_wp_error( $anchor ) ) return $anchor;
        if ( ! is_int( $anchor['revision'] ) || $anchor['revision'] < 1 )
            return self::deny( 'external_fence_uninitialized', 'Release requires an initialized external site-local fence.' );
        return array(
            'contract' => self::RING_CONTRACT, 'site_key' => $target['site_key'],
            'ring' => $target['ring'], 'cohort_id' => $target['cohort_id'],
            'binding_sha256' => $observation['binding_sha256'],
            'baseline_snapshot_sha256' => $observation['snapshot_sha256'],
            'external_anchor_revision' => $anchor['revision'],
            'decision_sha256' => MAD4B_SCP_Resilience_Context::digest( array(
                $target, $limits, $anchor['anchor_sha256'], $observation['snapshot_sha256'],
            ) ),
            'health_gate_passed' => true, 'execution_supported' => false,
            'requires_governed_execution_and_fresh_readback' => true,
            'authorizing' => false, 'mutation_performed' => false,
        );
    }

    /** Restore comparison never turns old grants or pre-restore receipts back on. */
    public static function restore_preview( array $before, array $current, array $effect_receipts ) {
        foreach ( array( $before, $current ) as $observation ) {
            $valid = self::check_snapshot( $observation );
            if ( is_wp_error( $valid ) ) return $valid;
        }
        if ( MAD4B_SCP_Resilience_Context::site_key( $before['binding'] )
            !== MAD4B_SCP_Resilience_Context::site_key( $current['binding'] ) )
            return self::deny( 'foreign_restore', 'Restore evidence belongs to another site, origin, blog or environment.' );
        $anchor = MAD4B_SCP_Resilience_Anchor::read( $current['binding'] );
        if ( is_wp_error( $anchor ) ) return $anchor;
        if ( (int) $anchor['revision'] < 1 )
            return self::deny( 'external_fence_uninitialized', 'Restore requires a preserved external fence.' );
        if ( count( $effect_receipts ) > 128 )
            return self::deny( 'effect_capacity', 'External-effect receipts exceed the bounded reconciliation inventory.' );
        $unresolved = array();
        foreach ( $effect_receipts as $key => $receipt ) {
            if ( ! is_array( $receipt )
                || ! MAD4B_SCP_Resilience_Context::is_hash( $receipt['receipt_sha256'] ?? '' )
                || ! in_array( $receipt['state'] ?? '', array( 'verified_no_effect', 'verified_reconciled' ), true )
                || ( $receipt['site_key'] ?? '' ) !== MAD4B_SCP_Resilience_Context::site_key( $current['binding'] ) )
                $unresolved[] = (string) $key;
        }
        $drift = array();
        foreach ( array( 'database', 'files', 'runtime_package', 'site_profile', 'registry' ) as $facet ) {
            if ( ( $before['facets'][$facet] ?? null ) !== ( $current['facets'][$facet] ?? null ) )
                $drift[] = $facet;
        }
        $epoch_changed = $before['binding']['restore_epoch'] !== $current['binding']['restore_epoch']
            || $before['binding']['external_record_sha256'] !== $current['binding']['external_record_sha256'];
        return array(
            'contract' => self::RESTORE_CONTRACT,
            'site_key' => MAD4B_SCP_Resilience_Context::site_key( $current['binding'] ),
            'epoch_changed' => $epoch_changed, 'drift_facets' => $drift,
            'unresolved_external_effect_keys' => $unresolved,
            'external_effect_receipts_independently_verified' => false,
            'external_effect_inventory_completeness_verified' => false,
            'current_identity_blockers' => $current['identity_blockers'] ?? array(),
            'must_reconcile_grants_and_candidate_bindings' => true,
            'prior_mutable_receipts_accepted' => false,
            'old_execution_assumptions_replayed' => false,
            'append_only_audit_retained_as_evidence_only' => true,
            'post_restore_acceptance_issued' => false,
            'execution_supported' => false,
            'authorizing' => false, 'mutation_performed' => false,
        );
    }
}
