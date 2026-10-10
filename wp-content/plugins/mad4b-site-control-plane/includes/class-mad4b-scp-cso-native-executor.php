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
        // The selected Site Profile is not proof of the running WordPress
        // environment. In particular, an implicit WP production default must
        // NEVER be converted into Staging write authority by profile settings.
        $live_scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $live_scope ) ||
            ! function_exists( 'wp_get_environment_type' ) ||
            ! is_string( $live_scope['environment'] ?? null ) ||
            ! in_array( $live_scope['environment'], array( 'local', 'development', 'staging' ), true ) ||
            ! hash_equals( $live_scope['environment'], (string) wp_get_environment_type() ) )
            return self::error( 'NATIVE_WORDPRESS_ENVIRONMENT_MISMATCH' );
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
            // A claim error can follow a committed DB update whose acknowledgment
            // was lost. The journal was already reserved, so NEVER treat this as
            // a terminal pre-effect denial. Require independent recovery proof.
            self::transition( $key, $journal, 'needs_reconcile', 'CLAIM_OUTCOME_UNCERTAIN' );
            return self::error( 'NATIVE_APPROVAL_CLAIM_OUTCOME_UNCERTAIN' );
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
                array( 'needs_reconcile','reserved','inflight','reconciling' ), true ),
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

    /**
     * Optional, externally witnessed recovery. The signing PRIVATE key must
     * live outside WordPress/MCP. A Site Profile, native provider snapshot,
     * local audit entry or ChatGPT assertion is NOT independent evidence.
     *
     * Deployment must pin a separate auditor's public key and identity. No
     * key means recovery closure remains disabled (fail closed).
     */
    private static function reconcile_evidence( $material, $scope, $original_id,
        $journal, $evidence, $allow_expired = false ) {
        if ( ! defined( 'MAD4B_CSO_RECONCILE_TRUSTED_PUBLIC_KEY' ) ||
            ! defined( 'MAD4B_CSO_RECONCILE_EXTERNAL_AUDITOR_ID' ) ||
            ! is_string( MAD4B_CSO_RECONCILE_TRUSTED_PUBLIC_KEY ) ||
            ! is_string( MAD4B_CSO_RECONCILE_EXTERNAL_AUDITOR_ID ) ||
            strlen( MAD4B_CSO_RECONCILE_TRUSTED_PUBLIC_KEY ) < 200 ||
            ! preg_match( '/^[a-z0-9._-]{8,96}$/D',
                MAD4B_CSO_RECONCILE_EXTERNAL_AUDITOR_ID ) ||
            ! function_exists( 'openssl_verify' ) )
            return self::error( 'NATIVE_EXTERNAL_AUDITOR_NOT_ENROLLED' );
        if ( ! is_array( $evidence ) ||
            array_keys( $evidence ) !== array( 'body', 'signature' ) ||
            ! is_array( $evidence['body'] ) ||
            ! is_string( $evidence['signature'] ) ||
            strlen( $evidence['signature'] ) > 2048 )
            return self::error( 'NATIVE_RECONCILE_EVIDENCE_SHAPE' );
        $body = $evidence['body'];
        $fields = array( 'contract', 'issuer', 'ticket_sha256', 'plan_sha256',
            'scope_sha256', 'provider_id', 'target_sha256', 'outcome',
            'observed_revision_sha256', 'observed_values_sha256', 'evidence_ref',
            'writer_fenced', 'quiesced_at', 'side_effects_excluded', 'issued_at',
            'expires_at' );
        if ( array_keys( $body ) !== $fields ||
            $body['contract'] !== self::CONTRACT . '.external-proof.v1' ||
            ! hash_equals( MAD4B_CSO_RECONCILE_EXTERNAL_AUDITOR_ID,
                (string) $body['issuer'] ) ||
            ! hash_equals( hash( 'sha256', $original_id ),
                (string) $body['ticket_sha256'] ) ||
            ! hash_equals( MAD4B_SCP_CSO_Scope::digest( $material ),
                (string) $body['plan_sha256'] ) ||
            ! hash_equals( MAD4B_SCP_CSO_Scope::digest( $scope ),
                (string) $body['scope_sha256'] ) ||
            ! hash_equals( (string) $material['provider_id'],
                (string) $body['provider_id'] ) ||
            ! hash_equals( MAD4B_SCP_CSO_Scope::digest( $material['target'] ),
                (string) $body['target_sha256'] ) ||
            ! in_array( $body['outcome'], array( 'applied', 'absent' ), true ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) $body['observed_revision_sha256'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) $body['observed_values_sha256'] ) ||
            ! is_string( $body['evidence_ref'] ) ||
            ! preg_match( '/^[A-Za-z0-9._:-]{8,191}$/D', $body['evidence_ref'] ) ||
            true !== $body['writer_fenced'] ||
            ! is_int( $body['quiesced_at'] ) ||
            $body['quiesced_at'] < (int) ( $journal['reconcile_initial_updated_at'] ?? $journal['updated_at'] ) ||
            ! is_bool( $body['side_effects_excluded'] ) ||
            ! is_int( $body['issued_at'] ) ||
            ! is_int( $body['expires_at'] ) ||
            $body['issued_at'] < (int) $journal['created_at'] ||
            $body['issued_at'] > time() + 30 ||
            $body['expires_at'] <= $body['issued_at'] ||
            $body['expires_at'] > $body['issued_at'] + 300 ||
            ( ! $allow_expired && $body['expires_at'] < time() ) ||
            ( $allow_expired && $body['expires_at'] < time() - 86400 ) ||
            ( 'absent' === $body['outcome'] && true !== $body['side_effects_excluded'] ) )
            return self::error( 'NATIVE_RECONCILE_PROOF_BINDING_INVALID' );
        $bytes = base64_decode( $evidence['signature'], true );
        $canonical = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $bytes || ! is_string( $canonical ) ||
            1 !== openssl_verify( $canonical, $bytes,
                MAD4B_CSO_RECONCILE_TRUSTED_PUBLIC_KEY, OPENSSL_ALGO_SHA256 ) )
            return self::error( 'NATIVE_RECONCILE_SIGNATURE_INVALID' );
        // A valid signature cannot overrule newer provider changes.
        $snapshot = MAD4B_SCP_CSO_Storage_Adapters::snapshot(
            $material['provider_id'], $material['target'] );
        if ( is_wp_error( $snapshot ) || ! is_array( $snapshot ) ||
            ! is_array( $snapshot['values'] ?? null ) ||
            ! is_string( $snapshot['revision'] ?? null ) ||
            ! hash_equals( $body['observed_revision_sha256'],
                hash( 'sha256', $snapshot['revision'] ) ) ||
            ! hash_equals( $body['observed_values_sha256'],
                MAD4B_SCP_CSO_Scope::digest( $snapshot['values'] ) ) )
            return self::error( 'NATIVE_RECONCILE_OBSERVATION_DRIFT' );
        $applied = $body['outcome'] === 'applied';
        $matching = true;
        foreach ( $material['values'] as $field => $wanted ) {
            if ( ! array_key_exists( $field, $snapshot['values'] ) ||
                $snapshot['values'][ $field ] !== $wanted ) {
                $matching = false;
                break;
            }
        }
        if ( ( $applied && ( ! $matching ||
                hash_equals( $material['expected_revision'], $snapshot['revision'] ) ) ) ||
            ( ! $applied && ! hash_equals(
                $material['expected_revision'], $snapshot['revision'] ) ) )
            return self::error( 'NATIVE_RECONCILE_OUTCOME_CONTRADICTED' );
        return array( 'body' => $body,
            'proof_sha256' => hash( 'sha256', $canonical . '|' . $evidence['signature'] ) );
    }

    private static function reconcile_context( $sealed, $original_id, $evidence,
        $agent_public_id, $allow_expired = false ) {
        $observed = self::status( $sealed, $original_id );
        if ( is_wp_error( $observed ) ) return $observed;
        $material = MAD4B_SCP_CSO_Scope::unseal(
            $sealed, MAD4B_SCP_CSO_Changes::CONTRACT );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $material ) || ! is_array( $material ) ||
            is_wp_error( $scope ) || ! is_array( $scope ) )
            return self::error( 'NATIVE_RECONCILE_CONTEXT_INVALID' );
        $admission = self::admission( $material );
        if ( is_wp_error( $admission ) ) return $admission;
        if ( ! method_exists( 'MAD4B_SCP_Policy', 'can_approve_mutations' ) ||
            ! MAD4B_SCP_Policy::can_approve_mutations() )
            return self::error( 'NATIVE_RECONCILE_APPROVER_DENIED' );
        $agent = self::agent( $agent_public_id );
        if ( is_wp_error( $agent ) ) return $agent;
        $payload = self::payload( $material, $scope );
        $binding = self::approval_identifiers( $material, $scope, $payload );
        $original = MAD4B_SCP_Approval_Tickets::get( $original_id );
        $expected_hash = MAD4B_SCP_Approval_Tickets::canonical_payload_hash(
            $agent['public_id'], $binding['server'], $binding['ability'],
            $binding['provider'], $binding['target_fingerprint'],
            $binding['payload'], 'mutation' );
        if ( is_wp_error( $expected_hash ) || ! is_array( $original ) ||
            (int) ( $original['agent_id'] ?? 0 ) !== (int) $agent['id'] ||
            ( $original['ticket_class'] ?? '' ) !== 'mutation' ||
            ( $original['server_id'] ?? '' ) !== self::SERVER ||
            ( $original['ability_name'] ?? '' ) !== $binding['ability'] ||
            ( $original['provider'] ?? '' ) !== $binding['provider'] ||
            ! hash_equals( (string) ( $original['target_fingerprint'] ?? '' ),
                $binding['target_fingerprint'] ) ||
            ! hash_equals( (string) ( $original['payload_sha256'] ?? '' ),
                (string) $expected_hash ) )
            return self::error( 'NATIVE_RECONCILE_ORIGINAL_TICKET_MISMATCH' );
        $key = self::journal_key( $original_id, $payload['plan_sha256'] );
        $journal = get_option( $key, null );
        if ( ! is_array( $journal ) || ! in_array( $journal['state'] ?? '',
            array( 'reserved', 'inflight', 'needs_reconcile', 'reconciling' ), true ) )
            return self::error( 'NATIVE_RECONCILE_JOURNAL_NOT_OPEN' );
        $proof = self::reconcile_evidence( $material, $scope, $original_id,
            $journal, $evidence, $allow_expired );
        if ( is_wp_error( $proof ) ) return $proof;
        $outcome = $proof['body']['outcome'];
        $original_status = (string) ( $original['status'] ?? '' );
        if ( ! in_array( $original_status,
            $outcome === 'applied' ? array( 'executing', 'used' ) :
                array( 'approved', 'executing', 'failed', 'revoked' ), true ) )
            return self::error( 'NATIVE_RECONCILE_ORIGINAL_STATUS_CONFLICT' );
        // An approved ticket can survive a crash after the journal reservation
        // but before the atomic claim. An independent ABSENT proof permits only
        // revocation, never synthesizing a claim or replaying the write.
        if ( 'approved' === $original_status &&
            ! in_array( $journal['state'], array( 'reserved', 'needs_reconcile', 'reconciling' ), true ) )
            return self::error( 'NATIVE_RECONCILE_UNCLAIMED_STATE_INVALID' );
        // A previously revoked original is resumable only after this exact
        // recovery proof and ticket have already been pinned by journal CAS.
        if ( 'revoked' === $original_status && 'reconciling' !== $journal['state'] )
            return self::error( 'NATIVE_RECONCILE_REVOCATION_NOT_PINNED' );
        return compact( 'agent', 'binding', 'journal', 'key', 'proof', 'original', 'outcome' );
    }

    private static function recovery_binding( $context, $original_id ) {
        return array(
            'server' => self::SERVER,
            'ability' => 'mad4b-cso/reconcile-finalize',
            'provider' => $context['binding']['provider'],
            'fingerprint' => MAD4B_SCP_CSO_Scope::digest(
                array( $original_id, $context['binding']['target_fingerprint'] ) ),
            'payload' => array( 'contract' => self::CONTRACT . '.recovery-ticket.v1',
                'original_ticket_sha256' => hash( 'sha256', $original_id ),
                'plan_sha256' => $context['binding']['payload']['plan_sha256'],
                'proof_sha256' => $context['proof']['proof_sha256'],
                'outcome' => $context['outcome'] ),
        );
    }

    /** Prepare a SECOND, separately reviewed one-use recovery approval. */
    public static function reconcile_approval_plan( $sealed, $original_id, $evidence,
        $agent_public_id, $reason ) {
        if ( ! is_string( $reason ) || strlen( $reason ) < 3 || strlen( $reason ) > 500 )
            return self::error( 'NATIVE_RECONCILE_REASON_INVALID' );
        $ctx = self::reconcile_context( $sealed, $original_id, $evidence,
            $agent_public_id );
        if ( is_wp_error( $ctx ) ) return $ctx;
        if ( $ctx['journal']['state'] === 'reconciling' )
            return self::error( 'NATIVE_RECONCILE_ALREADY_CLAIMED' );
        $r = self::recovery_binding( $ctx, $original_id );
        $ticket = MAD4B_SCP_Approval_Tickets::create_pending(
            $ctx['agent']['public_id'], $r['server'], $r['ability'],
            $r['provider'], $r['fingerprint'], $r['payload'],
            'recovery', $reason, 300 );
        if ( is_wp_error( $ticket ) ) return $ticket;
        return array( 'contract' => self::CONTRACT . '.recovery-plan.v1',
            'ticket_id' => $ticket['ticket_id'], 'status' => 'pending',
            'original_ticket_sha256' => hash( 'sha256', $original_id ),
            'proof_sha256' => $ctx['proof']['proof_sha256'],
            'operator_approval_required' => true, 'mutation_performed' => false );
    }

    /**
     * Close original ticket + journal without replaying provider. Interrupted
     * closures may resume only with EXACT same signed proof and recovery ticket.
     * An auditor must independently attest the write outcome and worker fencing.
     */
    public static function reconcile_finalize( $sealed, $original_id, $evidence,
        $agent_public_id, $recovery_ticket_id ) {
        if ( ! is_string( $recovery_ticket_id ) || strlen( $recovery_ticket_id ) > 128 )
            return self::error( 'NATIVE_RECOVERY_TICKET_INVALID' );
        $ctx = self::reconcile_context( $sealed, $original_id, $evidence,
            $agent_public_id, false );
        if ( is_wp_error( $ctx ) ) {
            // Only an already pinned recovery may resume with an expired
            // signature; a fresh unclaimed proof must always be in TTL.
            $status = self::status( $sealed, $original_id );
            if ( is_wp_error( $status ) || ( $status['status'] ?? '' ) !== 'reconciling' )
                return $ctx;
            $ctx = self::reconcile_context( $sealed, $original_id, $evidence,
                $agent_public_id, true );
            if ( is_wp_error( $ctx ) ) return $ctx;
        }
        $r = self::recovery_binding( $ctx, $original_id );
        $held = $ctx['journal']['state'] === 'reconciling';
        if ( $held ) {
            if ( ! hash_equals( (string) ( $ctx['journal']['recovery_ticket_sha256'] ?? '' ),
                    hash( 'sha256', $recovery_ticket_id ) ) ||
                ! hash_equals( (string) ( $ctx['journal']['reconcile_proof_sha256'] ?? '' ),
                    $ctx['proof']['proof_sha256'] ) ||
                ( $ctx['journal']['reconcile_outcome'] ?? '' ) !== $ctx['outcome'] )
                return self::error( 'NATIVE_RECONCILE_LOCK_CONFLICT' );
        } else {
            $auth = MAD4B_SCP_Approval_Tickets::authorize_exact(
                $recovery_ticket_id, $ctx['agent'], $r['server'], $r['ability'],
                $r['provider'], $r['fingerprint'], $r['payload'], 'recovery' );
            if ( is_wp_error( $auth ) ) return $auth;
            $next = $ctx['journal'];
            $next['state'] = 'reconciling';
            $next['reconcile_initial_updated_at'] = $ctx['journal']['updated_at'];
            $next['recovery_ticket_sha256'] = hash( 'sha256', $recovery_ticket_id );
            $next['reconcile_proof_sha256'] = $ctx['proof']['proof_sha256'];
            $next['reconcile_outcome'] = $ctx['outcome'];
            $next['updated_at'] = time();
            $next['reason_code'] = 'EXTERNAL_PROOF_PINNED';
            $ok = self::journal_cas( $ctx['key'], $ctx['journal'], $next );
            if ( is_wp_error( $ok ) ) return $ok;
            $ctx['journal'] = $next;
        }
        $recovery = MAD4B_SCP_Approval_Tickets::get( $recovery_ticket_id );
        if ( ! is_array( $recovery ) ||
            (int) ( $recovery['agent_id'] ?? 0 ) !== (int) $ctx['agent']['id'] ||
            ( $recovery['ticket_class'] ?? '' ) !== 'recovery' ||
            ( $recovery['server_id'] ?? '' ) !== $r['server'] ||
            ( $recovery['ability_name'] ?? '' ) !== $r['ability'] ||
            ( $recovery['provider'] ?? '' ) !== $r['provider'] ||
            ( $recovery['target_fingerprint'] ?? '' ) !== $r['fingerprint'] )
            return self::error( 'NATIVE_RECOVERY_APPROVAL_CHANGED' );
        $hash = MAD4B_SCP_Approval_Tickets::canonical_payload_hash(
            $ctx['agent']['public_id'], $r['server'], $r['ability'],
            $r['provider'], $r['fingerprint'], $r['payload'], 'recovery' );
        if ( is_wp_error( $hash ) || ! hash_equals(
            (string) ( $recovery['payload_sha256'] ?? '' ), (string) $hash ) )
            return self::error( 'NATIVE_RECOVERY_APPROVAL_PAYLOAD_CHANGED' );
        if ( ( $recovery['status'] ?? '' ) === 'approved' ) {
            $claimed = MAD4B_SCP_Approval_Tickets::claim_exact(
                $recovery_ticket_id, $ctx['agent'], $r['server'], $r['ability'],
                $r['provider'], $r['fingerprint'], $r['payload'], 'recovery' );
            if ( is_wp_error( $claimed ) ) return $claimed;
        } elseif ( ! in_array( (string) ( $recovery['status'] ?? '' ),
            array( 'executing', 'used' ), true ) ) {
            return self::error( 'NATIVE_RECOVERY_APPROVAL_NOT_CLAIMED' );
        }
        if ( ( $ctx['original']['status'] ?? '' ) === 'executing' ) {
            $terminal = $ctx['outcome'] === 'applied' ? 'used' : 'failed';
            $closed = MAD4B_SCP_Approval_Tickets::finalize_claim( $original_id,
                $terminal, 'EXTERNALLY_RECONCILED' );
            if ( is_wp_error( $closed ) ) return $closed;
        } elseif ( 'approved' === ( $ctx['original']['status'] ?? '' ) ) {
            // No native effect is certified. Revoke the unused original ticket
            // after the recovery ticket has been claimed; never claim it.
            if ( 'absent' !== $ctx['outcome'] )
                return self::error( 'NATIVE_RECONCILE_UNCLAIMED_EFFECT_CONFLICT' );
            $closed = MAD4B_SCP_Approval_Tickets::revoke( $original_id );
            if ( is_wp_error( $closed ) ) return $closed;
        }
        $recovery = MAD4B_SCP_Approval_Tickets::get( $recovery_ticket_id );
        if ( is_array( $recovery ) && ( $recovery['status'] ?? '' ) === 'executing' ) {
            $closed = MAD4B_SCP_Approval_Tickets::finalize_claim(
                $recovery_ticket_id, 'used' );
            if ( is_wp_error( $closed ) ) return $closed;
        }
        $original = MAD4B_SCP_Approval_Tickets::get( $original_id );
        $recovery = MAD4B_SCP_Approval_Tickets::get( $recovery_ticket_id );
        if ( ! is_array( $original ) || ! is_array( $recovery ) ||
            ! in_array( (string) ( $original['status'] ?? '' ),
                $ctx['outcome'] === 'applied' ? array( 'used' ) :
                    array( 'failed', 'revoked' ), true ) ||
            ( $recovery['status'] ?? '' ) !== 'used' )
            return self::error( 'NATIVE_RECONCILE_FINAL_READBACK_UNCERTAIN' );
        $next = $ctx['journal'];
        $next['state'] = $ctx['outcome'] === 'applied' ?
            'reconciled_applied' : 'reconciled_absent';
        $next['updated_at'] = time();
        $next['reason_code'] = 'EXTERNALLY_ATTESTED_FINALIZED';
        $ok = self::journal_cas( $ctx['key'], $ctx['journal'], $next );
        if ( is_wp_error( $ok ) ) return $ok;
        return array( 'contract' => self::CONTRACT . '.recovery-receipt.v1',
            'status' => $next['state'], 'proof_sha256' => $ctx['proof']['proof_sha256'],
            'original_ticket_terminal' => $original['status'],
            'recovery_ticket_terminal' => $recovery['status'],
            'provider_write_replayed' => false, 'production_promotion_authorized' => false );
    }

}
