<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-structural-redaction.php';

/**
 * G7 history-only operator projection over the existing authoritative journal.
 * No new writer, storage, update/undo executor, grants or approval state.
 */
final class MAD4B_SCP_G7_Operator_Journal {
    const CONTRACT = 'mad4b.feature007-g7-operator-journal.v1';
    const MAX_EVENTS = 1000;

    public static function project( $operation_id ) {
        if ( ! class_exists( 'MAD4B_SCP_Operation_Journal' ) ) {
            return self::error( 'operation_journal_unavailable' );
        }
        $trace = MAD4B_SCP_Operation_Journal::trace( $operation_id, self::MAX_EVENTS );
        if ( is_wp_error( $trace ) ) return $trace;
        $status = MAD4B_SCP_Operation_Journal::status( $operation_id );
        if ( is_wp_error( $status ) ) return $status;
        if ( ! is_array( $trace ) || ! is_array( $status ) ) return self::error( 'journal_projection_unavailable' );

        $identity = isset( $trace['operation_id'], $status['operation_id'] ) &&
            is_string( $trace['operation_id'] ) && is_string( $status['operation_id'] ) &&
            '' !== $trace['operation_id'] &&
            hash_equals( $trace['operation_id'], $status['operation_id'] );
        $events = isset( $trace['events'] ) && is_array( $trace['events'] ) ? $trace['events'] : array();
        $count = count( $events );
        $integrity = $identity &&
            true === ( $trace['chain_valid'] ?? false ) &&
            true === ( $trace['complete'] ?? false ) &&
            $count > 0 && $count <= self::MAX_EVENTS &&
            $count === ( $trace['count'] ?? null ) &&
            $count === ( $status['latest_sequence'] ?? null ) &&
            is_string( $status['journal_head_sha256'] ?? null ) &&
            1 === preg_match( '/^[a-f0-9]{64}$/D', $status['journal_head_sha256'] ) &&
            isset( $events[ $count - 1 ]['event_sha256'] ) &&
            is_string( $events[ $count - 1 ]['event_sha256'] ) &&
            hash_equals( $status['journal_head_sha256'], $events[ $count - 1 ]['event_sha256'] );

        $base = array(
            'contract' => self::CONTRACT,
            'operation_id' => $identity ? $trace['operation_id'] : '',
            'source' => 'existing_operation_journal',
            'read_only' => true,
            'mutation_performed' => false,
            'authorizing' => false,
            'execution_receipt_verified' => false,
            'external_effects_verified' => false,
            'reversal_verified' => false,
            'actor_identity_verified' => false,
            'undo_eligibility' => 'EXACT_COMPENSATION_AND_CURRENT_READBACK_REQUIRED',
            'outcome_is_commit_proof' => false,
        );
        if ( ! $integrity ) return array_merge( $base, array(
            'state' => 'RECONCILIATION_REQUIRED',
            'reason' => 'journal_missing_truncated_or_inconsistent',
            'events' => array(),
            'verified_history' => false,
            'operator_next_step' => 'Review durable execution evidence; do not retry or claim an Undo.',
        ) );

        $timeline = array();
        foreach ( $events as $event ) {
            if ( ! is_array( $event ) || ! isset( $event['sequence'], $event['event_sha256'] ) ||
                ! is_int( $event['sequence'] ) || $event['sequence'] !== count( $timeline ) + 1 ||
                ! is_string( $event['event_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $event['event_sha256'] ) ) {
                return array_merge( $base, array(
                    'state' => 'RECONCILIATION_REQUIRED', 'reason' => 'event_sequence_invalid',
                    'events' => array(), 'verified_history' => false,
                    'operator_next_step' => 'Review operation trace integrity before any action.',
                ) );
            }
            $safe = MAD4B_SCP_Structural_Redaction::redact(
                isset( $event['safe_metadata'] ) && is_array( $event['safe_metadata'] ) ? $event['safe_metadata'] : array(),
                'g7_operator_journal'
            );
            $timeline[] = array(
                'sequence' => $event['sequence'],
                'event_type' => self::bounded( $event['event_type'] ?? '' ),
                'checkpoint' => self::bounded( $event['checkpoint'] ?? '' ),
                'lifecycle_state' => self::bounded( $event['lifecycle_state'] ?? '' ),
                'terminal_outcome_claim' => self::bounded( $event['terminal_outcome'] ?? '' ),
                'recorded_actor_claim' => self::bounded( $safe['actor_id'] ?? '' ),
                'recorded_agent_claim' => self::bounded( $safe['agent_id'] ?? '' ),
                'reason' => self::bounded( $safe['reason_code'] ?? ( $safe['reason'] ?? '' ) ),
                'objects' => array(
                    'object_type' => self::bounded( $safe['object_type'] ?? '' ),
                    'object_id' => self::bounded( $safe['object_id'] ?? '' ),
                    'provider_id' => self::bounded( $safe['provider_id'] ?? '' ),
                    'capability_id' => self::bounded( $safe['capability_id'] ?? '' ),
                ),
                'safe_metadata' => $safe,
                'event_sha256' => $event['event_sha256'],
                'values_redacted' => true,
            );
        }
        return array_merge( $base, array(
            'state' => 'HISTORY_ONLY',
            'verified_history' => true,
            'journal_head_sha256' => $status['journal_head_sha256'],
            'lifecycle_state' => self::bounded( $status['lifecycle_state'] ?? '' ),
            'terminal_outcome_claim' => self::bounded( $status['terminal_outcome'] ?? '' ),
            'events' => $timeline,
            'operator_next_step' => 'Use separate exact authorized execution/receipt evidence to establish commit or Undo eligibility.',
        ) );
    }

    private static function bounded( $value ) {
        if ( ! is_string( $value ) && ! is_int( $value ) ) return '';
        return substr( (string) $value, 0, 191 );
    }

    private static function error( $reason ) {
        return new WP_Error( 'mad4b_g7_' . $reason, 'The journal cannot provide a verified history-only projection.', array(
            'authorizing' => false, 'mutation_performed' => false, 'reconciliation_required' => true,
        ) );
    }
}
