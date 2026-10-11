<?php
/**
 * Explicit, enrolled-owner delegated self-certification for a single STAGING
 * install. This is NOT CI, Ed25519 attestation or production certification.
 * The pre-existing governed updater owns all filesystem mutations and rollback.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_WP_Native_Self_Approval {
    const CONTRACT = 'mad4b.wp-native-site-self-approval.v1';
    const PLAN = 'mad4b/wp-native-self-approve-plan';
    const APPLY = 'mad4b/wp-native-self-approve-install';
    const CONFIRM = 'INSTALL SELF APPROVED PACKAGE ON STAGING';
    const REPO = 'mad4bdigital-ai/WordPress';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $sha = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' );
        $reason = array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 );
        $base = array( 'expected_head' => $sha, 'reason' => $reason );
        $defs = array(
            array( self::PLAN, 'Review self-certified Staging install', 'plan', true,
                array( 'MAD4B_SCP_Policy', 'can_read' ),
                array( 'type' => 'object', 'additionalProperties' => false,
                    'required' => array( 'expected_head', 'reason' ), 'properties' => $base ) ),
            array( self::APPLY, 'Owner-approved self-certified Staging install', 'apply', false,
                array( __CLASS__, 'can_apply' ),
                array( 'type' => 'object', 'additionalProperties' => false,
                    'required' => array( 'expected_head', 'reason', 'expected_plan_sha256', 'confirmation' ),
                    'properties' => array_merge( $base, array(
                        'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                        'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRM ) ) ) ) ) ),
        );
        foreach ( $defs as $def ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $def[0] ) ) continue;
            wp_register_ability( $def[0], array(
                'label' => $def[1], 'description' => 'Explicit, owner-governed self-certified LOCAL Staging installation; no external tests or signatures claimed.',
                'category' => $def[3] ? 'mad4b-read' : 'mad4b-admin',
                'execute_callback' => array( __CLASS__, $def[2] ),
                'permission_callback' => $def[4],
                'input_schema' => $def[5], 'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool',
                        'surface' => $def[3] ? 'read' : 'admin',
                        'production_mutation_allowed' => false ),
                    'annotations' => array( 'readonly' => $def[3],
                        'destructive' => ! $def[3], 'idempotent' => $def[3] ) ),
            ) );
        }
    }

    private static function staging() {
        if ( ! class_exists( 'MAD4B_SCP_WP_Native_Build' ) ||
            ! class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' ) ||
            ! MAD4B_SCP_WordPress_Native_Opt_In::enabled() ||
            ! function_exists( 'wp_get_environment_type' ) ||
            'staging' !== wp_get_environment_type() ) return false;
        $cap = MAD4B_SCP_WP_Native_Build::capabilities();
        return is_array( $cap ) && ! empty( $cap['eligible'] );
    }

    private static function input( $input ) {
        return is_array( $input ) && isset( $input['expected_head'], $input['reason'] )
            && is_string( $input['expected_head'] )
            && preg_match( '/^[a-f0-9]{40}$/D', $input['expected_head'] )
            && is_string( $input['reason'] )
            && strlen( $input['reason'] ) >= 3 && strlen( $input['reason'] ) <= 500;
    }

    public static function plan( $input = array() ) {
        if ( ! self::input( $input ) || count( $input ) !== 2 )
            return new WP_Error( 'mad4b_self_approval_input', 'Exact immutable SHA and review reason required.' );
        $sha = $input['expected_head'];
        $reason = function_exists( 'sanitize_text_field' )
            ? sanitize_text_field( $input['reason'] ) : trim( $input['reason'] );
        $site = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
        $blockers = array();
        if ( ! self::staging() ) $blockers[] = 'exact_enrolled_staging_self_approval_disabled';
        $sim = self::staging() ? MAD4B_SCP_WP_Native_Build::simulate(
            array( 'expected_head' => $sha ) ) : array();
        if ( is_wp_error( $sim ) ) return $sim;
        if ( ( $sim['state'] ?? '' ) !== 'LOCAL_SELF_CHECKS_PASSED_UNTRUSTED' )
            $blockers[] = 'local_zip_receipt_or_profile_unverified';
        $status = class_exists( 'MAD4B_SCP_WP_Native_Build' )
            ? MAD4B_SCP_WP_Native_Build::status() : array();
        $identity = array(
            'version' => (string) ( $status['canonical_receipt']['control_plane_version'] ?? '' ),
            'source_commit_sha' => $sha,
            'archive_sha256' => (string) ( $status['archive_sha256'] ?? '' ),
            'build_fingerprint' => (string) ( $status['build_fingerprint'] ?? '' ),
            'package_manifest_digest' => (string) ( $status['package_manifest_digest'] ?? '' ),
            'size_bytes' => (int) ( $status['archive_bytes'] ?? 0 ),
        );
        $candidate = array( 'repository' => self::REPO, 'type' => 'commit', 'reference' => $sha );
        $upload = array();
        if ( empty( $blockers ) && class_exists( 'MAD4B_SCP_Self_Update' ) ) {
            $upload = MAD4B_SCP_Self_Update::upload_plan( array_merge( $identity, array(
                'channel' => 'wordpress_native_candidate_upload',
                'candidate_source' => $candidate, 'reason' => $reason ) ) );
            if ( is_wp_error( $upload ) ) $blockers[] = $upload->get_error_code();
            elseif ( empty( $upload['eligible'] ) )
                $blockers = array_merge( $blockers, (array) ( $upload['blockers'] ?? array() ) );
        } else if ( ! class_exists( 'MAD4B_SCP_Self_Update' ) ) {
            $blockers[] = 'governed_staging_updater_missing';
        }
        $bind = array(
            'contract' => self::CONTRACT . '.plan.v1',
            'evidence_mode' => 'site_self_certified_staging',
            'site_uuid' => (string) ( $site['site_uuid'] ?? '' ),
            'profile_digest' => (string) ( $site['profile_digest'] ?? '' ),
            'origin' => (string) ( $site['canonical_origin'] ?? '' ),
            'identity' => $identity, 'candidate_source' => $candidate,
            'underlying_upload_plan_sha256' => is_array( $upload ) ? (string) ( $upload['plan_sha256'] ?? '' ) : '',
            'canonical_receipt_sha256' => (string) ( $status['canonical_receipt_sha256'] ?? '' ),
            'local_checks' => (array) ( $sim['local_checks'] ?? array() ),
            'reason' => $reason,
        );
        $blockers = array_values( array_unique( $blockers ) );
        sort( $blockers, SORT_STRING );
        return array_merge( $bind, array(
            'eligible' => empty( $blockers ), 'blockers' => $blockers,
            'plan_sha256' => hash( 'sha256', wp_json_encode( $bind ) ),
            'self_certification_authorized_by_owner_at_apply' => true,
            'independent_signature_present' => false, 'native_php_matrix_executed' => false,
            'release_certified' => false, 'production_authorized' => false,
            'confirmation' => self::CONFIRM, 'authorizing' => false, 'mutation_performed' => false,
        ) );
    }

    public static function can_apply( $input = array() ) {
        if ( ! self::staging() || ! function_exists( 'current_user_can' ) ||
            ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ||
            ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) ||
            ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_mutate() )
            return new WP_Error( 'mad4b_self_approval_owner_required', 'Enrolled Staging owner required.' );
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope(
                MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ||
            ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
                MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) )
            return new WP_Error( 'mad4b_self_approval_oauth_required', 'Owner OAuth step-up required.' );
        if ( ! class_exists( 'MAD4B_SCP_Self_Update' ) ||
            ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return new WP_Error( 'mad4b_self_approval_governance_missing', 'Existing updater and authorization required.' );
        // Retain the ORIGINAL upload grant and one-time approval. Never
        // treat an authorization decision envelope as boolean permission.
        $authority_input = is_array( $input ) ? $input : array();
        $authority_input['channel'] = 'wordpress_native_candidate_upload';
        $decision = MAD4B_SCP_Self_Update::can_upload_apply( $authority_input );
        return MAD4B_SCP_Authorization::permission_result_from_authorization( $decision );
    }

    public static function apply( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'expected_head', 'reason', 'expected_plan_sha256', 'confirmation',
                '_mad4b_approval_ticket_id', '_mad4b_context_receipt' ) ) ||
            ! self::input( $input ) ||
            ( $input['confirmation'] ?? '' ) !== self::CONFIRM ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_plan_sha256'] ?? '' ) ) )
            return new WP_Error( 'mad4b_self_approval_confirmation', 'Exact confirmed Staging plan required.' );
        $access = self::can_apply( $input );
        if ( is_wp_error( $access ) || true !== $access ) return $access;
        $sha = $input['expected_head'];
        $plan_input = array( 'expected_head' => $sha, 'reason' => $input['reason'] );
        $plan = self::plan( $plan_input );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( empty( $plan['eligible'] ) ||
            ! hash_equals( $plan['plan_sha256'], $input['expected_plan_sha256'] ) )
            return new WP_Error( 'mad4b_self_approval_plan_changed', 'Review a fresh exact local package plan.' );
        $bytes = MAD4B_SCP_WP_Native_Build::local_package_bytes( $sha );
        if ( is_wp_error( $bytes ) ) return $bytes;
        if ( strlen( $bytes ) !== $plan['identity']['size_bytes'] ||
            ! hash_equals( $plan['identity']['archive_sha256'], hash( 'sha256', $bytes ) ) )
            return new WP_Error( 'mad4b_self_approval_archive_changed', 'Local ZIP drift detected.' );
        $fresh = self::plan( $plan_input );
        if ( is_wp_error( $fresh ) || empty( $fresh['eligible'] ) ||
            ! hash_equals( $plan['plan_sha256'], $fresh['plan_sha256'] ) )
            return new WP_Error( 'mad4b_self_approval_evidence_changed', 'Self-certification evidence changed before install.' );
        $access = self::can_apply( $input );
        if ( is_wp_error( $access ) || true !== $access ) return $access;
        if ( ! class_exists( 'MAD4B_SCP_Audit' ) )
            return new WP_Error( 'mad4b_self_approval_audit_missing', 'Governed audit is mandatory.' );
        $audit = MAD4B_SCP_Audit::record( self::APPLY, array(
            'source_commit_sha' => $sha, 'site_uuid' => $plan['site_uuid'],
            'archive_sha256' => $plan['identity']['archive_sha256'],
            'plan_sha256' => $plan['plan_sha256'], 'evidence_mode' => 'site_self_certified_staging',
            'independently_signed' => false, 'native_php_matrix_executed' => false,
            'production_authorized' => false ), 'owner_delegated_self_certification' );
        if ( is_wp_error( $audit ) )
            return new WP_Error( 'mad4b_self_approval_audit_failed', 'Self-approval audit was rejected.' );
        $upload = array_merge( $plan['identity'], array(
            'channel' => 'wordpress_native_candidate_upload',
            'candidate_source' => $plan['candidate_source'], 'reason' => $plan['reason'],
            'expected_plan_sha256' => $plan['underlying_upload_plan_sha256'],
            'candidate_confirmation' => 'INSTALL EXACT STAGING CANDIDATE',
            'package_base64' => base64_encode( $bytes ),
        ) );
        unset( $bytes );
        // Same archive verifier, updater, maintenance lease, backup, readback,
        // and rollback as the existing governed Staging candidate upload.
        $result = MAD4B_SCP_Self_Update::upload_apply( $upload );
        return is_array( $result ) ? array_merge( $result, array(
            'evidence_mode' => 'site_self_certified_staging',
            'independent_attestation_verified' => false,
            'external_release_certified' => false, 'production_authorized' => false ) ) : $result;
    }
}
