<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Non-executing bounded bulk change preparation. No fan-out or grants. */
final class MAD4B_SCP_CSO_Bulk {
    const CONTRACT = 'mad4b.cso.bulk-plan.v1';
    public static function plan( $plans, $selection, $canary = 1 ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'bulk' ) ||
            ! MAD4B_SCP_CSO_Scope::enabled( 'single_write' ) ||
            ! MAD4B_SCP_CSO_Scope::first_party_session() ||
            ! is_array( $plans ) || count( $plans ) < 1 ||
            count( $plans ) > 32 || ! is_array( $selection ) ||
            ! is_int( $canary ) || $canary < 1 || $canary > 5 ||
            $canary > count( $plans ) ||
            true !== MAD4B_SCP_CSO_Scope::bounded( $plans ) )
            return MAD4B_SCP_CSO_Scope::error( 'BULK_REQUEST_NOT_ADMISSIBLE' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ) return $scope;
        $digests = array();
        foreach ( $plans as $plan ) {
            if ( ! is_array( $plan ) || ! isset( $plan['plan'], $plan['plan_sha256'] ) ||
                ! is_array( $plan['plan'] ) || ! is_string( $plan['plan_sha256'] ) ||
                ! preg_match( '/^[a-f0-9]{64}$/D', $plan['plan_sha256'] ) )
                return MAD4B_SCP_CSO_Scope::error( 'BULK_PLAN_INVALID' );
            $material = MAD4B_SCP_CSO_Scope::unseal( $plan['plan'],
                MAD4B_SCP_CSO_Changes::CONTRACT );
            if ( is_wp_error( $material ) || ! is_array( $material ) ||
                ! hash_equals( $plan['plan_sha256'],
                    MAD4B_SCP_CSO_Scope::digest( $material ) ) ||
                ( $material['contract'] ?? '' ) !== MAD4B_SCP_CSO_Changes::CONTRACT ||
                ( $material['expires_at'] ?? 0 ) <= time() ||
                ! hash_equals( (string) ( $material['scope_fingerprint'] ?? '' ),
                    (string) $scope['binding_sha256'] ) ||
                ! hash_equals( (string) ( $material['actor_sha256'] ?? '' ),
                    (string) $scope['actor_sha256'] ) ||
                ! empty( $material['write_authorized'] ) )
                return MAD4B_SCP_CSO_Scope::error( 'BULK_PLAN_SCOPE_STALE' );
            $digest = $plan['plan_sha256'];
            if ( isset( $digests[ $digest ] ) )
                return MAD4B_SCP_CSO_Scope::error( 'BULK_DUPLICATE_PLAN' );
            $digests[ $digest ] = true;
        }
        if ( $selection &&
            ( array_keys( $selection ) !== array( 'indexes' ) ||
                ! is_array( $selection['indexes'] ) ||
                count( $selection['indexes'] ) !== count( $plans ) ) )
            return MAD4B_SCP_CSO_Scope::error( 'BULK_SELECTION_NOT_EXACT' );
        if ( $selection ) {
            $indexes = $selection['indexes'];
            sort( $indexes, SORT_NUMERIC );
            if ( $indexes !== range( 0, count( $plans ) - 1 ) )
                return MAD4B_SCP_CSO_Scope::error( 'BULK_SELECTION_NOT_EXACT' );
        }
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return MAD4B_SCP_CSO_Scope::error( 'BULK_SCOPE_CHANGED' );
        $digest = MAD4B_SCP_CSO_Scope::digest( array_keys( $digests ) );
        return array( 'contract' => self::CONTRACT,
            'count' => count( $plans ),
            'plan_sha256' => $digest,
            'canary_size' => $canary,
            'selection' => 'all_explicit_exact_scope',
            'commit_allowed' => false, 'mutation_performed' => false,
            'failure_mode' => 'stop_and_reconcile',
            'next_safe_action' => 'register_governed_per_item_native_executor' );
    }

    public static function commit( $plan, $governance, $checkpoint, $limit ) {
        return MAD4B_SCP_CSO_Scope::error( 'BULK_NATIVE_EXECUTOR_NOT_CERTIFIED' );
    }
}
