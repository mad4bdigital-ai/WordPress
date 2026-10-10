<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * WordPress-hosted operational identity fence.
 * This class does not grant permissions; it checks enrolled scope only.
 */
final class MAD4B_SCP_Operational_Scope_Guard {
    const CONTRACT = 'mad4b.wordpress-operational-scope.v1';

    /**
     * Compatibility façade. All enforcement shares the strong, revision-bound
     * Operational Integrity checkpoint; never fall back to weaker identity.
     */
    public static function require_current() {
        if ( ! class_exists( 'MAD4B_SCP_Operational_Integrity', false ) ) {
            return new WP_Error( 'mad4b_scope_integrity_missing', 'Shared trusted scope is unavailable.' );
        }
        $checkpoint = MAD4B_SCP_Operational_Integrity::capture();
        return is_wp_error( $checkpoint ) ? $checkpoint : $checkpoint['scope'];
    }

    public static function require_brand( $candidate, $scope = null ) {
        if ( null === $scope ) $scope = self::require_current();
        if ( is_wp_error( $scope ) ) return $scope;
        $candidate = strtolower( trim( (string) $candidate ) );
        $expected = strtolower( trim( (string) $scope['brand_ref'] ) );
        if ( '' === $candidate || ! hash_equals( $expected, $candidate ) )
            return new WP_Error( 'mad4b_brand_scope_mismatch', 'Requested Brand differs from current verified Brand.' );
        return $scope;
    }

    public static function source_in_scope( $record, $scope ) {
        if ( ! is_array( $scope ) || ! is_array( $record ) ||
             empty( $record['site_uuid'] ) || empty( $record['brand_id'] ) ) return false;
        return hash_equals( strtolower( (string) $scope['site_uuid'] ), strtolower( (string) $record['site_uuid'] ) )
            && hash_equals( strtolower( (string) $scope['brand_ref'] ), strtolower( (string) $record['brand_id'] ) );
    }
}
