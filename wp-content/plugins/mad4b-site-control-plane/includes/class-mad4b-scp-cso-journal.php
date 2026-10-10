<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Non-authorizing projection over the existing transactional operation journal. */
final class MAD4B_SCP_CSO_Journal {
    const CONTRACT = 'mad4b.cso01.journal.v1';
    const STATES = array( 'PLANNED', 'AUTHORIZED', 'DENIED', 'EXPIRED', 'RUNNING', 'VERIFYING', 'SUCCEEDED', 'PARTIAL', 'UNCERTAIN', 'FAILED', 'COMPENSATING', 'COMPENSATED' );
    public static function context( array $plan ) {
        if ( ! is_string( $plan['operation_id'] ?? null ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $plan['operation_id'] )
            || ! is_string( $plan['plan_sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $plan['plan_sha256'] ) || ! is_int( $plan['expires_at'] ?? null ) ) return self::error( 'identity_invalid' );
        return array( 'operation_id' => $plan['operation_id'], 'operation_key' => 'cso:' . $plan['operation_id'],
            'operation_binding_sha256' => $plan['plan_sha256'], 'hard_deadline_at' => gmdate( 'c', $plan['expires_at'] ) );
    }
    public static function begin( array $plan, array $metadata = array() ) {
        $ctx = self::context( $plan ); if ( is_wp_error( $ctx ) ) return $ctx;
        if ( ! class_exists( 'MAD4B_SCP_Operation_Journal' ) ) return self::error( 'native_unavailable' );
        $metadata = self::metadata( $metadata ); if ( is_wp_error( $metadata ) ) return $metadata;
        $scope = MAD4B_SCP_CSO_Scope::assert_current( $plan['scope'] ); if ( is_wp_error( $scope ) ) return $scope;
        $metadata['cso_state'] = 'PLANNED'; $metadata['site_scope_digest'] = MAD4B_SCP_CSO_Scope::digest( $plan['scope'] );
        if ( is_wp_error( $metadata['site_scope_digest'] ) ) return $metadata['site_scope_digest'];
        return MAD4B_SCP_Operation_Journal::begin( $ctx, 'planned', $metadata );
    }
    public static function inspect( array $plan ) {
        $ctx = self::context( $plan ); if ( is_wp_error( $ctx ) ) return $ctx;
        if ( ! class_exists( 'MAD4B_SCP_Operation_Journal' ) ) return self::error( 'native_unavailable' );
        $scope = MAD4B_SCP_CSO_Scope::assert_current( $plan['scope'] ); if ( is_wp_error( $scope ) ) return $scope;
        $status = MAD4B_SCP_Operation_Journal::status( $ctx['operation_id'] ); if ( is_wp_error( $status ) ) return $status;
        $trace = MAD4B_SCP_Operation_Journal::trace( $ctx['operation_id'], 1000 ); if ( is_wp_error( $trace ) ) return $trace;
        if ( ! is_array( $status ) || ! is_array( $trace ) || ( $status['contract'] ?? '' ) !== 'mad4b.dynamic-operation-status.v1'
            || ( $trace['contract'] ?? '' ) !== 'mad4b.dynamic-operation-trace.v1' || true !== ( $trace['chain_valid'] ?? null ) || true !== ( $trace['complete'] ?? null )
            || ( $status['operation_binding_sha256'] ?? '' ) !== $ctx['operation_binding_sha256'] || ( $status['operation_key'] ?? '' ) !== $ctx['operation_key']
            || ( $status['operation_id'] ?? '' ) !== $ctx['operation_id'] || ( $trace['operation_id'] ?? '' ) !== $ctx['operation_id']
            || ( $status['latest_sequence'] ?? null ) !== ( $trace['count'] ?? null ) || ! is_int( $status['latest_sequence'] ?? null ) || empty( $trace['events'] ) ) return self::error( 'integrity_required' );
        $last = $trace['events'][ count( $trace['events'] ) - 1 ]; $state = $last['safe_metadata']['cso_state'] ?? '';
        if ( ! in_array( $state, self::STATES, true ) || ( $last['event_sha256'] ?? '' ) !== ( $status['journal_head_sha256'] ?? '' ) ) return self::error( 'head_invalid' );
        if ( in_array( $state, array( 'RUNNING', 'VERIFYING', 'COMPENSATING' ), true ) && ! empty( $status['orphan_candidate'] ) ) $state = 'UNCERTAIN';
        return array( 'contract' => self::CONTRACT, 'operation_id' => $ctx['operation_id'], 'state' => $state, 'sequence' => $status['latest_sequence'],
            'event_sha256' => $status['journal_head_sha256'], 'events' => $trace['events'], 'authorizing' => false, 'blind_retry_allowed' => false );
    }
    public static function transition( array $plan, $expected, $next, array $metadata = array() ) {
        $allowed = array( 'PLANNED' => array( 'AUTHORIZED', 'RUNNING', 'DENIED', 'EXPIRED', 'COMPENSATING' ), 'AUTHORIZED' => array( 'RUNNING', 'DENIED', 'EXPIRED' ),
            'RUNNING' => array( 'RUNNING', 'VERIFYING', 'PARTIAL', 'UNCERTAIN', 'FAILED', 'DENIED' ), 'VERIFYING' => array( 'SUCCEEDED', 'COMPENSATED', 'PARTIAL', 'UNCERTAIN' ),
            'PARTIAL' => array( 'RUNNING', 'COMPENSATING', 'UNCERTAIN' ), 'UNCERTAIN' => array( 'VERIFYING' ), 'SUCCEEDED' => array( 'COMPENSATING' ), 'COMPENSATING' => array( 'VERIFYING', 'UNCERTAIN', 'DENIED' ) );
        if ( ! is_string( $expected ) || ! is_string( $next ) || ! in_array( $next, $allowed[ $expected ] ?? array(), true ) ) return self::error( 'transition_denied' );
        $before = self::inspect( $plan ); if ( is_wp_error( $before ) ) return $before;
        if ( $before['state'] !== $expected ) return self::error( 'cas_stale' );
        $metadata = self::metadata( $metadata ); if ( is_wp_error( $metadata ) ) return $metadata;
        $metadata['cso_state'] = $next; $terminal = in_array( $next, array( 'SUCCEEDED', 'COMPENSATED', 'DENIED', 'EXPIRED', 'FAILED' ), true );
        $saved = MAD4B_SCP_Operation_Journal::append( self::context( $plan ), 'cso_transition', array( 'expected_sequence' => $before['sequence'], 'expected_event_sha256' => $before['event_sha256'],
            'lifecycle_state' => $terminal ? ( in_array( $next, array( 'SUCCEEDED', 'COMPENSATED' ), true ) ? 'completed' : 'terminal_failed' ) : strtolower( $next ),
            'checkpoint' => strtolower( $next ), 'terminal_outcome' => $terminal ? strtolower( $next ) : '', 'metadata' => $metadata ) );
        if ( is_wp_error( $saved ) ) return $saved;
        $after = self::inspect( $plan );
        return is_wp_error( $after ) || $after['state'] !== $next || $after['sequence'] !== $before['sequence'] + 1 ? self::error( 'readback_uncertain' ) : $after;
    }
    /** The service caller must first recheck native target read permission. */
    public static function redact_history( array $journal ) {
        $events = array();
        foreach ( $journal['events'] as $event ) {
            $safe = self::metadata( $event['safe_metadata'] ); if ( is_wp_error( $safe ) ) return $safe;
            $events[] = array( 'sequence' => $event['sequence'], 'state' => $safe['cso_state'] ?? '', 'node_id' => $safe['node_id'] ?? '', 'node_state' => $safe['node_state'] ?? '',
                'receipt_ref' => $safe['receipt_ref'] ?? '', 'reason_code' => $safe['reason_code'] ?? '', 'created_at' => $event['created_at'] ?? '' );
        }
        return array( 'contract' => self::CONTRACT, 'operation_id' => $journal['operation_id'], 'state' => $journal['state'], 'events' => $events,
            'values_redacted' => true, 'authorizing' => false, 'mutation_performed' => false, 'undo_authorized' => false );
    }
    public static function nodes( array $journal ) {
        $states = array(); foreach ( $journal['events'] as $event ) { $m = $event['safe_metadata']; if ( isset( $m['node_id'], $m['node_state'] ) ) $states[ $m['node_id'] ] = $m['node_state']; } return $states;
    }
    private static function metadata( array $metadata ) {
        $allowed = array( 'cso_state', 'site_scope_digest', 'ability_name', 'node_id', 'node_state', 'receipt_ref', 'reason_code', 'verified', 'mutation_id', 'checkpoint' );
        if ( count( $metadata ) > 32 ) return self::error( 'metadata_bounds' );
        foreach ( $metadata as $key => $v ) if ( ! in_array( $key, $allowed, true ) || ( ! is_string( $v ) && ! is_int( $v ) && ! is_bool( $v ) )
            || ( is_string( $v ) && ( strlen( $v ) > 191 || preg_match( '/[\x00-\x1f\x7f]/', $v ) ) ) ) return self::error( 'metadata_invalid' );
        return $metadata;
    }
    private static function error( $reason ) { return new WP_Error( 'mad4b_cso_journal_' . $reason, 'Exact native journal state and durable reconciliation are required.', array( 'authorizing' => false, 'blind_retry_allowed' => false, 'reconciliation_required' => true ) ); }
}
