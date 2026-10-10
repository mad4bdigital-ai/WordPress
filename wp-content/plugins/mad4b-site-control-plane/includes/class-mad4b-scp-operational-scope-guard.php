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
        return self::record_metadata_matches( $record, $trusted );
    }

    /**
     * A pure assertion shared by Context sources, assets, and other bounded
     * consumers. Legacy records lacking mandatory site/brand ownership remain
     * quarantined; optional stored tenancy/multisite metadata may never
     * contradict the trusted deployment binding. No database writes or grants.
     */
    public static function record_metadata_matches( $record, $trusted ) {
        if ( ! is_array( $record ) || ! is_array( $trusted ) ) return false;
        $required = array( 'site_uuid' => 'site_uuid', 'brand_id' => 'brand_ref' );
        $optional = array(
            'tenant_ref' => 'tenant_ref',
            'tenant_id' => 'tenant_ref',
            'blog_id' => 'blog_id',
            'network_id' => 'network_id',
            'environment' => 'environment',
            'deployment_mode' => 'deployment_mode',
        );
        foreach ( $required + $optional as $record_key => $scope_key ) {
            if ( ! array_key_exists( $record_key, $required ) &&
                ! array_key_exists( $record_key, $record ) ) continue;
            if ( ! isset( $record[ $record_key ], $trusted[ $scope_key ] ) ||
                ! is_scalar( $record[ $record_key ] ) ) return false;
            $actual = trim( (string) $record[ $record_key ] );
            $expected = (string) $trusted[ $scope_key ];
            if ( in_array( $record_key, array( 'site_uuid', 'brand_id' ), true ) ) {
                $actual = strtolower( $actual );
                $expected = strtolower( $expected );
            }
            if ( '' === $actual || ! hash_equals( $expected, $actual ) ) return false;
        }
        return true;
    }

    /**
     * Read-only legacy census: never infer a missing owner, move a row,
     * touch a WordPress option, or expose source content/record identifiers.
     * Explicitly foreign and incomplete records remain quarantined for a
     * separately approved, backup-backed migration with independent readback.
     */
    public static function legacy_reconciliation_census( $records ) {
        $trusted = self::require_current();
        if ( is_wp_error( $trusted ) ) return $trusted;
        if ( ! is_array( $records ) || count( $records ) > 1000 )
            return new WP_Error( 'mad4b_legacy_census_unbounded', 'Provide a bounded read-only legacy inventory.' );
        $counts = array(
            'already_owned' => 0,
            'owner_unknown' => 0,
            'foreign_scope' => 0,
            'conflicting_metadata' => 0,
            'malformed' => 0,
        );
        foreach ( $records as $record ) {
            if ( ! is_array( $record ) ) { ++$counts['malformed']; continue; }
            $site = isset( $record['site_uuid'] ) && is_scalar( $record['site_uuid'] ) ? strtolower( trim( (string) $record['site_uuid'] ) ) : '';
            $brand = isset( $record['brand_id'] ) && is_scalar( $record['brand_id'] ) ? strtolower( trim( (string) $record['brand_id'] ) ) : '';
            if ( '' === $site || '' === $brand ) { ++$counts['owner_unknown']; continue; }
            if ( ! hash_equals( strtolower( (string) $trusted['site_uuid'] ), $site ) ||
                ! hash_equals( strtolower( (string) $trusted['brand_ref'] ), $brand ) ) {
                ++$counts['foreign_scope']; continue;
            }
            if ( ! self::record_metadata_matches( $record, $trusted ) ) {
                ++$counts['conflicting_metadata']; continue;
            }
            ++$counts['already_owned'];
        }
        return array(
            'contract' => 'mad4b.legacy-reconciliation-census.v1',
            'counts' => $counts,
            'rows_inspected' => count( $records ),
            'quarantined' => $counts['owner_unknown'] + $counts['foreign_scope'] +
                $counts['conflicting_metadata'] + $counts['malformed'],
            'mutation_performed' => false,
            'migration_authorized' => false,
            'blind_reassignment_allowed' => false,
            'next_safe_action' => 'review_owner_evidence_and_backup_before_any_individual_migration',
        );
    }
}
