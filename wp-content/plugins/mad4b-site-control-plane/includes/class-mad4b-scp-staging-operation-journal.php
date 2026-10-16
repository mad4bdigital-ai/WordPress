<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Immutable operation identity and fail-closed recovery marker.
 *
 * A unique wp_options key is an atomic duplicate-claim guard. A marker is
 * deliberately NOT an exactly-once distributed transaction or a certificate
 * that a remote side effect succeeded. A repeated or uncertain call must
 * read independent current state; it cannot use a new owner intent to replay.
 */
final class MAD4B_SCP_Staging_Operation_Journal {
    const CONTRACT = 'mad4b.staging-write-operation-journal.v1';
    const PREFIX = 'mad4b_scp_staging_operation_v1_';
    const LATEST = 'mad4b_scp_staging_operation_latest_v1';
    const ACTIVE_PREFIX = 'mad4b_scp_staging_operation_active_v1_';

    public static function id( array $plan ) {
        $fields = array( 'plan_sha256', 'site_uuid', 'source_commit_sha',
            'build_fingerprint', 'package_manifest_digest' );
        $parts = array( self::CONTRACT, 'staging-write-authority-convergence-apply' );
        foreach ( $fields as $field ) {
            $value = isset( $plan[ $field ] ) && is_string( $plan[ $field ] )
                ? strtolower( trim( $plan[ $field ] ) ) : '';
            $expected = 'site_uuid' === $field ? '/^[a-f0-9-]{36}$/D'
                : ( 'source_commit_sha' === $field ? '/^[a-f0-9]{40}$/D'
                : '/^[a-f0-9]{64}$/D' );
            if ( 1 !== preg_match( $expected, $value ) ) return '';
            $parts[] = $value;
        }
        return hash( 'sha256', implode( '|', $parts ) );
    }

