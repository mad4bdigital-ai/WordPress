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

    private static function preflight( array $context ) {
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
        if ( false === $deadline || $deadline <= time() || $deadline > time() + DAY_IN_SECONDS ) return self::deny( 'deadline_invalid' );
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

    private static function consistent_head( array $context, $task_id ) {
        $head = MAD4B_SCP_Operation_Journal::head( $context['operation_id'] );
        if ( is_wp_error( $head ) ) return $head;
        if ( ! is_array( $head ) || ! isset( $head['operation_key'], $head['operation_binding_sha256'],
            $head['latest_sequence'], $head['latest_event_sha256'] )
            || ! hash_equals( $task_id, (string) $head['operation_key'] )
            || ! hash_equals( $context['operation_binding_sha256'], (string) $head['operation_binding_sha256'] )
            || ! is_numeric( $head['latest_sequence'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) $head['latest_event_sha256'] ) ) return self::deny( 'journal_identity_conflict' );
        return $head;
    }

    /** Initialize a task ONLY inside a preexisting, single-owner journal head. */
    public static function initialize( array $context, array $record ) {
        $binding = self::preflight( $context );
        if ( is_wp_error( $binding ) ) return $binding;
        if ( array_keys( $record ) !== array( 'contract', 'task_id', 'plan_sha256', 'binding_sha256', 'revision', 'state', 'last_event_sha256' )
            || ( $record['contract'] ?? null ) !== MAD4B_SCP_Assistant_Task_Contract::CONTRACT
            || ! is_string( $record['task_id'] ) || ! preg_match( '/^proposal-[a-f0-9]{32}$/D', $record['task_id'] )
            || ! is_string( $record['plan_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $record['plan_sha256'] )
            || $record['binding_sha256'] !== $binding
            || $record['revision'] !== 1 || $record['state'] !== 'proposed'
            || $record['last_event_sha256'] !== str_repeat( '0', 64 ) ) return self::deny( 'initial_record_invalid' );
        $head = self::consistent_head( $context, $record['task_id'] );
        if ( is_wp_error( $head ) ) return $head;
        if ( (int) $head['latest_sequence'] !== 1 ) return self::deny( 'already_initialized_or_drifted' );
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
        $last = end( $trace['events'] );
        if ( ! is_array( $last ) || ! in_array( $last['event_type'] ?? '', array( 'assistant_task_initialized', 'assistant_task_transition' ), true )
            || ! hash_equals( (string) $head['latest_event_sha256'], (string) ( $last['event_sha256'] ?? '' ) ) ) return self::deny( 'journal_event_unverified' );
        $m = $last['safe_metadata'] ?? null;
        if ( ! is_array( $m ) || array_keys( $m ) !== array( 'task_id', 'plan_sha256', 'binding_sha256',
            'task_revision', 'task_state', 'task_event_sha256' ) ) return self::deny( 'journal_record_invalid' );
        $record = array( 'contract' => MAD4B_SCP_Assistant_Task_Contract::CONTRACT,
            'task_id' => $m['task_id'], 'plan_sha256' => $m['plan_sha256'],
            'binding_sha256' => $m['binding_sha256'], 'revision' => $m['task_revision'],
            'state' => $m['task_state'], 'last_event_sha256' => $m['task_event_sha256'] );
        if ( ! is_string( $record['task_id'] ) || ! hash_equals( $context['operation_key'], $record['task_id'] )
            || $record['binding_sha256'] !== $binding || ! is_int( $record['revision'] )
            || $record['revision'] < 1 || ! is_string( $record['plan_sha256'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $record['plan_sha256'] )
            || ! is_string( $record['last_event_sha256'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $record['last_event_sha256'] ) ) return self::deny( 'journal_record_invalid' );
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
        $candidate = MAD4B_SCP_Assistant_Task_Contract::transition( $record, $request );
        if ( is_wp_error( $candidate ) ) return $candidate;
        $next = $candidate['candidate_record'];
        $result = MAD4B_SCP_Operation_Journal::append( $context, 'assistant_task_transition', array(
            'expected_sequence' => $known['journal_sequence'],
            'expected_event_sha256' => $known['journal_head_sha256'],
            'checkpoint' => $next['state'], 'lifecycle_state' => 'planned',
            'metadata' => self::metadata( $next ) ) );
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
