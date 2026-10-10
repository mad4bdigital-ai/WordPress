<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * CSO governed native-write bridge, deliberately OFF until exact provider
 * enrollment AND native Approval Ticket/Ability/Execution Fence verification.
 * No direct SQL/meta/option writes except its own append-before-effect journal.
 */
final class MAD4B_SCP_CSO_Native_Executor {
    const CONTRACT = 'mad4b.cso.native-executor.v1';
    const SERVER = 'mad4b-write';
    private static $active_native_digest = '';

    private static function error( $code ) { return MAD4B_SCP_CSO_Scope::error( $code ); }

    private static function material( $sealed ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'single_write' ) ||
            ! MAD4B_SCP_CSO_Scope::first_party_session() ||
            ! is_array( $sealed ) )
            return self::error( 'NATIVE_SESSION_OR_FLAG_DENIED' );
        $material = MAD4B_SCP_CSO_Scope::unseal( $sealed, MAD4B_SCP_CSO_Changes::CONTRACT );
        if ( is_wp_error( $material ) || ! is_array( $material ) ||
            ( $material['contract'] ?? '' ) !== MAD4B_SCP_CSO_Changes::CONTRACT ||
            ! is_int( $material['expires_at'] ?? null ) ||
            $material['expires_at'] <= time() ||
            ! empty( $material['write_authorized'] ) ||
            ! is_array( $material['target'] ?? null ) ||
            ! is_array( $material['values'] ?? null ) ||
            count( $material['values'] ) > 32 ||
            ! MAD4B_SCP_CSO_Scope::safe_data( $material['values'] ) )
            return self::error( 'NATIVE_PLAN_STALE_OR_UNTRUSTED' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) || ! isset( $scope['binding_sha256'], $scope['actor_sha256'] ) ||
            ! hash_equals( (string) ( $material['scope_fingerprint'] ?? '' ),
                (string) $scope['binding_sha256'] ) ||
            ! hash_equals( (string) ( $material['actor_sha256'] ?? '' ),
                (string) $scope['actor_sha256'] ) )
            return self::error( 'NATIVE_SCOPE_CHANGED' );
        return array( 'material' => $material, 'scope' => $scope );
    }

    private static function payload( $material, $scope ) {
        // This exact payload is bound to both approval and the native Ability.
        return array(
            'contract' => self::CONTRACT,
            'provider_id' => $material['provider_id'],
            'target' => $material['target'],
            'expected_revision' => $material['expected_revision'],
            'descriptor_sha256' => $material['descriptor_sha256'],
            'values' => $material['values'],
            'plan_sha256' => MAD4B_SCP_CSO_Scope::digest( $material ),
            'scope_sha256' => MAD4B_SCP_CSO_Scope::digest( $scope ),
        );
    }

    /**
     * The native callback must be uncallable without an approved, claimed,
     * exact one-use ticket in this SAME PHP request. Direct WordPress Ability
     * execute and direct PHP invocation cannot supply this private permit.
     */
    public static function native_permit_matches( $input, $consume = false ) {
        $matched = is_array( $input ) && '' !== self::$active_native_digest &&
            hash_equals( self::$active_native_digest, MAD4B_SCP_CSO_Scope::digest( $input ) );
        if ( $matched && true === $consume ) self::$active_native_digest = '';
        return $matched;
    }

    private static function execute_native_once( $ability, $payload ) {
        if ( '' !== self::$active_native_digest )
            return self::error( 'NATIVE_REENTRANT_EXECUTION_DENIED' );
        self::$active_native_digest = MAD4B_SCP_CSO_Scope::digest( $payload );
        try {
            if ( true !== $ability->check_permissions( $payload ) )
                return self::error( 'NATIVE_ABILITY_PERMISSION_REVOKED' );
            return $ability->execute( $payload );
        } finally {
            self::$active_native_digest = '';
        }
    }

    private static function admission( $material ) {
        if ( ! class_exists( 'MAD4B_SCP_Approval_Tickets', false ) ||
            ! class_exists( 'MAD4B_SCP_Execution_Fence', false ) ||
            ! class_exists( 'MAD4B_SCP_Operational_Integrity', false ) ||
            ! class_exists( 'MAD4B_SCP_Database_Topology', false ) ||
            ! class_exists( 'MAD4B_SCP_Write_Runtime_Certification', false ) ||
            ! class_exists( 'MAD4B_SCP_Agent_Registry', false ) ||
            ! class_exists( 'MAD4B_SCP_Policy', false ) ||
            ! MAD4B_SCP_Policy::can_mutate() )
            return self::error( 'NATIVE_AUTHORITY_UNAVAILABLE' );
        $certificate = MAD4B_SCP_Write_Runtime_Certification::current_status();
        if ( ! is_array( $certificate ) || empty( $certificate['ready'] ) ||
            empty( $certificate['current_truth'] ) )
            return self::error( 'NATIVE_CURRENT_RUNTIME_NOT_CERTIFIED' );
        $ability_name = $material['native_write_ability'] ?? '';
        if ( ! is_string( $ability_name ) || ! function_exists( 'wp_get_ability' ) ||
            ! is_object( wp_get_ability( $ability_name ) ) ||
            ! MAD4B_SCP_Execution_Fence::final_execution_wrapper_verified( $ability_name ) )
            return self::error( 'NATIVE_EXECUTION_FENCE_UNVERIFIED' );
        $ability = wp_get_ability( $ability_name );
        if ( ! method_exists( $ability, 'get_meta' ) ||
            ! method_exists( $ability, 'check_permissions' ) ||
            ! method_exists( $ability, 'execute' ) )
            return self::error( 'NATIVE_WRITE_ABILITY_INVALID' );
        $meta = $ability->get_meta();
        if ( ! is_array( $meta ) ||
            true !== ( $meta['mcp']['mad4b_cso_certified_write'] ?? false ) ||
            false !== ( $meta['annotations']['readonly'] ?? null ) ||
            true !== ( $meta['mcp']['governed_write'] ?? false ) )
            return self::error( 'NATIVE_WRITE_CERTIFICATION_MISSING' );
        $topology = MAD4B_SCP_Database_Topology::assert_write_ready( true );
        return is_wp_error( $topology ) ? $topology : array( 'ability' => $ability, 'topology' => $topology );
    }

    private static function approval_identifiers( $material, $scope, $payload ) {
        return array( 'server' => self::SERVER,
            'ability' => (string) $material['native_write_ability'],
            'provider' => (string) $material['provider_id'],
            'target_fingerprint' => MAD4B_SCP_CSO_Scope::digest(
                array( $scope['binding_sha256'], $material['target'],
                    $material['expected_revision'] ) ),
            'payload' => $payload );
    }

    private static function agent( $public_id ) {
        if ( ! is_string( $public_id ) ||
            ! preg_match( '/^[a-zA-Z0-9._:-]{4,128}$/D', $public_id ) )
            return self::error( 'NATIVE_AGENT_ID_INVALID' );
        $agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $public_id );
        return is_array( $agent ) && ( $agent['status'] ?? '' ) === 'enabled' &&
            isset( $agent['id'], $agent['public_id'] ) ?
            $agent : self::error( 'NATIVE_AGENT_NOT_ENROLLED' );
    }

    /** Creates a PENDING exact native ticket. It does not approve it. */
    public static function approval_plan( $sealed, $reason, $agent_public_id ) {
        $bundle = self::material( $sealed );
        if ( is_wp_error( $bundle ) ) return $bundle;
        if ( ! is_string( $reason ) || strlen( $reason ) < 3 || strlen( $reason ) > 500 )
            return self::error( 'NATIVE_APPROVAL_REASON_INVALID' );
        $admission = self::admission( $bundle['material'] );
        if ( is_wp_error( $admission ) ) return $admission;
        $agent = self::agent( $agent_public_id );
        if ( is_wp_error( $agent ) ) return $agent;
        $scope = $bundle['scope'];
        $body = self::payload( $bundle['material'], $scope );
        $identity = self::approval_identifiers( $bundle['material'], $scope, $body );
        $fresh = MAD4B_SCP_CSO_Scope::assert_current( $scope );
        if ( true !== $fresh ) return self::error( 'NATIVE_APPROVAL_SCOPE_CHANGED' );
        $ticket = MAD4B_SCP_Approval_Tickets::create_pending(
            $agent['public_id'], $identity['server'], $identity['ability'],
            $identity['provider'], $identity['target_fingerprint'],
            $identity['payload'], 'mutation', $reason, 300 );
        if ( is_wp_error( $ticket ) ) return $ticket;
        return array( 'contract' => self::CONTRACT . '.approval-plan.v1',
            'ticket_id' => $ticket['ticket_id'] ?? '',
            'status' => 'pending', 'approved' => false, 'executed' => false,
            'plan_sha256' => $body['plan_sha256'], 'operator_approval_required' => true );
    }

    private static function journal_key( $ticket_id, $plan_digest ) {
        return 'mad4b_cso_write_' . hash( 'sha256', $ticket_id . '|' . $plan_digest );
    }

    private static function journal_cas( $key, $previous, $next ) {
        global $wpdb;
        $prev = maybe_serialize( $previous );
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
            maybe_serialize( $next ), $key, $prev ) );
        wp_cache_delete( $key, 'options' );
        return 1 === (int) $updated && empty( $wpdb->last_error ) ?
            true : self::error( 'NATIVE_JOURNAL_PERSISTENCE_UNCERTAIN' );
    }

    private static function transition( $key, &$journal, $status, $code = '' ) {
        $updated = $journal;
        $updated['state'] = $status;
        $updated['updated_at'] = time();
        $updated['reason_code'] = $code;
        $ok = self::journal_cas( $key, $journal, $updated );
        if ( is_wp_error( $ok ) ) return $ok;
        $journal = $updated;
        return true;
    }

    /**
     * One-shot native Ability execution, with preeffect durable journal.
     * Ambiguous outcomes cannot be retried; the same ticket and plan key are
     * irreversible and require independently authorized reconciliation.
     */
    public static function commit( $sealed, $governance ) {
        if ( ! is_array( $governance ) ||
            array_diff( array_keys( $governance ), array( 'ticket_id','agent_public_id' ) ) ||
            ! is_string( $governance['ticket_id'] ?? null ) ||
            ! is_string( $governance['agent_public_id'] ?? null ) )
            return self::error( 'NATIVE_GOVERNANCE_INPUT_INVALID' );
        $bundle = self::material( $sealed );
        if ( is_wp_error( $bundle ) ) return $bundle;
        $material = $bundle['material']; $scope = $bundle['scope'];
        $admission = self::admission( $material );
        if ( is_wp_error( $admission ) ) return $admission;
        $agent = self::agent( $governance['agent_public_id'] );
        if ( is_wp_error( $agent ) ) return $agent;
        $payload = self::payload( $material, $scope );
        $binding = self::approval_identifiers( $material, $scope, $payload );
        $ticket_id = $governance['ticket_id'];
        $approved = MAD4B_SCP_Approval_Tickets::authorize_exact(
            $ticket_id, $agent, $binding['server'], $binding['ability'],
            $binding['provider'], $binding['target_fingerprint'],
            $binding['payload'], 'mutation' );
        if ( is_wp_error( $approved ) ) return $approved;

        // Revocation and revision drift detected before reserving effect.
        $snapshot = MAD4B_SCP_CSO_Storage_Adapters::snapshot(
            $material['provider_id'], $material['target'] );
        if ( is_wp_error( $snapshot ) ||
            ! hash_equals( $material['expected_revision'], (string) ( $snapshot['revision'] ?? '' ) ) ||
            ! hash_equals( $material['descriptor_sha256'],
                (string) ( $snapshot['descriptor']['descriptor_sha256'] ?? '' ) ) )
            return self::error( 'NATIVE_PRE_EFFECT_REVISION_STALE' );
        $checkpoint = MAD4B_SCP_Operational_Integrity::capture();
        if ( is_wp_error( $checkpoint ) ) return $checkpoint;
        $pre = MAD4B_SCP_Operational_Integrity::assert_unchanged( $checkpoint, true );
        if ( is_wp_error( $pre ) || true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'NATIVE_EFFECT_SCOPE_UNAUTHORIZED' );
        // Native permission is rechecked only inside an exact one-use
        // in-request permit AFTER durable reservation and ticket claim.
        $journal = array(
            'contract' => self::CONTRACT . '.journal.v1',
            'ticket_sha256' => hash( 'sha256', $ticket_id ),
            'plan_sha256' => $payload['plan_sha256'],
            'scope_sha256' => $payload['scope_sha256'],
            'provider_id' => $material['provider_id'],
            'target_sha256' => MAD4B_SCP_CSO_Scope::digest( $material['target'] ),
            'state' => 'reserved', 'reason_code' => '',
            'created_at' => time(), 'updated_at' => time() );
        $key = self::journal_key( $ticket_id, $payload['plan_sha256'] );
        // Atomic unique key prevents concurrent replay even before ticket claim.
        if ( ! add_option( $key, $journal, '', false ) )
            return self::error( 'NATIVE_REPLAY_OR_UNKNOWN_EFFECT' );
        $readback = get_option( $key, null );
        if ( $readback !== $journal )
            return self::error( 'NATIVE_JOURNAL_READBACK_UNCERTAIN' );
        $claimed = MAD4B_SCP_Approval_Tickets::claim_exact(
            $ticket_id, $agent, $binding['server'], $binding['ability'],
            $binding['provider'], $binding['target_fingerprint'],
            $binding['payload'], 'mutation' );
        if ( is_wp_error( $claimed ) ) {
            self::transition( $key, $journal, 'claim_denied', 'CLAIM_FAILED' );
            return self::error( 'NATIVE_APPROVAL_CLAIM_FAILED' );
        }
        $inflight = self::transition( $key, $journal, 'inflight' );
        if ( is_wp_error( $inflight ) )
            return self::error( 'NATIVE_INFLIGHT_JOURNAL_UNCERTAIN' );
        $pre = MAD4B_SCP_Operational_Integrity::assert_unchanged( $checkpoint, true );
        if ( is_wp_error( $pre ) || true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'NATIVE_POST_CLAIM_SCOPE_CHANGED' );
        try {
            $result = self::execute_native_once( $admission['ability'], $payload );
        } catch ( \Throwable $error ) {
            self::transition( $key, $journal, 'needs_reconcile', 'NATIVE_THROWN' );
            return self::error( 'NATIVE_EFFECT_UNCERTAIN' );
        }
        // Any error from an external callback can mean partial effects. Never
        // replay or finalize as failed without independent evidence.
        if ( is_wp_error( $result ) || ! is_array( $result ) ||
            ( $result['status'] ?? '' ) !== 'written' ) {
            self::transition( $key, $journal, 'needs_reconcile', 'NATIVE_RESULT_UNCERTAIN' );
            return self::error( 'NATIVE_EFFECT_UNCERTAIN' );
        }
        $readback = MAD4B_SCP_CSO_Storage_Adapters::snapshot(
            $material['provider_id'], $material['target'] );
        $matches = ! is_wp_error( $readback ) &&
            (string) ( $readback['revision'] ?? '' ) !== (string) $material['expected_revision'];
        foreach ( $material['values'] as $field => $expected ) {
            if ( ! $matches || ! array_key_exists( $field, $readback['values'] ) ||
                $readback['values'][ $field ] !== $expected ) { $matches = false; break; }
        }
        if ( ! $matches || is_wp_error( MAD4B_SCP_Operational_Integrity::assert_unchanged( $checkpoint, true ) ) ) {
            self::transition( $key, $journal, 'needs_reconcile', 'READBACK_OR_SCOPE_UNVERIFIED' );
            return self::error( 'NATIVE_INDEPENDENT_READBACK_REQUIRED' );
        }
        $done = MAD4B_SCP_Approval_Tickets::finalize_claim( $ticket_id, 'used' );
        if ( is_wp_error( $done ) ) {
            self::transition( $key, $journal, 'needs_reconcile', 'TICKET_FINALIZE_UNCERTAIN' );
            return self::error( 'NATIVE_APPROVAL_FINALIZE_UNCERTAIN' );
        }
        $verified = self::transition( $key, $journal, 'verified' );
        if ( is_wp_error( $verified ) ) return $verified;
        return array( 'contract' => self::CONTRACT . '.receipt.v1',
            'status' => 'verified', 'ticket_sha256' => $journal['ticket_sha256'],
            'plan_sha256' => $payload['plan_sha256'],
            'postwrite_revision' => $readback['revision'],
            'native_ability' => $binding['ability'],
            'readback_matched' => true, 'write_executed' => true,
            'production_promotion_authorized' => false );
    }

    /** Status is only an owner-bound lookup, never a retry/repair operation. */
    public static function status( $sealed, $ticket_id ) {
        // The execution TTL does not hide recovery evidence. Expired plans
        // may be inspected by their exact actor, but never executed again.
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'single_write' ) ||
            ! MAD4B_SCP_CSO_Scope::first_party_session() ||
            ! is_array( $sealed ) || ! is_string( $ticket_id ) ||
            strlen( $ticket_id ) > 128 )
            return self::error( 'NATIVE_STATUS_SCOPE_INVALID' );
        $material = MAD4B_SCP_CSO_Scope::unseal(
            $sealed, MAD4B_SCP_CSO_Changes::CONTRACT );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $material ) || is_wp_error( $scope ) ||
            ! is_array( $material ) ||
            ( $material['contract'] ?? '' ) !== MAD4B_SCP_CSO_Changes::CONTRACT ||
            ! hash_equals( (string) ( $material['scope_fingerprint'] ?? '' ),
                (string) ( $scope['binding_sha256'] ?? '' ) ) ||
            ! hash_equals( (string) ( $material['actor_sha256'] ?? '' ),
                (string) ( $scope['actor_sha256'] ?? '' ) ) )
            return self::error( 'NATIVE_STATUS_SCOPE_INVALID' );
        $plan_sha = MAD4B_SCP_CSO_Scope::digest( $material );
        $key = self::journal_key( $ticket_id, $plan_sha );
        $record = get_option( $key, null );
        if ( ! is_array( $record ) ||
            ( $record['contract'] ?? '' ) !== self::CONTRACT . '.journal.v1' ||
            ! hash_equals( (string) ( $record['scope_sha256'] ?? '' ),
                MAD4B_SCP_CSO_Scope::digest( $scope ) ) ||
            ! hash_equals( (string) ( $record['plan_sha256'] ?? '' ), $plan_sha ) )
            return self::error( 'NATIVE_OPERATION_NOT_OWNED_OR_FOUND' );
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'NATIVE_STATUS_SCOPE_CHANGED' );
        return array( 'contract' => self::CONTRACT . '.status.v1',
            'status' => $record['state'],
            'reason_code' => $record['reason_code'],
            'replay_allowed' => false,
            'needs_independent_reconcile' => in_array( $record['state'],
                array( 'needs_reconcile','reserved','inflight' ), true ),
            'mutation_performed' => false );
    }

    /**
     * Recovery observation ONLY, never a certification, retry, journal update,
     * approval finalization or automatic compensation. Existing native provider
     * snapshot is *not* an external independent attestation.
     */
    public static function reconcile_inspect( $sealed, $ticket_id ) {
        $status = self::status( $sealed, $ticket_id );
        if ( is_wp_error( $status ) ) return $status;
        $material = MAD4B_SCP_CSO_Scope::unseal(
            $sealed, MAD4B_SCP_CSO_Changes::CONTRACT );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $material ) || ! is_array( $material ) ||
            is_wp_error( $scope ) ||
            ! is_array( $material['values'] ?? null ) ||
            ! is_array( $material['target'] ?? null ) ||
            ! is_string( $material['provider_id'] ?? null ) ||
            ! is_string( $material['expected_revision'] ?? null ) ||
            true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'NATIVE_RECONCILE_SCOPE_INVALID' );
        $observed = MAD4B_SCP_CSO_Storage_Adapters::snapshot(
            $material['provider_id'], $material['target'] );
        if ( is_wp_error( $observed ) || ! is_array( $observed ) ||
            ! is_array( $observed['values'] ?? null ) ||
            ! is_string( $observed['revision'] ?? null ) ) {
            return array(
                'contract' => self::CONTRACT . '.reconcile-observation.v1',
                'journal_status' => $status['status'],
                'observation_available' => false,
                'provider_values_match' => null,
                'native_revision_changed' => null,
                'effect_verified_independently' => false,
                'journal_mutated' => false, 'replay_allowed' => false,
                'next_safe_action' => 'collect_external_independent_readback' );
        }
        $match = true;
        foreach ( $material['values'] as $field => $wanted ) {
            if ( ! is_string( $field ) ||
                ! array_key_exists( $field, $observed['values'] ) ||
                $observed['values'][ $field ] !== $wanted ) {
                $match = false;
                break;
            }
        }
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'NATIVE_RECONCILE_SCOPE_CHANGED' );
        return array(
            'contract' => self::CONTRACT . '.reconcile-observation.v1',
            'journal_status' => $status['status'],
            'observation_available' => true,
            'provider_values_match' => $match,
            'native_revision_changed' => ! hash_equals(
                $material['expected_revision'], $observed['revision'] ),
            'effect_verified_independently' => false,
            'journal_mutated' => false, 'replay_allowed' => false,
            'next_safe_action' => 'collect_external_independent_readback' );
    }
}
