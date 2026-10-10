<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * WordPress-hosted operational identity fence.
 * This class does not grant permissions; it checks enrolled scope only.
 */
final class MAD4B_SCP_Operational_Scope_Guard {
    const CONTRACT = 'mad4b.wordpress-operational-scope.v1';

    public static function require_current() {
        if ( ! class_exists( 'MAD4B_SCP_Deployment_Mode_Resolver', false ) )
            return new WP_Error( 'mad4b_scope_resolver_missing', 'WordPress scope resolver is unavailable.' );
        $resolved = MAD4B_SCP_Deployment_Mode_Resolver::resolve();
        if ( ! is_array( $resolved ) || 'RESOLVED_FOR_REVIEW_ONLY' !== ( $resolved['status'] ?? '' ) ||
             'wordpress_dedicated' !== ( $resolved['active_mode'] ?? '' ) ||
             ! isset( $resolved['scope'] ) || ! is_array( $resolved['scope'] ) ) {
            return new WP_Error( 'mad4b_scope_not_bound',
                'Site and Brand identity must be enrolled and verified before this operation.',
                array( 'reason' => is_array( $resolved ) ? sanitize_key( (string) ( $resolved['reason'] ?? 'not_bound' ) ) : 'resolver_invalid' ) );
        }
        $scope = $resolved['scope'];
        if ( empty( $scope['site_uuid'] ) || empty( $scope['brand_ref'] ) ||
             empty( $scope['tenant_ref'] ) || empty( $scope['blog_id'] ) || empty( $scope['network_id'] ) )
            return new WP_Error( 'mad4b_scope_incomplete', 'Verified operational scope is incomplete.' );
        return $scope;
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
