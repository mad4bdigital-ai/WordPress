<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ACI01 P0 trust boundary. No alternate authority: reuse exact MAD4B
 * Site Profile and Adaptive Operations (generation + restore-epoch) receipts.
 * This class reads and compares them; it never issues a new grant.
 */
final class MAD4B_SCP_ACI01_Runtime_Binding {
    const CONTRACT = 'mad4b.aci01.runtime-binding.v1';

    public static function current() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile', false )
            || ! class_exists( 'MAD4B_SCP_Adaptive_Operations_Context', false )
            || ! MAD4B_SCP_Site_Profile::configured() ) return self::error( 'provider_missing' );
        $status = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $status )
            || true !== ( $status['authority_ready'] ?? null )
            || true !== ( $status['origin_match'] ?? null )
            || true !== ( $status['environment_match'] ?? null )
            || true !== ( $status['deployment_binding_match'] ?? null )
            || true === ( $status['profile_authority_quarantined'] ?? null )
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return self::error( 'site_not_bound' );
        $live = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( self::is_error( $live ) || ! is_array( $live ) ) return self::error( 'epoch_or_generation_not_verified' );
        $current = array(
            'contract' => self::CONTRACT,
            'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'origin' => MAD4B_SCP_Site_Profile::site_origin(),
            'environment' => MAD4B_SCP_Site_Profile::current_environment(),
            'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
            'runtime_generation' => $live['runtime_generation'] ?? '',
            'restore_epoch' => $live['restore_epoch'] ?? null,
            'artifact_sha256' => $live['artifact_sha256'] ?? '',
            'external_record_sha256' => $live['external_record_sha256'] ?? '',
            'origin_sha256' => $live['origin_sha256'] ?? '',
        );
        if ( ! self::is_valid( $current )
            || $live['site_uuid'] !== $current['site_uuid']
            || $live['environment'] !== $current['environment']
            || $live['profile_digest'] !== $current['profile_digest']
            || $live['origin_sha256'] !== $current['origin_sha256']
            || $live['restore_epoch'] !== $current['restore_epoch']
            || $live['runtime_generation'] !== $current['runtime_generation'] ) return self::error( 'live_binding_mismatch' );
        return $current;
    }

    public static function is_valid( $x ) {
        if ( ! is_array( $x ) || ( $x['contract'] ?? '' ) !== self::CONTRACT ) return false;
        foreach ( array( 'site_uuid', 'origin', 'environment', 'profile_digest',
                         'runtime_generation', 'artifact_sha256', 'external_record_sha256', 'origin_sha256' ) as $field )
            if ( ! isset( $x[$field] ) || ! is_string( $x[$field] ) ) return false;
        if ( ! preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $x['site_uuid'] )
            || ! in_array( $x['environment'], array( 'local', 'development', 'staging', 'production' ), true )
            || ! is_int( $x['restore_epoch'] ?? null ) || $x['restore_epoch'] < 1 ) return false;
        foreach ( array( 'profile_digest', 'runtime_generation', 'artifact_sha256',
                         'external_record_sha256', 'origin_sha256' ) as $field )
            if ( ! preg_match( '/^[a-f0-9]{64}$/D', $x[$field] ) ) return false;
        $parts = parse_url( $x['origin'] );
        return is_array( $parts ) && ( $parts['scheme'] ?? '' ) === 'https'
            && ! empty( $parts['host'] ) && ! isset( $parts['user'], $parts['pass'] )
            && ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
            && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] )
            && ( ! isset( $parts['path'] ) || $parts['path'] === '' || $parts['path'] === '/' )
            && hash_equals( hash( 'sha256', $x['origin'] ), $x['origin_sha256'] );
    }

    public static function same( $expected, $current ) {
        if ( ! self::is_valid( $expected ) || ! self::is_valid( $current ) ) return false;
        foreach ( array( 'site_uuid','origin','environment','profile_digest','runtime_generation',
                         'restore_epoch','artifact_sha256','external_record_sha256','origin_sha256' ) as $k ) {
            if ( is_string( $expected[$k] ) && ! hash_equals( $expected[$k], $current[$k] ) ) return false;
            if ( is_int( $expected[$k] ) && $expected[$k] !== $current[$k] ) return false;
        }
        return true;
    }

    private static function is_error( $v ) {
        return function_exists( 'is_wp_error' ) && is_wp_error( $v );
    }

    private static function error( $reason ) {
        return new WP_Error( 'mad4b_aci01_binding_' . $reason,
            'Current governed site identity, generation and restore epoch are required.',
            array( 'authorizing' => false, 'automatic_retry_allowed' => false ) );
    }
}
