<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pure CAS transition policy for assistant workflow tickets. Actual atomic
 * persistence, leases, approval, signed receipts and side effects belong to
 * MAD4B Durable Execution and its separately governed execution lanes.
 */
final class MAD4B_SCP_Assistant_Task_Contract {
    const CONTRACT = 'mad4b.assistant-task-transition.v1';

    private static function fail( $code ) {
        return new WP_Error( 'mad4b_assistant_task_' . $code,
            'Transition rejected; no task state or external effect was committed.',
            array( 'authorizing' => false, 'mutation_performed' => false ) );
    }

    public static function transition( $record, $request ) {
        if ( ! is_array( $record ) || ! is_array( $request ) ) return self::fail( 'invalid_shape' );
        $recordKeys = array( 'contract', 'task_id', 'plan_sha256', 'binding_sha256', 'revision', 'state', 'last_event_sha256' );
        $requestKeys = array( 'expected_revision', 'expected_last_event_sha256', 'next_state', 'reason_code' );
        if ( array_keys( $record ) !== $recordKeys || array_keys( $request ) !== $requestKeys ) return self::fail( 'unknown_fields' );
        foreach ( array( 'plan_sha256', 'binding_sha256', 'last_event_sha256' ) as $k ) {
            if ( ! is_string( $record[ $k ] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $record[ $k ] ) ) return self::fail( 'invalid_digest' );
        }
        if ( $record['contract'] !== self::CONTRACT
            || ! is_string( $record['task_id'] ) || ! preg_match( '/^proposal-[a-f0-9]{32}$/D', $record['task_id'] )
            || ! is_int( $record['revision'] ) || $record['revision'] < 1 || $record['revision'] >= PHP_INT_MAX
            || ! is_int( $request['expected_revision'] ) || $request['expected_revision'] !== $record['revision']
            || ! is_string( $request['expected_last_event_sha256'] )
            || ! hash_equals( $record['last_event_sha256'], $request['expected_last_event_sha256'] ) ) return self::fail( 'stale_revision' );
        if ( ! is_string( $request['reason_code'] ) || ! preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $request['reason_code'] )
            || ! is_string( $request['next_state'] ) ) return self::fail( 'request_invalid' );
        $allowed = array(
            'proposed' => array( 'evidence_pending', 'cancelled' ),
            'evidence_pending' => array( 'review_pending', 'blocked', 'cancelled' ),
            'review_pending' => array( 'authority_pending', 'blocked', 'cancelled' ),
            'authority_pending' => array( 'blocked', 'cancelled' ),
            'blocked' => array( 'evidence_pending', 'cancelled' ),
            'cancelled' => array(),
            'completed' => array(),
        );
        if ( ! is_string( $record['state'] ) || strlen( $record['state'] ) > 32
            || ! isset( $allowed[ $record['state'] ] )
            || ! in_array( $request['next_state'], $allowed[ $record['state'] ], true ) ) return self::fail( 'transition_not_allowed' );
        // No state in this public pure reducer confers execution eligibility.
        // An external signed grant cannot be created by any input here.
        $next = $record;
        $next['revision']++;
        $next['state'] = $request['next_state'];
        $next['last_event_sha256'] = hash( 'sha256', serialize( array(
            $record['last_event_sha256'], $record['plan_sha256'], $record['binding_sha256'],
            $record['task_id'], $record['revision'], $request['next_state'], $request['reason_code'],
        ) ) );
        return array(
            'contract' => self::CONTRACT,
            'expected_record_revision' => $record['revision'],
            'candidate_record' => $next,
            'persistence_status' => 'NOT_PERSISTED',
            'requires_atomic_compare_and_swap' => true,
            'requires_external_authority' => true,
            'executable' => false, 'authorizing' => false,
            'mutation_performed' => false, 'production_authorized' => false,
        );
    }
}
