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
    public static function current_id() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::configured() ||
            ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
            ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ||
            'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) {
            return new WP_Error( 'mad4b_operator_profile_unenrolled',
                'A matching enrolled Staging Site Profile is required.' );
        }
        if ( ! defined( 'MAD4B_OPERATOR_RUNNER_PROFILE_ID' ) ) {
            return new WP_Error( 'mad4b_operator_runner_profile_unconfigured',
                'An explicitly pinned Operator runner profile is required.' );
        }
        $id = constant( 'MAD4B_OPERATOR_RUNNER_PROFILE_ID' );
        if ( ! is_string( $id ) || strlen( $id ) < 3 || strlen( $id ) > 64 ||
             1 !== preg_match( '/^[a-z][a-z0-9-]{2,63}$/D', $id ) ||
             ( function_exists( 'sanitize_key' ) && sanitize_key( $id ) !== $id ) ) {
            return new WP_Error( 'mad4b_operator_runner_profile_invalid',
                'The pinned Operator runner profile is invalid.' );
        }
        return $id;
    }
}
