<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g9-resilience-gates.php';

/**
 * G9 append-only site-local reservation and reconciliation journal.
 *
 * This never installs/updates a plugin, changes ring membership, grants authority,
 * invokes a provider, or settles external effects. The existing governed runtime
 * release executor remains the sole dispatch owner. A digest is NOT a signature
 * or a certificate of successful release/rollback.
 */
final class MAD4B_SCP_G9_Release_Fence {
    const CONTRACT = 'mad4b.g9.release-fence.v1';
    const PLAN_CONTRACT = 'mad4b.g9.release-fence-plan.v1';
    const RESERVATION_CONTRACT = 'mad4b.g9.release-fence-reservation.v1';
    const MAX_PLAN_AGE = 300;
    const MAX_EVENTS = 256;

    private static function blocked( $code, $message ) {
        return new WP_Error( 'mad4b_g9_fence_' . $code, $message, array(
            'authorizing' => false, 'blind_retry_allowed' => false,
            'reconciliation_required' => true, 'mutation_performed' => false,
        ) );
    }

    private static function live_observation() {
        $observed = MAD4B_SCP_Resilience_Context::capture();
        if ( is_wp_error( $observed ) ) return $observed;
        if ( ! is_array( $observed ) || empty( $observed['snapshot_sha256'] ) ) {
            return self::blocked( 'observation_unavailable', 'Exact local observation is unavailable.' );
        }
        return $observed;
    }

    /**
     * Read-only, exact-local plan. Target/cohort/thresholds are policy inputs,
     * not authority. A later reservation must recapture and rerun all gates.
     */
    public static function plan( array $target, array $limits ) {
        $observed = self::live_observation();
        if ( is_wp_error( $observed ) ) return $observed;
        $preview = MAD4B_SCP_G9_Resilience_Gates::release_preview( $observed, $target, $limits );
        if ( is_wp_error( $preview ) ) return $preview;
        $now = MAD4B_SCP_Resilience_Context::now();
        $plan = array(
            'contract' => self::PLAN_CONTRACT,
            'target' => $target, 'limits' => $limits,
            'site_key' => MAD4B_SCP_Resilience_Context::site_key( $observed['binding'] ),
            'snapshot_sha256' => $observed['snapshot_sha256'],
            'binding_sha256' => $observed['binding_sha256'],
            'anchor_revision' => $preview['external_anchor_revision'],
            'preview_sha256' => $preview['decision_sha256'],
            'issued_at' => $now, 'expires_at' => $now + self::MAX_PLAN_AGE,
            'execution_supported' => false, 'authorizing' => false,
            'mutation_performed' => false,
        );
        $plan['plan_sha256'] = MAD4B_SCP_Resilience_Context::digest( $plan );
        return $plan;
    }