    private static function key( $id ) { return self::PREFIX . $id; }
    private static function active_key( $site_uuid ) {
        return self::ACTIVE_PREFIX . substr( hash( 'sha256', strtolower( $site_uuid ) ), 0, 32 );
    }
    private static function latest_key( $site_uuid ) {
        return self::LATEST . '_' . substr( hash( 'sha256', strtolower( $site_uuid ) ), 0, 32 );
    }
    private static function signature( array $record ) {
        if ( ! function_exists( 'wp_salt' ) ) return '';
        $secret = (string) wp_salt( 'auth' );
        if ( strlen( $secret ) < 16 ) return '';
        unset( $record['signature'] );
        $json = function_exists( 'wp_json_encode' )
            ? wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            : json_encode( $record );
        return is_string( $json ) ? hash_hmac( 'sha256', $json, $secret ) : '';
    }
    private static function valid( $id, $record ) {
        if ( ! is_array( $record ) || ! isset( $record['operation_id'],
            $record['signature'], $record['state'], $record['site_uuid'], $record['plan_sha256'] )
            || ! hash_equals( $id, (string) $record['operation_id'] )
            || ! in_array( (string) $record['state'],
                array( 'EXECUTION_UNCERTAIN', 'RECOVERY_REQUIRED', 'FINALIZING', 'VERIFIED' ), true ) ) return false;
        $expected = self::signature( $record );
        return '' !== $expected && hash_equals( $expected, (string) $record['signature'] );
    }
    public static function inspect_id( $id, $site_uuid ) {
        if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $id )
            || ! is_string( $site_uuid ) || 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $site_uuid ) )
            return array( 'state' => 'INVALID_OPERATION_ID', 'read_only' => true );
        $record = get_option( self::key( $id ), array() );
        if ( ! self::valid( $id, $record ) || ! hash_equals( $site_uuid, (string) $record['site_uuid'] ) )
            return array( 'state' => 'UNVERIFIED_OR_UNAVAILABLE', 'read_only' => true );
        $active = get_option( self::active_key( $site_uuid ), '' );
        $locked = is_string( $active ) && '' !== $active;
        $active_matches = $locked && hash_equals( $id, $active );
        $state = (string) $record['state'];
        $index = get_option( self::latest_key( $site_uuid ), array() );
        $historical_verified = 'VERIFIED' === $state && $locked && ! $active_matches
            && is_array( $index ) && isset( $index['operation_id'] )
            && ! hash_equals( $id, (string) $index['operation_id'] );
        if ( $locked && ! $active_matches && ! $historical_verified )
            $state = 'OPERATION_LOCK_CONFLICT';
        elseif ( 'VERIFIED' === $state && $locked && ! $historical_verified )
            $state = 'VERIFIED_LOCK_HELD';
        elseif ( 'FINALIZING' === $state ) $state = $locked
            ? 'FINALIZATION_LOCK_HELD' : 'FINALIZATION_INCOMPLETE';
        elseif ( in_array( $state, array( 'EXECUTION_UNCERTAIN', 'RECOVERY_REQUIRED' ), true )
            && ! $locked ) $state = 'OPERATION_LOCK_MISSING';
        return array( 'state' => $state, 'operation_id' => $id,
            'site_uuid' => $site_uuid, 'plan_sha256' => (string) $record['plan_sha256'],
            'post_execution_plan_sha256' => isset( $record['post_execution_plan_sha256'] )
                ? (string) $record['post_execution_plan_sha256'] : '',
            'lock_held' => $locked, 'lock_matches_operation' => $active_matches,
            'started_at' => (int) ( $record['started_at'] ?? 0 ),
            'updated_at' => (int) ( $record['updated_at'] ?? 0 ),
            'readback_required' => true, 'blind_retry_allowed' => false,
            'mutation_performed' => false, 'read_only' => true );
    }
    public static function inspect_plan( array $plan ) {
        $id = self::id( $plan );
        return '' === $id ? array( 'state' => 'INVALID_PLAN_IDENTITY', 'read_only' => true )
            : self::inspect_id( $id, strtolower( (string) $plan['site_uuid'] ) );
    }
    public static function reserve( array $plan ) {
        $id = self::id( $plan );
        if ( '' === $id ) return new WP_Error( 'mad4b_operation_plan_invalid', 'Exact operation identity required.' );
        $latest = self::latest( strtolower( $plan['site_uuid'] ) );
        if ( ! in_array( $latest['state'], array(
            'NO_CURRENT_OPERATION_POINTER', 'VERIFIED' ), true ) )
            return new WP_Error( 'mad4b_previous_operation_needs_reconciliation',
                'A previous Staging operation has an uncertain or recovery-required outcome. Reconcile before a different plan.',
                array( 'operation_id' => isset( $latest['operation_id'] )
                    ? (string) $latest['operation_id'] : '' ) );
        $existing = get_option( self::key( $id ), array() );
        if ( $existing ) return new WP_Error( 'mad4b_operation_readback_required',
            'This exact operation has already been reserved; read its audit, candidate binding and runtime status before any new operation.',
            array( 'operation_id' => $id, 'state' => self::inspect_plan( $plan )['state'] ) );
        // A second atomic site-scoped lock rejects concurrent *different*
        // plans as well as identical plans. A failed or interrupted attempt
        // holds the lock until governed recovery establishes its outcome.
        if ( ! add_option( self::active_key( $plan['site_uuid'] ), $id, '', false ) )
            return new WP_Error( 'mad4b_site_operation_lock_held',
                'A site operation is in progress or requires recovery; do not submit another Apply.',
                array( 'last_operation' => self::latest( strtolower( $plan['site_uuid'] ) ) ) );
        $record = array( 'contract' => self::CONTRACT, 'operation_id' => $id,
            'plan_sha256' => strtolower( $plan['plan_sha256'] ),
            'site_uuid' => strtolower( $plan['site_uuid'] ),
            'state' => 'EXECUTION_UNCERTAIN',
            'started_at' => time(), 'updated_at' => time(),
            'result_receipt_sha256' => '' );
        $record['signature'] = self::signature( $record );
        if ( '' === $record['signature'] ) return new WP_Error(
            'mad4b_operation_signing_unavailable', 'Operation journal signature unavailable.' );
        // add_option is the cross-request unique key claim, unlike a PHP mutex.
        if ( ! add_option( self::key( $id ), $record, '', false ) )
            return new WP_Error( 'mad4b_operation_readback_required',
                'Concurrent or previous attempt exists, or journal storage is unavailable. Do not retry blindly.',
                array( 'operation_id' => $id ) );
        if ( 'EXECUTION_UNCERTAIN' !== self::inspect_plan( $plan )['state'] )
            return new WP_Error( 'mad4b_operation_reservation_unverified',
                'Operation reserved but readback is unverified; do not retry.',
                array( 'operation_id' => $id ) );
        // A convenience pointer is not authority; the signed immutable key is.
        update_option( self::latest_key( $record['site_uuid'] ), array(
            'operation_id' => $id, 'site_uuid' => $record['site_uuid'] ), false );
        $latest = self::latest( $record['site_uuid'] );
        if ( ! isset( $latest['operation_id'] ) || ! hash_equals( $id, $latest['operation_id'] ) )
            return new WP_Error( 'mad4b_operation_pointer_unverified',
                'Reservation exists but current-operation index is unavailable; do not execute or retry.',
                array( 'operation_id' => $id ) );
        return array( 'operation_id' => $id, 'state' => 'EXECUTION_UNCERTAIN',
            'mutation_performed' => false, 'blind_retry_allowed' => false );
    }
    private static function transition( array $plan, $state, $receipt = '', $post_plan = '' ) {
        $id = self::id( $plan );
        if ( '' === $id ) return false;
        $record = get_option( self::key( $id ), array() );
        if ( ! self::valid( $id, $record ) ) return false;
        $from = (string) $record['state'];
        if ( 'EXECUTION_UNCERTAIN' === $from && 'FINALIZING' === $state ) {
            if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $receipt )
                || 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $post_plan ) ) return false;
            $record['result_receipt_sha256'] = $receipt;
            $record['post_execution_plan_sha256'] = $post_plan;
        } elseif ( 'FINALIZING' === $from && 'VERIFIED' === $state ) {
            if ( ! empty( $receipt ) || ! empty( $post_plan ) ) return false;
        } elseif ( 'EXECUTION_UNCERTAIN' !== $from || 'RECOVERY_REQUIRED' !== $state ) {
            return false;
        }
        $record['state'] = $state;
        $record['updated_at'] = time();
        $record['signature'] = self::signature( $record );
        if ( '' === $record['signature'] || ! update_option( self::key( $id ), $record, false ) )
            return false;
        $observed = self::inspect_plan( $plan );
        $wanted = 'FINALIZING' === $state ? 'FINALIZATION_LOCK_HELD'
            : ( 'VERIFIED' === $state ? 'VERIFIED' : 'RECOVERY_REQUIRED' );
        return $wanted === $observed['state'];
    }
    public static function complete( array $plan, $receipt, $post_plan = '' ) {
        $id = self::id( $plan );
        if ( '' === $id ) return false;
        // Two-phase terminal commit: never publish VERIFIED while an
        // old site lock remains. A crash is diagnosed as FINALIZING.
        if ( ! self::transition( $plan, 'FINALIZING', $receipt, $post_plan ) ) return false;
        $active = get_option( self::active_key( $plan['site_uuid'] ), '' );
        if ( ! is_string( $active ) || ! hash_equals( $id, $active )
            || ! function_exists( 'delete_option' ) ) return false;
        if ( ! delete_option( self::active_key( $plan['site_uuid'] ) )
            || '' !== get_option( self::active_key( $plan['site_uuid'] ), '' ) ) return false;
        return self::transition( $plan, 'VERIFIED' )
            && 'VERIFIED' === self::inspect_plan( $plan )['state'];
    }
    public static function suspend( array $plan ) {
        $status = self::inspect_plan( $plan );
        if ( 'VERIFIED' === $status['state'] || 'RECOVERY_REQUIRED' === $status['state'] ) return true;
        if ( in_array( $status['state'], array( 'FINALIZATION_LOCK_HELD',
            'FINALIZATION_INCOMPLETE', 'VERIFIED_LOCK_HELD' ), true ) ) return false;
        return self::transition( $plan, 'RECOVERY_REQUIRED' );
    }
    public static function latest( $site_uuid ) {
        $ptr = get_option( self::latest_key( (string) $site_uuid ), array() );
        if ( ! $ptr && get_option( self::active_key( (string) $site_uuid ), '' ) )
            return array( 'state' => 'OPERATION_LOCK_WITHOUT_INDEX', 'read_only' => true,
                'blind_retry_allowed' => false );
        if ( ! is_array( $ptr ) || ! isset( $ptr['site_uuid'], $ptr['operation_id'] )
            || ! hash_equals( (string) $site_uuid, (string) $ptr['site_uuid'] ) )
            return array( 'state' => 'NO_CURRENT_OPERATION_POINTER', 'read_only' => true );
        return self::inspect_id( $ptr['operation_id'], $site_uuid );
    }
}
