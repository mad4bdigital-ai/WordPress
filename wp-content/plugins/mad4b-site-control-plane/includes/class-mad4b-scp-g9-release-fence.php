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

        // This is an internal, typed reservation only. It is not exposed as an
        // Ability, and can never substitute for provider-specific authorization.
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || true !== MAD4B_SCP_Site_Profile::nonproduction_governed()
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
        if ( is_wp_error( $auth ) || true !== $auth )
            return self::blocked( 'not_admitted', 'Existing governed executor did not admit the exact reservation.' );

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
     * Correlate an existing native operation with a signed execution receipt.
     * This never concludes release acceptance: the site-local host, provider,
     * rendered and external-effect acceptance remain independent requirements.
     * Unknown/contradictory journal state can never be called committed.
     */
    public static function native_execution_evidence( array $binding, $operation_sha256, $operation_id, array $receipt ) {
        $reservation = self::inspect( $binding, $operation_sha256 );
        if ( is_wp_error( $reservation ) ) return $reservation;
        if ( ! is_string( $operation_id )
            || 1 !== preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9:_-]{3,127}$/D', $operation_id ) )
            return self::blocked( 'native_operation_invalid', 'Exact native operation identifier is required.' );
        if ( ! class_exists( 'MAD4B_SCP_Execution_State_View' )
            || ! class_exists( 'MAD4B_SCP_Execution_Receipt' ) )
            return self::blocked( 'native_evidence_unavailable', 'Existing execution state and signed receipt verifier are unavailable.' );
        if ( ( $receipt['request_id'] ?? '' ) !== $operation_id
            || ( $receipt['target_fingerprint'] ?? '' ) !== $operation_sha256 )
            return self::blocked( 'native_receipt_unbound', 'Native receipt does not identify this exact fenced operation.' );
        $verified = MAD4B_SCP_Execution_Receipt::verify( $receipt );
        if ( is_wp_error( $verified ) || ! is_array( $verified )
            || empty( $verified['valid'] ) || empty( $verified['cryptographic_signature_verified'] ) )
            return self::blocked( 'native_signature_invalid', 'Native execution receipt signature is unavailable or invalid.' );
        $state = MAD4B_SCP_Execution_State_View::operation( $operation_id );
        if ( is_wp_error( $state ) || ! is_array( $state )
            || ( $state['contract'] ?? '' ) !== MAD4B_SCP_Execution_State_View::CONTRACT
            || ( $state['canonical_state'] ?? '' ) !== MAD4B_SCP_Execution_State_View::COMMITTED
            || empty( $state['terminal'] ) || ! empty( $state['reconciliation_required'] ) )
            return self::blocked( 'native_execution_uncertain', 'Canonical execution state is missing, contradictory or uncommitted.' );
        return array(
            'contract' => 'mad4b.g9.native-execution-evidence.v1',
            'operation_sha256' => $operation_sha256,
            'native_operation_id' => $operation_id,
            'signed_receipt_sha256' => $verified['receipt_sha256'] ?? '',
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
        if ( ( $original['operation_sha256'] ?? '' ) !== $operation_sha256
            || ( $original['site_key'] ?? '' ) !== MAD4B_SCP_Resilience_Context::site_key( $binding ) )
            return self::blocked( 'reservation_foreign', 'Reservation belongs to another site or operation.' );
        return array(
            'contract' => self::CONTRACT, 'operation_sha256' => $operation_sha256,
            'site_key' => $original['site_key'], 'state' => 'reconciliation_required',
            'initial_fence_state' => 'fenced_not_dispatched',
            'anchor_revision' => $record['revision'],
            'external_effect_unknown' => true, 'blind_retry_allowed' => false,
            'release_accepted' => false, 'rollback_verified' => false,
            'authorizing' => false, 'mutation_performed' => false,
        );
    }
}