    /**
     * Reserve a single immutable site-local fence BEFORE governed executor entry.
     * No direct transport/Ability calls are permitted. Off by default and always
     * requires explicit current authority from the existing admission policy.
     */
    public static function reserve( array $plan ) {
        if ( ! defined( 'MAD4B_SCP_G9_RELEASE_FENCE_ENABLED' )
            || true !== MAD4B_SCP_G9_RELEASE_FENCE_ENABLED )
            return self::blocked( 'not_enabled', 'Code-only fence is not enabled for this site.' );

        if ( ( $plan['contract'] ?? '' ) !== self::PLAN_CONTRACT
            || ! MAD4B_SCP_Resilience_Context::is_hash( $plan['plan_sha256'] ?? '' ) )
            return self::blocked( 'plan_invalid', 'An exact bounded release plan is required.' );
        $copy = $plan; unset( $copy['plan_sha256'] );
        if ( ! hash_equals( $plan['plan_sha256'], MAD4B_SCP_Resilience_Context::digest( $copy ) ) )
            return self::blocked( 'plan_tampered', 'Release plan digest mismatch.' );
        $now = MAD4B_SCP_Resilience_Context::now();
        if ( ! is_int( $plan['issued_at'] ?? null ) || ! is_int( $plan['expires_at'] ?? null )
            || $plan['issued_at'] > $now || $now > $plan['expires_at']
            || $plan['expires_at'] - $plan['issued_at'] > self::MAX_PLAN_AGE )
            return self::blocked( 'plan_expired', 'Plan expired or clock moved backward.' );

        // The native executor must receive a code-owned, exact policy. Caller
        // supplied thresholds are descriptive only; accepting arbitrary lax
        // limits would silently defeat the release gate.
        if ( ! defined( 'MAD4B_SCP_G9_RELEASE_LIMITS' )
            || ! is_array( MAD4B_SCP_G9_RELEASE_LIMITS )
            || ! is_array( $plan['limits'] ?? null )
            || ! hash_equals(
                MAD4B_SCP_Resilience_Context::digest( MAD4B_SCP_G9_RELEASE_LIMITS ),
                MAD4B_SCP_Resilience_Context::digest( $plan['limits'] )
            ) )
            return self::blocked( 'policy_not_pinned', 'A code-owned exact Staging release threshold policy must match the plan.' );
        // Prior-ring hashes are not signed site acceptance receipts. Block
        // canary/general reservations until the native prior-ring verifier
        // is wired into the governed release/acceptance lane.
        if ( ! is_array( $plan['target'] ?? null )
            || ( $plan['target']['ring'] ?? '' ) !== 'pilot' )
            return self::blocked( 'wider_ring_not_certified', 'Only pilot reservation is modeled; wider rings require native signed prior-ring acceptance.' );
        // Never trust the observations carried by the plan. Recapture this worker
        // and compare every exact fact, including provider/host/health/effects.
        $observed = self::live_observation();
        if ( is_wp_error( $observed ) ) return $observed;
        if ( ! hash_equals( (string) ( $plan['snapshot_sha256'] ?? '' ), $observed['snapshot_sha256'] )
            || ! hash_equals( (string) ( $plan['binding_sha256'] ?? '' ), $observed['binding_sha256'] )
            || ( $plan['site_key'] ?? '' ) !== MAD4B_SCP_Resilience_Context::site_key( $observed['binding'] ) )
            return self::blocked( 'live_drift', 'Local site facts changed after planning.' );
        if ( ! is_array( $plan['target'] ?? null ) || ! is_array( $plan['limits'] ?? null ) )
            return self::blocked( 'plan_schema', 'Release plan has no typed cohort or thresholds.' );
        $preview = MAD4B_SCP_G9_Resilience_Gates::release_preview(
            $observed, $plan['target'], $plan['limits']
        );
        if ( is_wp_error( $preview ) ) return $preview;
        if ( ! hash_equals( $plan['preview_sha256'], $preview['decision_sha256'] )
            || ! is_int( $plan['anchor_revision'] )
            || $plan['anchor_revision'] !== $preview['external_anchor_revision'] )
            return self::blocked( 'plan_superseded', 'External fence or release policy changed.' );

        // The fast identity observer checks MANIFEST metadata, not the bytes
        // of every packaged file. An executable reservation needs full
        // runtime provenance (current package files and exact digest) and
        // must not accept a deferred fast identity as that proof.
        if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' )
            || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) )
            return self::blocked( 'runtime_package_unverified', 'Full package provenance verifier is unavailable.' );
        $full_package = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
        if ( ! is_array( $full_package ) || true !== ( $full_package['runtime_manifest_match'] ?? null )
            || true !== ( $full_package['manifest_valid'] ?? null )
            || false !== ( $full_package['stale'] ?? null )
            || ( $full_package['package_manifest_digest'] ?? '' ) !== $observed['binding']['artifact_sha256'] )
            return self::blocked( 'runtime_package_unverified', 'Packaged runtime files do not match the exact current build.' );
        // This is an internal, typed reservation only. It is not exposed as an
        // Ability, and can never substitute for provider-specific authorization.
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || true !== MAD4B_SCP_Site_Profile::nonproduction_governed()
            || MAD4B_SCP_Site_Profile::current_environment() !== 'staging'
            || ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
            || ! class_exists( 'MAD4B_SCP_Authorization' )
            || ! class_exists( 'MAD4B_SCP_Policy' ) )
            return self::blocked( 'execution_authority_unavailable', 'Existing mutation guard is unavailable.' );
        $mutable = MAD4B_SCP_Policy::can_mutate();
        if ( is_wp_error( $mutable ) || true !== $mutable )
            return self::blocked( 'execution_authority_unavailable', 'Core mutation guard is disabled.' );
        $ready = MAD4B_SCP_Staging_Write_Authority::current_execution_readiness();
        if ( ! is_array( $ready ) || empty( $ready['ready'] )
            || empty( $ready['current_grant_snapshot_ready'] )
            || empty( $ready['candidate_binding_match'] )
            || ! MAD4B_SCP_Resilience_Context::is_hash( $ready['grant_rows_fingerprint'] ?? '' )
            || ! hash_equals( $observed['authority']['grant_snapshot_sha256'], $ready['grant_rows_fingerprint'] ) )
            return self::blocked( 'grants_stale', 'Current exact site-local grants and candidate generation differ from the captured plan.' );
        $auth = MAD4B_SCP_Authorization::authorize_mutation(
            'mad4b/g9-release-reserve', 'mad4b-admin', 'core',
            array( 'plan_sha256' => $plan['plan_sha256'] )
        );
        // Native authorize_mutation() returns an EXACT claim ARRAY on success,
        // not boolean true. Never coerce an arbitrary truthy value into consent.
        if ( is_wp_error( $auth ) || ! is_array( $auth )
            || ( $auth['ability'] ?? '' ) !== 'mad4b/g9-release-reserve'
            || ( $auth['server_id'] ?? '' ) !== 'mad4b-admin'
            || ( $auth['provider'] ?? '' ) !== 'core'
            || ! is_int( $auth['grant_id'] ?? null ) || $auth['grant_id'] < 1
            || ! MAD4B_SCP_Resilience_Context::is_hash( $auth['policy_decision_sha256'] ?? '' )
            || ! MAD4B_SCP_Resilience_Context::is_hash( $auth['resource_set_sha256'] ?? '' )
            || ! MAD4B_SCP_Resilience_Context::is_hash( $auth['approval_impact_binding_sha256'] ?? '' ) )
            return self::blocked( 'not_admitted', 'Existing governed executor did not admit an exact verified reservation claim.' );

        // Stable logical operation identity: re-planning with a new issued_at,
        // threshold or anchor revision MUST NOT permit another rollout for the
        // same site/cohort/ring/artifact/restore epoch.
        $operation_sha = MAD4B_SCP_Resilience_Context::digest( array(
            'contract' => 'mad4b.g9.release-operation-key.v1',
            'site_key' => $plan['site_key'],
            'cohort_id' => $plan['target']['cohort_id'],
            'ring' => $plan['target']['ring'],
            'artifact_sha256' => $observed['binding']['artifact_sha256'],
            'runtime_generation_sha256' => $observed['binding']['runtime_generation_sha256'],
            'restore_epoch' => $observed['binding']['restore_epoch'],
            'external_record_sha256' => $observed['binding']['external_record_sha256'],
        ) );
        $entry_key = 'g9:release:' . $operation_sha;
        $result = MAD4B_SCP_Resilience_Anchor::transact(
            $observed['binding'], $plan['anchor_revision'],
            function ( $current ) use ( $entry_key, $plan, $operation_sha, $now, $observed ) {
                // Recheck the authoritative grant and candidate binding after
                // acquiring the external lock. If an administrator revoked a
                // permission in the admission-to-CAS window, do not reserve.
                // A plugin deployment or restore can occur AFTER the preview
                // and first grant admission. Re-assert the code-owned worker
                // generation and exact restore epoch under the external CAS
                // lock, before any durable reservation is published.
                $generation = MAD4B_SCP_Runtime_Generation_Fence::assert_current( array(
                    'contract' => MAD4B_SCP_Runtime_Generation_Fence::CONTRACT,
                    'generation_sha256' => $observed['binding']['runtime_generation_sha256'],
                ) );
                if ( is_wp_error( $generation ) || ! is_array( $generation ) || empty( $generation['ready'] ) )
                    return self::blocked( 'generation_changed_before_cas', 'Runtime generation changed during rollout admission.' );
                $full_locked = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
                if ( ! is_array( $full_locked )
                    || true !== ( $full_locked['runtime_manifest_match'] ?? null )
                    || true !== ( $full_locked['manifest_valid'] ?? null )
                    || false !== ( $full_locked['stale'] ?? null )
                    || ( $full_locked['package_manifest_digest'] ?? '' ) !== $observed['binding']['artifact_sha256'] )
                    return self::blocked( 'package_changed_before_cas', 'Packaged runtime file content changed before the reservation checkpoint.' );
                $epoch = MAD4B_SCP_Restore_Epoch::status( false, true );
                if ( ! is_array( $epoch ) || empty( $epoch['ready'] )
                    || ( $epoch['epoch'] ?? null ) !== $observed['binding']['restore_epoch']
                    || ( $epoch['external_record_sha256'] ?? '' ) !== $observed['binding']['external_record_sha256'] )
                    return self::blocked( 'restore_changed_before_cas', 'Current restore epoch or external record changed before reservation.' );
                $fresh = MAD4B_SCP_Staging_Write_Authority::current_execution_readiness();
                if ( ! is_array( $fresh ) || empty( $fresh['ready'] )
                    || empty( $fresh['current_grant_snapshot_ready'] )
                    || empty( $fresh['candidate_binding_match'] )
                    || ! MAD4B_SCP_Resilience_Context::is_hash( $fresh['grant_rows_fingerprint'] ?? '' )
                    || ! hash_equals( $observed['authority']['grant_snapshot_sha256'], $fresh['grant_rows_fingerprint'] ) )
                    return self::blocked( 'grants_changed_before_cas', 'Authority was revoked or changed before the external fence was reserved.' );
                if ( count( $current['scopes'] ) >= self::MAX_EVENTS
                    || array_key_exists( $entry_key, $current['scopes'] ) )
                    return self::blocked( 'replay_or_capacity', 'Duplicate release or fence capacity exceeded.' );
                foreach ( $current['scopes'] as $old ) {
                    if ( is_array( $old ) && ( $old['plan_sha256'] ?? '' ) === $plan['plan_sha256'] )
                        return self::blocked( 'duplicate_plan', 'Plan was already fenced under another key.' );
                }
                $current['scopes'][ $entry_key ] = array(
                    'contract' => self::RESERVATION_CONTRACT,
                    'operation_sha256' => $operation_sha, 'plan_sha256' => $plan['plan_sha256'],
                    'site_key' => $plan['site_key'], 'binding_sha256' => $plan['binding_sha256'],
                    'cohort_id' => $plan['target']['cohort_id'],
                    'ring' => $plan['target']['ring'],
                    'state' => 'fenced_not_dispatched', 'recorded_at' => $now,
                    'authorizing' => false, 'execution_performed' => false,
                );
                return $current;
            }
        );
        if ( is_wp_error( $result ) ) return $result;
        if ( ! isset( $result['scopes'][ $entry_key ] ) )
            return self::blocked( 'readback_mismatch', 'Reserved fence was not confirmed on external readback.' );
        return array(
            'contract' => self::RESERVATION_CONTRACT,
            'operation_sha256' => $operation_sha,
            'plan_sha256' => $plan['plan_sha256'],
            'site_key' => $plan['site_key'],
            'anchor_revision' => $result['revision'],
            'state' => 'fenced_not_dispatched',
            'execution_supported' => false, 'authorizing' => false,
            'mutation_performed' => true, 'provider_mutation_performed' => false,
            'release_accepted' => false,
        );
    }

    /**
     * Correlate a native journal UUID, transport request and signed receipt.
     * These identities occupy different namespaces. The native executor must
     * persist their explicit G9 linkage in its own terminal journal event.
     * A caller-provided receipt or coincidentally equal digest is insufficient.
     * This verifier owns no linkage writer and cannot issue release acceptance.
     */
    public static function native_execution_evidence( array $binding, $operation_sha256, $operation_id, array $receipt ) {
        $reservation = self::inspect( $binding, $operation_sha256 );
        if ( is_wp_error( $reservation ) ) return $reservation;
        if ( ! is_string( $operation_id )
            || 1 !== preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $operation_id ) )
            return self::blocked( 'native_operation_invalid', 'An exact native UUIDv4 operation identifier is required.' );
        if ( ! class_exists( 'MAD4B_SCP_Execution_State_View' )
            || ! class_exists( 'MAD4B_SCP_Execution_Receipt' ) )
            return self::blocked( 'native_evidence_unavailable', 'Existing execution state and signed receipt verifier are unavailable.' );
        if ( ( $receipt['contract'] ?? '' ) !== MAD4B_SCP_Execution_Receipt::CONTRACT
            || ! is_string( $receipt['request_id'] ?? null )
            || '' === $receipt['request_id'] || strlen( $receipt['request_id'] ) > 100
            || ! MAD4B_SCP_Resilience_Context::is_hash( $receipt['target_fingerprint'] ?? '' )
            || ( $receipt['ability'] ?? '' ) !== 'mad4b/runtime-release-set-apply'
            || ( $receipt['provider_id'] ?? '' ) !== 'core'
            || ! MAD4B_SCP_Resilience_Context::is_hash( $receipt['resource_set_sha256'] ?? '' )
            || ! MAD4B_SCP_Resilience_Context::is_hash( $receipt['terminal_receipt_sha256'] ?? '' ) )
            return self::blocked( 'native_receipt_unbound', 'A typed signed native release receipt with its own request and target identities is required.' );
        $verified = MAD4B_SCP_Execution_Receipt::verify( $receipt );
        if ( is_wp_error( $verified ) || ! is_array( $verified )
            || true !== ( $verified['valid'] ?? null )
            || true !== ( $verified['cryptographic_signature_verified'] ?? null )
            || ! MAD4B_SCP_Resilience_Context::is_hash( $verified['receipt_sha256'] ?? '' ) )
            return self::blocked( 'native_signature_invalid', 'Native execution receipt signature is unavailable or invalid.' );
        $state = MAD4B_SCP_Execution_State_View::operation( $operation_id );
        if ( is_wp_error( $state ) || ! is_array( $state )
            || ( $state['contract'] ?? '' ) !== MAD4B_SCP_Execution_State_View::CONTRACT
            || ( $state['canonical_state'] ?? '' ) !== MAD4B_SCP_Execution_State_View::COMMITTED
            || true !== ( $state['terminal'] ?? null ) || ! empty( $state['reconciliation_required'] )
            || ( $state['scope'] ?? '' ) !== 'operation'
            || ( $state['source_contract'] ?? '' ) !== 'mad4b.dynamic-operation-status.v1'
            || ( $state['evidence']['operation_id'] ?? '' ) !== $operation_id
            || ! MAD4B_SCP_Resilience_Context::is_hash( $state['evidence']['operation_binding_sha256'] ?? '' )
            || ! MAD4B_SCP_Resilience_Context::is_hash( $state['evidence']['journal_head_sha256'] ?? '' )
            || ! is_int( $state['evidence']['latest_sequence'] ?? null )
            || $state['evidence']['latest_sequence'] < 1
            || ! empty( $state['evidence']['orphan_candidate'] )
            || ! empty( $state['evidence']['stale_heartbeat'] )
            || ! empty( $state['evidence']['lock_expired'] )
            || ! empty( $state['evidence']['hard_deadline_exceeded'] ) )
            return self::blocked( 'native_execution_uncertain', 'Canonical journal operation identity, append-only head or terminal evidence is missing or contradictory.' );

        // Execution_Receipt::build() signs the operation_journal stage from the
        // native claim operation_id. request_id is the separate transport ID.
        // A NOT_REQUIRED stage is common for the current runtime release path;
        // it must remain unverified until the owning executor adds this binding.
        $journal_stage = $receipt['stages']['operation_journal'] ?? null;
        if ( ! is_array( $journal_stage ) || ( $journal_stage['status'] ?? '' ) !== 'PASS'
            || ( $journal_stage['evidence_type'] ?? '' ) !== 'operation_id'
            || ( $journal_stage['evidence_sha256'] ?? '' ) !== hash( 'sha256', $operation_id )
            || ! class_exists( 'MAD4B_SCP_Operation_Journal' )
            || ! method_exists( 'MAD4B_SCP_Operation_Journal', 'trace' ) )
            return self::blocked( 'native_link_unavailable', 'The signed native claim does not bind this journal operation; G9 execution evidence remains unverified.' );
        $trace = MAD4B_SCP_Operation_Journal::trace( $operation_id, 1000 );
        if ( is_wp_error( $trace ) || ! is_array( $trace )
            || ( $trace['contract'] ?? '' ) !== 'mad4b.dynamic-operation-trace.v1'
            || ( $trace['operation_id'] ?? '' ) !== $operation_id
            || true !== ( $trace['chain_valid'] ?? null ) || true !== ( $trace['complete'] ?? null )
            || ! is_array( $trace['events'] ?? null )
            || ( $trace['count'] ?? null ) !== $state['evidence']['latest_sequence']
            || count( $trace['events'] ) !== $trace['count'] )
            return self::blocked( 'native_link_unavailable', 'Complete independently read native journal linkage is unavailable.' );
        $terminal = end( $trace['events'] );
        if ( ! is_array( $terminal )
            || ( $terminal['sequence'] ?? null ) !== $state['evidence']['latest_sequence']
            || ( $terminal['event_sha256'] ?? '' ) !== $state['evidence']['journal_head_sha256']
            || ( $terminal['lifecycle_state'] ?? '' ) !== 'completed'
            || ! in_array( $terminal['terminal_outcome'] ?? '', array( 'committed', 'completed', 'success', 'succeeded', 'ok' ), true )
            || ! is_array( $terminal['safe_metadata'] ?? null ) )
            return self::blocked( 'native_link_unavailable', 'The native terminal journal event does not match the exact committed state.' );
        // These are read from the native append-only journal, never accepted as
        // caller attestations. The executor linkage producer is not implemented
        // by this G9 foundation; absent metadata fails closed.
        $link = $terminal['safe_metadata'];
        $expected = array(
            'link_contract' => 'mad4b.g9.native-release-link.v1',
            'g9_operation_sha256' => $operation_sha256,
            'g9_plan_sha256' => $reservation['plan_sha256'],
            'site_binding_sha256' => $reservation['binding_sha256'],
            'native_request_id' => $receipt['request_id'],
            'native_target_fingerprint' => $receipt['target_fingerprint'],
            'resource_set_sha256' => $receipt['resource_set_sha256'],
            'execution_receipt_sha256' => $verified['receipt_sha256'],
            'terminal_receipt_sha256' => $receipt['terminal_receipt_sha256'],
        );
        foreach ( $expected as $key => $value ) {
            if ( ! is_string( $link[ $key ] ?? null ) || ! hash_equals( $value, $link[ $key ] ) )
                return self::blocked( 'native_link_unavailable', 'The native journal has no exact signed-claim-to-G9 reservation linkage.' );
        }
        // Pin both independent reads. A concurrent journal append, restore or
        // site/profile change must not produce a mixed-generation success view.
        $fresh_state = MAD4B_SCP_Execution_State_View::operation( $operation_id );
        $fresh_reservation = self::inspect( $binding, $operation_sha256 );
        if ( is_wp_error( $fresh_state ) || ! is_array( $fresh_state )
            || is_wp_error( $fresh_reservation ) || ! is_array( $fresh_reservation )
            || MAD4B_SCP_Resilience_Context::digest( $state ) !== MAD4B_SCP_Resilience_Context::digest( $fresh_state )
            || MAD4B_SCP_Resilience_Context::digest( $reservation ) !== MAD4B_SCP_Resilience_Context::digest( $fresh_reservation ) )
            return self::blocked( 'native_evidence_changed', 'Native journal or local reservation changed during verification; evidence remains unverified.' );
        return array(
            'contract' => 'mad4b.g9.native-execution-evidence.v1',
            'operation_sha256' => $operation_sha256,
            'native_operation_id' => $operation_id,
            'native_request_id' => $receipt['request_id'],
            'native_target_fingerprint' => $receipt['target_fingerprint'],
            'signed_receipt_sha256' => $verified['receipt_sha256'],
            'native_journal_head_sha256' => $state['evidence']['journal_head_sha256'],
            'native_execution_state' => 'COMMITTED',
            'native_execution_evidence_verified' => true,
            'site_local_release_accepted' => false,
            'fresh_provider_host_and_external_effect_readback_required' => true,
            'production_authorized' => false,
            'authorizing' => false, 'mutation_performed' => false,
        );
    }

    /**
     * Read-only uncertainty projection. A missing terminal event is never
     * success or retryable; an observed external outcome requires independent
     * provider readback through the existing executor/acceptance plane.
     */
    public static function inspect( array $binding, $operation_sha256 ) {
        if ( ! MAD4B_SCP_Resilience_Context::is_hash( $operation_sha256 ) )
            return self::blocked( 'operation_identity', 'Exact operation digest is required.' );
        // No caller-selected foreign-site reads, even for the passive receipt view.
        $current = self::live_observation();
        if ( is_wp_error( $current ) ) return $current;
        if ( MAD4B_SCP_Resilience_Context::digest( $binding ) !== $current['binding_sha256'] )
            return self::blocked( 'foreign_site', 'External history is limited to this exact currently enrolled site.');
        $record = MAD4B_SCP_Resilience_Anchor::read( $binding );
        if ( is_wp_error( $record ) ) return $record;
        $key = 'g9:release:' . $operation_sha256;
        if ( ! isset( $record['scopes'][ $key ] )
            || ! is_array( $record['scopes'][ $key ] ) )
            return self::blocked( 'reservation_missing', 'Unknown operation cannot be assumed complete.' );
        $original = $record['scopes'][ $key ];
        if ( ( $original['contract'] ?? '' ) !== self::RESERVATION_CONTRACT
            || ! MAD4B_SCP_Resilience_Context::is_hash( $original['plan_sha256'] ?? '' )
            || ! MAD4B_SCP_Resilience_Context::is_hash( $original['binding_sha256'] ?? '' )
            || ( $original['operation_sha256'] ?? '' ) !== $operation_sha256
            || ( $original['site_key'] ?? '' ) !== MAD4B_SCP_Resilience_Context::site_key( $binding ) )
            return self::blocked( 'reservation_foreign', 'Reservation belongs to another site or operation.' );
        return array(
            'contract' => self::CONTRACT, 'operation_sha256' => $operation_sha256,
            'site_key' => $original['site_key'], 'state' => 'reconciliation_required',
            'plan_sha256' => $original['plan_sha256'],
            'binding_sha256' => $original['binding_sha256'],
            'initial_fence_state' => 'fenced_not_dispatched',
            'anchor_revision' => $record['revision'],
            'external_effect_unknown' => true, 'blind_retry_allowed' => false,
            'release_accepted' => false, 'rollback_verified' => false,
            'authorizing' => false, 'mutation_performed' => false,
        );
    }
}
