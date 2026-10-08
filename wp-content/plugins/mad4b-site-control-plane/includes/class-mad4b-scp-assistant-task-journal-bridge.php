<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Staging-only assistant ticket persistence in the existing operation journal.
 * Internal operator service; no WordPress Ability, AJAX endpoint, auto-approval,
 * provider executor, package lifecycle, new table, or separate shadow journal.
 * A separate governed owner must first create the exact operation head.
 */
final class MAD4B_SCP_Assistant_Task_Journal_Bridge {
    const CONTRACT = 'mad4b.assistant-task-journal-bridge.v1';

    private static function deny( $reason ) {
        return new WP_Error( 'mad4b_assistant_journal_' . $reason,
            'The governed assistant ticket could not be durably certified.',
            array( 'authorizing' => false, 'provider_entry_allowed' => false,
                'reconciliation_required' => in_array( $reason, array( 'write_uncertain', 'readback_failed' ), true ),
                'blind_retry_allowed' => false ) );
    }

    private static function preflight( array $context, $writing = false ) {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) return self::deny( 'operator_required' );
        if ( ! class_exists( 'MAD4B_SCP_Adaptive_Operations_Context', false )
            || ! class_exists( 'MAD4B_SCP_Operation_Journal', false )
            || ! class_exists( 'MAD4B_SCP_Assistant_Task_Contract', false ) ) return self::deny( 'prerequisite_missing' );
        $current = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( is_wp_error( $current ) || ! is_array( $current ) || ( $current['environment'] ?? '' ) !== 'staging' ) return self::deny( 'staging_required' );
        $keys = array( 'site_uuid', 'environment', 'profile_digest', 'origin_sha256',
            'runtime_generation', 'artifact_sha256', 'restore_epoch', 'external_record_sha256' );
        $values = array();
        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $current ) ) return self::deny( 'identity_incomplete' );
            $values[] = $current[ $key ];
        }
        $digest = hash( 'sha256', serialize( $values ) );
        if ( ! isset( $context['operation_binding_sha256'], $context['operation_id'], $context['operation_key'], $context['hard_deadline_at'] )
            || ! is_string( $context['operation_binding_sha256'] )
            || ! hash_equals( $digest, $context['operation_binding_sha256'] )
            || ! is_string( $context['operation_id'] ) || ! is_string( $context['operation_key'] ) ) return self::deny( 'identity_mismatch' );
        $deadline = is_string( $context['hard_deadline_at'] ) ? strtotime( $context['hard_deadline_at'] ) : false;
        if ( false === $deadline || $deadline > time() + DAY_IN_SECONDS || ( $writing && $deadline <= time() ) ) return self::deny( 'deadline_invalid' );
        return $digest;
    }

    private static function metadata( array $record ) {
        return array( 'task_id' => $record['task_id'],
            'plan_sha256' => $record['plan_sha256'],
            'binding_sha256' => $record['binding_sha256'],
            'task_revision' => $record['revision'],
            'task_state' => $record['state'],
            'task_event_sha256' => $record['last_event_sha256'] );
    }

    private static function record_from_metadata( $metadata, $transition = false ) {
        $fields = array( 'task_id', 'plan_sha256', 'binding_sha256', 'task_revision',
            'task_state', 'task_event_sha256' );
        if ( $transition ) $fields[] = 'reason_code';
        if ( ! is_array( $metadata ) || array_keys( $metadata ) !== $fields ) {
            return self::deny( 'journal_record_invalid' );
        }
        return array( 'contract' => MAD4B_SCP_Assistant_Task_Contract::CONTRACT,
            'task_id' => $metadata['task_id'], 'plan_sha256' => $metadata['plan_sha256'],
            'binding_sha256' => $metadata['binding_sha256'], 'revision' => $metadata['task_revision'],
            'state' => $metadata['task_state'], 'last_event_sha256' => $metadata['task_event_sha256'] );
    }

    private static function consistent_head( array $context, $task_id ) {
        $head = MAD4B_SCP_Operation_Journal::head( $context['operation_id'] );
        if ( is_wp_error( $head ) ) return $head;
        if ( ! is_array( $head ) || ! isset( $head['operation_key'], $head['operation_binding_sha256'],
            $head['latest_sequence'], $head['latest_event_sha256'], $head['hard_deadline_at'] )
            || ! hash_equals( $task_id, (string) $head['operation_key'] )
            || ! hash_equals( $context['operation_binding_sha256'], (string) $head['operation_binding_sha256'] )
            || ! is_numeric( $head['latest_sequence'] )
            || false === strtotime( (string) $head['hard_deadline_at'] . ' UTC' )
            || strtotime( (string) $head['hard_deadline_at'] . ' UTC' ) !== strtotime( (string) $context['hard_deadline_at'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) $head['latest_event_sha256'] ) ) return self::deny( 'journal_identity_conflict' );
        return $head;
    }

    /**
     * A preexisting journal head must explicitly commit to the non-authorizing
     * ticket identity in its original operation_started event. This protects
     * against accidental aliasing to a generic operation journal head.
     * It does NOT certify caller evidence or provide an approval signature.
     */
    public static function genesis_claim( array $record ) {
        return array( 'assistant_ticket' => 'proposal_only',
            'task_id' => $record['task_id'], 'plan_sha256' => $record['plan_sha256'],
            'binding_sha256' => $record['binding_sha256'] );
    }

    private static function verify_genesis( array $trace, array $record ) {
        $first = isset( $trace['events'][0] ) ? $trace['events'][0] : null;
        if ( ! is_array( $first ) || (int) ( $first['sequence'] ?? 0 ) !== 1
            || ( $first['event_type'] ?? '' ) !== 'operation_started'
            || ( $first['checkpoint'] ?? '' ) !== 'planned'
            || ( $first['lifecycle_state'] ?? '' ) !== 'planned' ) return self::deny( 'genesis_unverified' );
        $metadata = $first['safe_metadata']['metadata'] ?? null;
        $json = wp_json_encode( self::genesis_claim( $record ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_array( $metadata ) || ! is_string( $json )
            || ! isset( $metadata['sha256'], $metadata['length'] )
            || ! is_string( $metadata['sha256'] )
            || ! hash_equals( hash( 'sha256', $json ), $metadata['sha256'] )
            || (int) $metadata['length'] !== strlen( $json ) ) return self::deny( 'genesis_unverified' );
        return true;
    }

    private static function initial_record_valid( array $record, $binding ) {
        if ( array_keys( $record ) !== array( 'contract', 'task_id', 'plan_sha256', 'binding_sha256', 'revision', 'state', 'last_event_sha256' )
            || ( $record['contract'] ?? null ) !== MAD4B_SCP_Assistant_Task_Contract::CONTRACT
            || ! is_string( $record['task_id'] ) || ! preg_match( '/^proposal-[a-f0-9]{32}$/D', $record['task_id'] )
            || ! is_string( $record['plan_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $record['plan_sha256'] )
            || $record['binding_sha256'] !== $binding
            || $record['revision'] !== 1 || $record['state'] !== 'proposed'
            || $record['last_event_sha256'] !== str_repeat( '0', 64 ) ) return self::deny( 'initial_record_invalid' );
        return true;
    }

    /**
     * Create ONLY a non-authorizing Staging review ticket. An exact admin
     * request creates one canonical Operation Journal head; on any uncertain
     * outcome the journal is retained for independent reconciliation.
     * This cannot activate, install, configure, certify or approve providers.
     */
    public static function open_review_ticket( array $context, array $record ) {
        $binding = self::preflight( $context, true );
        if ( is_wp_error( $binding ) ) return $binding;
        $valid = self::initial_record_valid( $record, $binding );
        if ( is_wp_error( $valid ) ) return $valid;
        if ( $context['operation_key'] !== $record['task_id'] ) return self::deny( 'identity_mismatch' );
        $created = MAD4B_SCP_Operation_Journal::begin( $context, 'planned', self::genesis_claim( $record ) );
        if ( is_wp_error( $created ) ) return $created;
        $result = self::initialize( $context, $record );
        if ( is_wp_error( $result ) ) return new WP_Error( 'mad4b_assistant_journal_initialization_uncertain',
            'A non-executable review head was created but initialization was not fully verified; reconcile instead of blindly retrying.',
            array( 'cause' => $result->get_error_code(), 'reconciliation_required' => true,
                'blind_retry_allowed' => false, 'authorizing' => false, 'provider_entry_allowed' => false ) );
        return $result;
    }

    /** Initialize a task ONLY inside a preexisting, single-owner journal head. */
    public static function initialize( array $context, array $record ) {
        $binding = self::preflight( $context, true );
        if ( is_wp_error( $binding ) ) return $binding;
        $valid = self::initial_record_valid( $record, $binding );
        if ( is_wp_error( $valid ) ) return $valid;
        $head = self::consistent_head( $context, $record['task_id'] );
        if ( is_wp_error( $head ) ) return $head;
        if ( (int) $head['latest_sequence'] !== 1 ) return self::deny( 'already_initialized_or_drifted' );
        $trace = MAD4B_SCP_Operation_Journal::trace( $context['operation_id'], 1000 );
        if ( is_wp_error( $trace ) || ! is_array( $trace ) || empty( $trace['chain_valid'] )
            || empty( $trace['complete'] ) || ! isset( $trace['events'] )
            || count( $trace['events'] ) !== 1 ) return self::deny( 'genesis_unverified' );
        $genesis = self::verify_genesis( $trace, $record );
        if ( is_wp_error( $genesis ) ) return $genesis;
        $result = MAD4B_SCP_Operation_Journal::append( $context, 'assistant_task_initialized', array(
            'expected_sequence' => 1, 'expected_event_sha256' => $head['latest_event_sha256'],
            'checkpoint' => 'proposed', 'lifecycle_state' => 'planned',
            'metadata' => self::metadata( $record ) ) );
        if ( is_wp_error( $result ) ) return $result;
        $readback = self::read( $context );
        if ( is_wp_error( $readback ) || $readback['record'] !== $record ) return self::deny( 'readback_failed' );
        return array( 'contract' => self::CONTRACT, 'record' => $readback['record'],
            'journal_head_sha256' => $readback['journal_head_sha256'],
            'persistence_status' => 'PERSISTED_JOURNAL_CAS', 'authorizing' => false,
            'executable' => false, 'mutation_performed' => true );
    }

    /** Verify the authoritative hash-chained journal, not a client ticket. */
    public static function read( array $context ) {
        $binding = self::preflight( $context );
        if ( is_wp_error( $binding ) ) return $binding;
        $head = self::consistent_head( $context, $context['operation_key'] );
        if ( is_wp_error( $head ) ) return $head;
        $trace = MAD4B_SCP_Operation_Journal::trace( $context['operation_id'], 1000 );
        if ( is_wp_error( $trace ) || ! is_array( $trace )
            || empty( $trace['chain_valid'] ) || empty( $trace['complete'] )
            || ! isset( $trace['events'] ) || ! is_array( $trace['events'] )
            || count( $trace['events'] ) !== (int) $head['latest_sequence'] ) return self::deny( 'journal_chain_unverified' );
        // Replay every recorded transition using the pure reducer. A valid
        // hash chain alone cannot prove the intermediate task states were valid.
        $events = $trace['events'];
        if ( count( $events ) < 2 ) return self::deny( 'journal_record_invalid' );
        $initial = $events[1];
        if ( (int) ( $initial['sequence'] ?? 0 ) !== 2
            || ( $initial['event_type'] ?? '' ) !== 'assistant_task_initialized'
            || ( $initial['checkpoint'] ?? '' ) !== 'proposed' ) return self::deny( 'journal_record_invalid' );
        $record = self::record_from_metadata( $initial['safe_metadata'] ?? null );
        if ( is_wp_error( $record ) || $record['revision'] !== 1
            || $record['state'] !== 'proposed'
            || $record['last_event_sha256'] !== str_repeat( '0', 64 ) ) return self::deny( 'journal_record_invalid' );
        $genesis = self::verify_genesis( $trace, $record );
        if ( is_wp_error( $genesis ) ) return $genesis;
        if ( ! is_string( $record['task_id'] )
            || ! hash_equals( $context['operation_key'], $record['task_id'] )
            || $record['binding_sha256'] !== $binding
            || ! is_string( $record['plan_sha256'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $record['plan_sha256'] ) ) return self::deny( 'journal_record_invalid' );
        for ( $i = 2; $i < count( $events ); $i++ ) {
            $event = $events[ $i ];
            if ( (int) ( $event['sequence'] ?? 0 ) !== $i + 1
                || ( $event['event_type'] ?? '' ) !== 'assistant_task_transition' ) {
                return self::deny( 'journal_transition_invalid' );
            }
            $metadata = $event['safe_metadata'] ?? null;
            $observed = self::record_from_metadata( $metadata, true );
            if ( is_wp_error( $observed ) || ! is_string( $metadata['reason_code'] ) ) {
                return self::deny( 'journal_transition_invalid' );
            }
            $expected = MAD4B_SCP_Assistant_Task_Contract::transition( $record, array(
                'expected_revision' => $record['revision'],
                'expected_last_event_sha256' => $record['last_event_sha256'],
                'next_state' => $observed['state'],
                'reason_code' => $metadata['reason_code'],
            ) );
            if ( is_wp_error( $expected )
                || $expected['candidate_record'] !== $observed
                || ( $event['checkpoint'] ?? '' ) !== $observed['state'] ) {
                return self::deny( 'journal_transition_invalid' );
            }
            $record = $observed;
        }
        $last = end( $events );
        if ( ! is_array( $last ) || ! hash_equals( (string) $head['latest_event_sha256'],
            (string) ( $last['event_sha256'] ?? '' ) ) ) return self::deny( 'journal_chain_unverified' );
        return array( 'contract' => self::CONTRACT, 'record' => $record,
            'journal_sequence' => (int) $head['latest_sequence'],
            'journal_head_sha256' => $head['latest_event_sha256'],
            'chain_valid' => true, 'authorizing' => false, 'read_only' => true,
            'executable' => false );
    }

    /**
     * Existing native journal serializes under FOR UPDATE, checks expected
     * head sequence and SHA inside the same transaction and commits the event.
     * This only moves non-executable review states, never applies a plugin.
     */
    public static function transition( array $context, array $record, array $request ) {
        $known = self::read( $context );
        if ( is_wp_error( $known ) ) return $known;
        if ( $record !== $known['record'] ) return self::deny( 'ticket_stale' );
        $write_preflight = self::preflight( $context, true );
        if ( is_wp_error( $write_preflight ) ) return $write_preflight;
        $candidate = MAD4B_SCP_Assistant_Task_Contract::transition( $record, $request );
        if ( is_wp_error( $candidate ) ) return $candidate;
        $next = $candidate['candidate_record'];
        $result = MAD4B_SCP_Operation_Journal::append( $context, 'assistant_task_transition', array(
            'expected_sequence' => $known['journal_sequence'],
            'expected_event_sha256' => $known['journal_head_sha256'],
            'checkpoint' => $next['state'], 'lifecycle_state' => 'planned',
            'metadata' => array_merge( self::metadata( $next ), array(
                'reason_code' => $request['reason_code'] ) ) ) );
        if ( is_wp_error( $result ) ) return $result;
        $after = self::read( $context );
        if ( is_wp_error( $after ) || $after['record'] !== $next ) return self::deny( 'readback_failed' );
        return array( 'contract' => self::CONTRACT, 'record' => $after['record'],
            'journal_head_sha256' => $after['journal_head_sha256'],
            'persistence_status' => 'PERSISTED_JOURNAL_CAS',
            'authorizing' => false, 'executable' => false,
            'provider_entry_allowed' => false, 'plugin_install_allowed' => false,
            'production_authorized' => false, 'mutation_performed' => true );
    }
}
