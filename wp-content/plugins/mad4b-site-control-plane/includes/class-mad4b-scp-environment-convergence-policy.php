<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Shared, read-only policy decision. Discovery is evidence, never authority.
 * A non-production classification cannot override an explicit WordPress
 * environment or an inexact Site Profile/deployment binding.
 */
final class MAD4B_SCP_Environment_Convergence_Policy {
    const CONTRACT = 'mad4b.environment-convergence-policy.v1';
    public static function resolve( $resolution, $profile_ready, $origin_ready, $binding_ready ) {
        $resolution = is_array( $resolution ) ? $resolution : array();
        $wordpress = sanitize_key( (string) ( $resolution['wordpress_environment'] ?? '' ) );
        $effective = sanitize_key( (string) ( $resolution['effective_environment'] ?? '' ) );
        $profile_authoritative = ! empty( $resolution['profile_environment_authoritative'] );
        $mismatch = ! empty( $resolution['wordpress_profile_mismatch'] );
        $valid = $profile_authoritative && ! $mismatch && $profile_ready && $origin_ready && $binding_ready
            && in_array( $effective, array( 'local', 'development', 'staging', 'production' ), true );
        $nonproduction = in_array( $effective, array( 'local', 'development', 'staging' ), true );
        $auto = $valid && $nonproduction;
        return array(
            'contract' => self::CONTRACT,
            'environment' => $effective,
            'wordpress_environment' => $wordpress,
            'mode' => $auto ? 'nonproduction_auto_converge' : 'production_strict',
            'environment_authority_verified' => $valid,
            'auto_convergence_eligible' => $auto,
            'production_strict' => ! $auto,
            'automatic_candidate_binding_eligible' => $auto,
            // Only the separate, exact reviewed grant reconciler may mutate
            // a grant. This policy never creates a new grant or approval.
            'automatic_grant_mutation_authorized' => false,
            'requires_exact_package_identity' => true,
            'requires_exact_reviewed_grants' => true,
            'requires_oauth_identity' => true,
            'requires_audit' => true,
            'mutation_performed' => false,
            'blockers' => $valid ? array() : array( 'exact_site_environment_authority_required' ),
        );
    }
    public static function current() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! method_exists( 'MAD4B_SCP_Site_Profile', 'environment_resolution' ) ) {
            return self::resolve( array(), false, false, false );
        }
        $resolution = MAD4B_SCP_Site_Profile::environment_resolution();
        $status = MAD4B_SCP_Site_Profile::status();
        return self::resolve(
            $resolution,
            ! empty( $status['authority_ready'] ),
            MAD4B_SCP_Site_Profile::origin_enrolled()
                && MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),
            ! empty( $status['deployment_binding_match'] )
        );
    }
}
