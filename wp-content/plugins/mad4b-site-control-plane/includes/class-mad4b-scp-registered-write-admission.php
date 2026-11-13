<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Non-authorizing, source-registry-bound preflight for owner write Abilities.
 * The actual mutation still requires the canonical Developer Authority's
 * exact, certified, one-time plan and independent grant readback.
 *
 * No environment allowlist is encoded here: the registered operation, site
 * profile and currently enrolled governance policy are the only inputs.
 */
final class MAD4B_SCP_Registered_Write_Admission {
    const CONTRACT = 'mad4b.registered-write-admission.v1';

    public static function preflight( $ability_name ) {
        if ( ! class_exists( 'MAD4B_SCP_Operation_Registry' )
            || ! method_exists( 'MAD4B_SCP_Operation_Registry', 'registered_write_policy' )
            || ! class_exists( 'MAD4B_SCP_Policy_Resolution' )
            || ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
            return new WP_Error( 'mad4b_registered_write_policy_runtime_missing',
                'Registered operation, policy resolver, or enrolled Site Profile unavailable.' );
        }
        $registered = MAD4B_SCP_Operation_Registry::registered_write_policy( $ability_name );
        if ( is_wp_error( $registered ) ) return $registered;
        $status = MAD4B_SCP_Site_Profile::status();
        $uuid = (string) ( is_array( $status ) ? ( $status['site_uuid'] ?? '' ) : '' );
        $revision = MAD4B_SCP_Site_Profile::revision();
        $profile_digest = (string) MAD4B_SCP_Site_Profile::profile_digest();
        if ( ! is_array( $status ) || empty( $status['authority_ready'] )
            || ! method_exists( 'MAD4B_SCP_Site_Profile', 'site_uuid' )
            || ! hash_equals( strtolower( (string) MAD4B_SCP_Site_Profile::site_uuid() ), strtolower( $uuid ) )
            || ! MAD4B_SCP_Site_Profile::origin_enrolled()
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
            || ! MAD4B_SCP_Site_Profile::managed_runtime_enabled()
            || ! MAD4B_SCP_Site_Profile::write_enabled()
            || ! preg_match( '/^[a-f0-9-]{36}$/D', strtolower( $uuid ) )
            || $revision < 1 || ! preg_match( '/^[a-f0-9]{64}$/D', $profile_digest ) ) {
            return new WP_Error( 'mad4b_registered_write_site_unverified',
                'Exact enrolled company/site and managed Write profile required.' );
        }
        $environment = sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
        if ( '' === $environment ) return new WP_Error( 'mad4b_registered_write_environment_unknown',
            'Current environment is not known to the enrolled Site Profile.' );
        if ( ! MAD4B_SCP_Policy_Resolution::environment_allowed( $environment ) ) {
            return new WP_Error( 'mad4b_registered_write_environment_policy_denied',
                'Governance policy does not admit this enrolled environment.' );
        }
        // Production is not denied by name. Its high-risk runtime policy must
        // be *currently effective* and exact-profile-bound, never presumed from
        // the mere presence of the WordPress Production label.
        if ( 'production' === $environment
            && ( ! class_exists( 'MAD4B_SCP_Governed_Runtime_Gates' )
                || ! MAD4B_SCP_Governed_Runtime_Gates::production_mutation_enabled() ) ) {
            return new WP_Error( 'mad4b_registered_write_runtime_policy_required',
                'Current production mutation governance is not effective for this Site Profile.' );
        }
        $mode = MAD4B_SCP_Policy_Resolution::current_operating_mode();
        if ( is_wp_error( $mode ) ) return $mode;
        $operation = (string) $registered['operation_id'];
        $target = hash( 'sha256', $operation . '|' . $uuid . '|' . $profile_digest );
        // Non-authorizing eligibility only. The canonical write path owns
        // approval-ticket claims, plan CAS, signed provenance and receipt.
        $facts = array(
            'hard_deny' => false, 'kill_switch_active' => false,
            'environment_allowed' => true, 'authority_allowed' => true,
            'certification_allowed' => true, 'quality_release_allowed' => true,
            'approval_required' => false, 'approval_satisfied' => false,
            'grant_allowed' => true, 'feature_enabled' => true,
            'environment' => $environment, 'capability' => (string) $ability_name,
            'target_fingerprint' => $target, 'operating_mode' => $mode,
            'policy_versions' => array(
                'registered_operation' => (string) $registered['operation_sha256'],
                'governance' => (string) MAD4B_SCP_Policy_Resolution::config_digest(),
                'site_profile_revision' => (string) $revision,
                'site_profile_digest' => $profile_digest,
            ),
            'required_approvals' => array(),
        );
        $result = MAD4B_SCP_Policy_Resolution::resolve( $facts );
        if ( is_wp_error( $result ) ) return $result;
        if ( 'ALLOW' !== ( $result['decision'] ?? '' ) ) return new WP_Error(
            'mad4b_registered_write_governance_denied',
            'Registered governance policy did not admit this operation.' );
        return array(
            'contract' => self::CONTRACT, 'operation_id' => $operation,
            'ability' => (string) $ability_name, 'environment' => $environment,
            'site_uuid' => strtolower( $uuid ), 'profile_revision' => $revision,
            'profile_digest' => $profile_digest,
            'policy_sha256' => (string) ( $result['decision_sha256'] ?? '' ),
            'state' => 'eligible_for_exact_canonical_plan_only',
            'authorization_issued' => false, 'mutation_performed' => false,
        );
    }
}
