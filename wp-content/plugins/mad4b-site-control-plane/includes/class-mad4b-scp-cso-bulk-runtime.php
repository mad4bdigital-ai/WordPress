<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable, explicit per-item native-ticket bulk runner.
 * One canary slice per authorized request; no blind retry after a crash or
 * unknown provider effect. Every real write routes through Native Executor.
 */
final class MAD4B_SCP_CSO_Bulk_Runtime {
    const CONTRACT = 'mad4b.cso.bulk-run.v1';

    private static function error( $v ) { return MAD4B_SCP_CSO_Scope::error( $v ); }

    private static function persist( $key, &$old, $next ) {
        global $wpdb;
        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
            maybe_serialize( $next ), $key, maybe_serialize( $old ) ) );
        wp_cache_delete( $key, 'options' );
        if ( 1 !== (int) $result || ! empty( $wpdb->last_error ) )
            return self::error( 'BULK_JOURNAL_COMMIT_UNCERTAIN' );
        $old = $next;
        return true;
    }

    private static function checkpoint( $batch_sha, $journal, $scope ) {
        return MAD4B_SCP_CSO_Scope::seal( array(
            'contract' => self::CONTRACT . '.checkpoint.v1',
            'batch_sha256' => $batch_sha,
            'scope_sha256' => MAD4B_SCP_CSO_Scope::digest( $scope ),
            'cursor' => $journal['cursor'],
            'status' => $journal['status'],
            'expires_at' => time() + 300 ), self::CONTRACT . '.checkpoint' );
    }

    public static function commit( $plan, $governance, $checkpoint, $limit ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'bulk' ) ||
            ! MAD4B_SCP_CSO_Scope::enabled( 'single_write' ) ||
            ! MAD4B_SCP_CSO_Scope::first_party_session() ||
            ! is_array( $plan ) || ! is_array( $governance ) ||
            array_diff( array_keys( $governance ), array( 'items','canary_reviewed' ) ) ||
            ! is_array( $governance['items'] ?? null ) ||
            ! is_bool( $governance['canary_reviewed'] ?? null ) ||
            ! is_array( $checkpoint ) ||
            ! is_int( $limit ) || $limit < 1 || $limit > 5 ||
            ! class_exists( 'MAD4B_SCP_Database_Topology', false ) ||
            ! class_exists( 'MAD4B_SCP_Operational_Integrity', false ) )
            return self::error( 'BULK_RUN_INPUT_OR_AUTHORITY_INVALID' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ) return $scope;
        $batch = MAD4B_SCP_CSO_Scope::unseal( $plan, MAD4B_SCP_CSO_Bulk::CONTRACT );
        if ( is_wp_error( $batch ) || ! is_array( $batch ) ||
            ( $batch['contract'] ?? '' ) !== MAD4B_SCP_CSO_Bulk::CONTRACT ||
            ! hash_equals( (string) ( $batch['scope_sha256'] ?? '' ),
                MAD4B_SCP_CSO_Scope::digest( $scope ) ) ||
            ! hash_equals( (string) ( $batch['actor_sha256'] ?? '' ),
                (string) $scope['actor_sha256'] ) ||
            ! is_int( $batch['expires_at'] ?? null ) ||
            $batch['expires_at'] <= time() ||
            ! is_array( $batch['plans'] ?? null ) ||
            count( $batch['plans'] ) < 1 || count( $batch['plans'] ) > 32 ||
            count( $governance['items'] ) !== count( $batch['plans'] ) ||
            ! is_int( $batch['canary_size'] ?? null ) ||
            $batch['canary_size'] < 1 || $batch['canary_size'] > 5 )
            return self::error( 'BULK_PLAN_OR_SCOPE_STALE' );
        $sha = MAD4B_SCP_CSO_Scope::digest( $batch );
        $key = 'mad4b_cso_batch_' . $sha;
        $topology = MAD4B_SCP_Database_Topology::assert_write_ready( true );
        if ( is_wp_error( $topology ) ) return $topology;
        $identity = MAD4B_SCP_Operational_Integrity::capture();
        if ( is_wp_error( $identity ) ||
            is_wp_error( MAD4B_SCP_Operational_Integrity::assert_unchanged( $identity, true ) ) )
            return self::error( 'BULK_CURRENT_GRANT_REQUIRED' );
        $journal = get_option( $key, null );
        if ( null === $journal ) {
            if ( $checkpoint || $limit > $batch['canary_size'] )
                return self::error( 'BULK_INITIAL_CANARY_REQUIRED' );
            $journal = array(
                'contract' => self::CONTRACT . '.journal.v1',
                'batch_sha256' => $sha,
                'scope_sha256' => MAD4B_SCP_CSO_Scope::digest( $scope ),
                'cursor' => 0, 'status' => 'ready',
                'effect_ticket_sha256' => '', 'created_at' => time() );
            if ( ! add_option( $key, $journal, '', false ) )
                return self::error( 'BULK_RACE_OR_UNKNOWN_EFFECT' );
            if ( get_option( $key, null ) !== $journal )
                return self::error( 'BULK_RESERVATION_READBACK_UNCERTAIN' );
        } else {
            if ( ! is_array( $journal ) ||
                ( $journal['contract'] ?? '' ) !== self::CONTRACT . '.journal.v1' ||
                ( $journal['batch_sha256'] ?? '' ) !== $sha ||
                ! hash_equals( (string) ( $journal['scope_sha256'] ?? '' ),
                    MAD4B_SCP_CSO_Scope::digest( $scope ) ) ||
                ( $journal['status'] ?? '' ) !== 'paused' ||
                ! $governance['canary_reviewed'] || ! $checkpoint )
                return self::error( 'BULK_REPLAY_OR_UNCERTAIN_EFFECT' );
            $proof = MAD4B_SCP_CSO_Scope::unseal(
                $checkpoint, self::CONTRACT . '.checkpoint' );
            if ( is_wp_error( $proof ) || ! is_array( $proof ) ||
                ( $proof['contract'] ?? '' ) !== self::CONTRACT . '.checkpoint.v1' ||
                ( $proof['batch_sha256'] ?? '' ) !== $sha ||
                ( $proof['scope_sha256'] ?? '' ) !== MAD4B_SCP_CSO_Scope::digest( $scope ) ||
                ( $proof['cursor'] ?? -1 ) !== $journal['cursor'] ||
                ( $proof['status'] ?? '' ) !== 'paused' ||
                ( $proof['expires_at'] ?? 0 ) < time() )
                return self::error( 'BULK_RESUME_CHECKPOINT_INVALID' );
        }
        $total = count( $batch['plans'] );
        $end = min( $total, $journal['cursor'] + $limit );
        for ( $index = $journal['cursor']; $index < $end; $index++ ) {
            if ( is_wp_error( MAD4B_SCP_Operational_Integrity::assert_unchanged( $identity, true ) ) ||
                true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
                return self::error( 'BULK_SCOPE_CHANGED_BEFORE_EFFECT' );
            $item = $batch['plans'][ $index ];
            $ticket = $governance['items'][ $index ] ?? null;
            if ( ! is_array( $item ) || ! is_array( $ticket ) ||
                array_diff( array_keys( $ticket ), array( 'ticket_id', 'agent_public_id' ) ) ||
                ! isset( $item['plan'], $item['plan_sha256'] ) ||
                ! is_array( $item['plan'] ) ||
                ! is_string( $ticket['ticket_id'] ?? null ) ||
                ! is_string( $ticket['agent_public_id'] ?? null ) )
                return self::error( 'BULK_ITEM_OR_APPROVAL_INVALID' );
            $material = MAD4B_SCP_CSO_Scope::unseal(
                $item['plan'], MAD4B_SCP_CSO_Changes::CONTRACT );
            if ( is_wp_error( $material ) || ! is_array( $material ) ||
                ! hash_equals( (string) $item['plan_sha256'],
                    MAD4B_SCP_CSO_Scope::digest( $material ) ) )
                return self::error( 'BULK_ITEM_TAMPERED' );
            $inflight = $journal;
            $inflight['status'] = 'inflight';
            $inflight['effect_ticket_sha256'] = hash( 'sha256', $ticket['ticket_id'] );
            if ( is_wp_error( self::persist( $key, $journal, $inflight ) ) )
                return self::error( 'BULK_INFLIGHT_JOURNAL_UNCERTAIN' );
            $result = MAD4B_SCP_CSO_Native_Executor::commit(
                $item['plan'], $ticket );
            if ( is_wp_error( $result ) || ! is_array( $result ) ||
                ( $result['status'] ?? '' ) !== 'verified' ||
                is_wp_error( MAD4B_SCP_Operational_Integrity::assert_unchanged( $identity, true ) ) ) {
                $blocked = $journal;
                $blocked['status'] = 'needs_reconcile';
                self::persist( $key, $journal, $blocked );
                return self::error( 'BULK_ITEM_EFFECT_UNCERTAIN' );
            }
            $after = $journal;
            $after['cursor'] = $index + 1;
            $after['status'] = $after['cursor'] === $total ? 'complete' : 'paused';
            if ( is_wp_error( self::persist( $key, $journal, $after ) ) )
                return self::error( 'BULK_POST_EFFECT_JOURNAL_UNCERTAIN' );
        }
        $receipt = self::checkpoint( $sha, $journal, $scope );
        if ( is_wp_error( $receipt ) ) return $receipt;
        return array( 'contract' => self::CONTRACT . '.result.v1',
            'status' => $journal['status'],
            'completed_items' => $journal['cursor'], 'total_items' => $total,
            'checkpoint' => $receipt,
            'canary_review_required' => $journal['status'] === 'paused',
            'replay_allowed' => false, 'blind_retry_allowed' => false,
            'production_promotion_authorized' => false );
    }
}
