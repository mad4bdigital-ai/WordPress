<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Explicit, enrolled per-site Operator runner binding.
 *
 * Site operators pin MAD4B_OPERATOR_RUNNER_PROFILE_ID in the private
 * environment configuration. No hostname-derived defaults and no fallback
 * to a previously enrolled site's identity. The external worker independently
 * pins the same profile ID and the current Site Profile target fingerprint.
 */
final class MAD4B_SCP_Operator_Runner_Profile {
    /**
     * Pure lookup for testable, signed-Registry-driven profile selection.
     * Only a previous independently verified original Host Bridge receipt
     * can supply the verified inventory in current_id(). No caller-supplied
     * profile, hostname or deployment tag is an authority.
     */
    public static function from_verified_inventory( array $registry, string $fingerprint ) {
        if ( ( $registry['contract'] ?? '' ) !== 'mad4b.company-host-topology-registry.v1'
            || ( $registry['state'] ?? '' ) !== 'VERIFIED'
            || ( $registry['registration_authority_readback'] ?? '' )
                !== 'ORIGINAL_SIGNED_RECEIPT_VERIFIED'
            || ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
            return new WP_Error( 'mad4b_operator_runner_inventory_unverified' );
        }
        $matches = array();
        foreach ( (array) ( $registry['required_nodes'] ?? array() ) as $node ) {
            if ( ! is_array( $node ) ||
                ! in_array( (string) ( $node['runtime_role'] ?? '' ),
                    array( 'wordpress_mcp', 'wordpress_webhead' ), true ) ) continue;
            $candidate_fp = (string) ( $node['target_fingerprint'] ?? '' );
            if ( strlen( $candidate_fp ) !== 64 ||
                ! hash_equals( $candidate_fp, $fingerprint ) ) continue;
            $matches[] = (string) ( $node['runner_profile_id'] ?? '' );
        }
        if ( count( $matches ) !== 1 )
            return new WP_Error( 'mad4b_operator_runner_registry_match_ambiguous' );
        $id = $matches[0];
        if ( ! preg_match( '/^[a-z][a-z0-9-]{2,63}$/D', $id ) )
            return new WP_Error( 'mad4b_operator_runner_registry_id_invalid' );
        return $id;
    }
    public static function current_id() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::configured() ||
            ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
            ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ||
            'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) {
            return new WP_Error( 'mad4b_operator_profile_unenrolled',
                'A matching enrolled Staging Site Profile is required.' );
        }
        // PERMANENT DYNAMIC RUNNER IDENTITY:
        // Preserve original explicit bootstrap configuration as a supported
        // owner-controlled route. If absent, accept ONLY a unique site UUID,
        // origin, environment and live Host fingerprint match against the
        // independently reverified SIGNED Host Inventory from Company Manager.
        // This does not grant an Operator OAuth session or broaden the
        // currently enrolled Host Runner environment policy.
        if ( defined( 'MAD4B_OPERATOR_RUNNER_PROFILE_ID' ) ) {
            $id = constant( 'MAD4B_OPERATOR_RUNNER_PROFILE_ID' );
        } else {
            $id = '';
            if ( class_exists( 'MAD4B_Company_Host_Topology_Registry_V1', false )
                && class_exists( 'MAD4B_SCP_Host_Bridge', false ) ) {
                $site = MAD4B_SCP_Site_Profile::site_uuid();
                $env = MAD4B_SCP_Site_Profile::current_environment();
                $origin = untrailingslashit( home_url( '/' ) );
                $registry = MAD4B_Company_Host_Topology_Registry_V1::verified_local_registry(
                    $site, $env, $origin
                );
                $fingerprint = MAD4B_SCP_Host_Bridge::target_fingerprint_readonly();
                $resolved = self::from_verified_inventory( $registry, $fingerprint );
                if ( ! is_wp_error( $resolved ) ) $id = $resolved;
            }
            if ( $id === '' ) {
                return new WP_Error( 'mad4b_operator_runner_profile_unconfigured',
                    'No unique verified original Host Inventory runner profile is enrolled.' );
            }
        }
        if ( ! is_string( $id ) || strlen( $id ) < 3 || strlen( $id ) > 64 ||
             1 !== preg_match( '/^[a-z][a-z0-9-]{2,63}$/D', $id ) ||
             ( function_exists( 'sanitize_key' ) && sanitize_key( $id ) !== $id ) ) {
            return new WP_Error( 'mad4b_operator_runner_profile_invalid',
                'The pinned Operator runner profile is invalid.' );
        }
        return $id;
    }
}
