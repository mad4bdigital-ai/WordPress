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

    /**
     * Never accept a caller-supplied/stale scope as a source of authority.
     * The optional argument is a claim and must exactly match current identity.
     */
    public static function require_brand( $candidate, $scope = null ) {
        $current = self::require_current();
        if ( is_wp_error( $current ) ) return $current;
        if ( null !== $scope ) {
            if ( ! is_array( $scope ) ) return new WP_Error( 'mad4b_scope_assertion_invalid', 'Scope assertion is invalid.' );
            foreach ( array( 'tenant_ref', 'site_uuid', 'brand_ref', 'blog_id', 'network_id', 'environment', 'deployment_mode' ) as $name ) {
                if ( ! isset( $scope[ $name ] ) || (string) $scope[ $name ] !== (string) $current[ $name ] ) {
                    return new WP_Error( 'mad4b_scope_assertion_stale', 'Scope assertion differs from the currently enrolled WordPress identity.' );
                }
            }
        }
        $candidate = strtolower( trim( (string) $candidate ) );
        if ( '' === $candidate || ! hash_equals( (string) $current['brand_ref'], $candidate ) ) {
            return new WP_Error( 'mad4b_brand_scope_mismatch', 'Requested Brand differs from current verified Brand.' );
        }
        return $current;
    }

    public static function source_in_scope( $record, $scope ) {
        $trusted = self::require_current();
        if ( is_wp_error( $trusted ) || ! is_array( $record ) || ! is_array( $scope ) ||
            empty( $record['site_uuid'] ) || empty( $record['brand_id'] ) ) return false;
        foreach ( array( 'tenant_ref', 'site_uuid', 'brand_ref', 'blog_id', 'network_id', 'environment', 'deployment_mode' ) as $name ) {
            if ( ! isset( $scope[ $name ] ) || (string) $scope[ $name ] !== (string) $trusted[ $name ] ) return false;
        }
        return hash_equals( strtolower( (string) $trusted['site_uuid'] ), strtolower( (string) $record['site_uuid'] ) )
            && hash_equals( strtolower( (string) $trusted['brand_ref'] ), strtolower( (string) $record['brand_id'] ) );
    }
}
