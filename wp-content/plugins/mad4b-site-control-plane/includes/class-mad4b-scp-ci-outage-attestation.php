<?php
/**
 * Source-bound CI-outage receipt verification.
 *
 * This is NOT a universal CI bypass. A separate owner-enrolled Ed25519
 * public key attests exact native test evidence for one Staging package,
 * site and short validity window. The signing key never enters WordPress.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_CI_Outage_Attestation {
    const CONTRACT = 'mad4b.staging-ci-outage-attestation.v1';
    const KEY_OPTION = 'mad4b_scp_offline_attestor_public_key_v1';
    const MAX_CLAIMS_BYTES = 8192;
    const MAX_LIFETIME = 86400;
    const REQUIRED_GATES = array(
        'g9_delivery_contract',
        'php83_tree_syntax',
        'canonical_package_receipt',
        'isolated_zip_runtime_integrity',
        'exact_source_verification',
    );

    const ENROLL_PLAN = 'mad4b/staging-offline-attestor-enroll-plan';
    const ENROLL_APPLY = 'mad4b/staging-offline-attestor-enroll-apply';
    const ENROLL_CONFIRM = 'ENROLL STAGING OFFLINE ATTESTOR';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 40 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        foreach ( array(
            array( self::ENROLL_PLAN, 'Plan Staging Offline Attestor Trust Enrollment', 'enroll_plan', true ),
            array( self::ENROLL_APPLY, 'Enroll Exact Staging Offline Attestor Public Key', 'enroll_apply', false ),
        ) as $item ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $item[0] ) ) continue;
            $schema = self::enroll_schema();
            if ( ! $item[3] ) {
                $schema['required'][] = 'expected_plan_sha256';
                $schema['required'][] = 'confirmation';
                $schema['properties']['expected_plan_sha256'] = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
                $schema['properties']['confirmation'] = array( 'type' => 'string', 'enum' => array( self::ENROLL_CONFIRM ) );
            }
            wp_register_ability( $item[0], array(
                'label' => $item[1], 'description' => 'Exact enrolled Staging admin attestor public-key enrollment. Never reads or stores signing secrets.',
                'category' => $item[3] ? 'mad4b-read' : 'mad4b-admin',
                'execute_callback' => array( __CLASS__, $item[2] ),
                'permission_callback' => $item[3] ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( __CLASS__, 'can_enroll' ),
                'input_schema' => $schema,
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $item[3] ? 'read' : 'admin' ),
                    'annotations' => array( 'readonly' => $item[3], 'destructive' => ! $item[3], 'idempotent' => $item[3] ) ),
            ) );
        }
    }

    private static function enroll_schema() {
        return array( 'type' => 'object', 'additionalProperties' => false,
            'required' => array( 'public_key_base64', 'reason' ), 'properties' => array(
                'public_key_base64' => array( 'type' => 'string', 'minLength' => 44, 'maxLength' => 44 ),
                'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
            ),
        );
    }

    public static function enroll_plan( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'public_key_base64', 'reason' ) ) )
            return self::failure( 'enroll_input_invalid', 'Only exact attestor public key and reason accepted.' );
        $key = (string) ( $input['public_key_base64'] ?? '' );
        $raw = base64_decode( $key, true );
        if ( ! is_string( $raw ) || strlen( $raw ) !== 32 || base64_encode( $raw ) !== $key )
            return self::failure( 'enroll_key_invalid', 'Canonical 32-byte Ed25519 public key required.' );
        $reason = trim( (string) ( $input['reason'] ?? '' ) );
        if ( strlen( $reason ) < 3 || strlen( $reason ) > 500 )
            return self::failure( 'enroll_reason_invalid', 'Bounded enrollment reason required.' );
        $site = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
        $existing = get_option( self::KEY_OPTION, '' );
        $blockers = array();
        if ( ! is_array( $site ) || 'staging' !== (string) ( $site['configured_environment'] ?? '' ) ||
            'staging' !== (string) ( $site['wordpress_environment'] ?? '' ) ||
            empty( $site['authority_ready'] ) || empty( $site['origin_match'] ) ||
            empty( $site['site_uuid'] ) || empty( $site['profile_digest'] ) )
            $blockers[] = 'exact_enrolled_staging_required';
        if ( is_string( $existing ) && '' !== $existing && ! hash_equals( $existing, $key ) )
            $blockers[] = 'existing_signing_trust_rotation_requires_separate_review';
        $plan = array(
            'contract' => self::CONTRACT . '.enroll-plan.v1',
            'site_uuid' => (string) ( $site['site_uuid'] ?? '' ),
            'profile_digest' => (string) ( $site['profile_digest'] ?? '' ),
            'origin' => (string) ( $site['canonical_origin'] ?? '' ),
            'actor_user_id' => get_current_user_id(),
            'public_key_sha256' => hash( 'sha256', $raw ),
            'existing_public_key_sha256' => '' === $existing ? '' : hash( 'sha256', (string) base64_decode( $existing, true ) ),
            'reason' => $reason, 'eligible' => empty( $blockers ), 'blockers' => $blockers,
            'already_enrolled' => is_string( $existing ) && '' !== $existing && hash_equals( $existing, $key ),
            'ci_required_for_staging' => false, 'production_allowed' => false,
            'read_only' => true, 'authorizing' => false,
        );
        $plan['plan_sha256'] = hash( 'sha256', wp_json_encode( $plan ) );
        return $plan;
    }

    public static function can_enroll( $input = null ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! current_user_can( 'manage_options' ) ||
            ! current_user_can( 'update_plugins' ) ||
            ! MAD4B_SCP_Policy::can_mutate() ||
            ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) )
            return self::failure( 'enroll_admin_required', 'Enrolled Staging WordPress plugin administrator required.' );
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ||
            ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) )
            return self::failure( 'enroll_owner_stepup_required', 'Exact ChatGPT owner authority step-up required.' );
        if ( ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return self::failure( 'enroll_grant_unavailable', 'Dedicated Staging write authorization unavailable.' );
        return MAD4B_SCP_Authorization::authorize_mutation(
            self::ENROLL_APPLY, 'mad4b-admin', 'core', is_array( $input ) ? $input : array()
        );
    }

    public static function enroll_apply( $input = array() ) {
        if ( ! is_array( $input ) ||
            ! hash_equals( self::ENROLL_CONFIRM, (string) ( $input['confirmation'] ?? '' ) ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_plan_sha256'] ?? '' ) ) )
            return self::failure( 'enroll_confirmation_invalid', 'Exact plan SHA and confirmation required.' );
        $access = self::can_enroll( $input );
        if ( is_wp_error( $access ) || true !== $access ) return $access;
        $plan = self::enroll_plan( array(
            'public_key_base64' => (string) ( $input['public_key_base64'] ?? '' ),
            'reason' => (string) ( $input['reason'] ?? '' ),
        ) );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! hash_equals( $plan['plan_sha256'], (string) $input['expected_plan_sha256'] ) ||
            empty( $plan['eligible'] ) ) return self::failure( 'enroll_plan_changed', 'Site or trust changed; review a new enrollment plan.' );
        $key = (string) $input['public_key_base64'];
        $before = get_option( self::KEY_OPTION, '' );
        if ( '' !== $before && ! hash_equals( (string) $before, $key ) )
            return self::failure( 'enroll_rotation_denied', 'Replacing an attestor requires separate reviewed rotation.' );
        if ( '' === $before ) update_option( self::KEY_OPTION, $key, false );
        if ( ! hash_equals( $key, (string) get_option( self::KEY_OPTION, '' ) ) ) {
            update_option( self::KEY_OPTION, $before, false );
            return self::failure( 'enroll_readback_failed', 'Public key readback mismatch; restored prior state.' );
        }
        $audit = class_exists( 'MAD4B_SCP_Audit' )
            ? MAD4B_SCP_Audit::record( 'mad4b/staging-offline-attestor-enroll',
                array( 'public_key_sha256' => $plan['public_key_sha256'],
                    'site_uuid' => $plan['site_uuid'], 'plan_sha256' => $plan['plan_sha256'],
                    'production_authorized' => false ), 'success' )
            : self::failure( 'audit_missing', 'Audit unavailable.' );
        if ( is_wp_error( $audit ) ) {
            update_option( self::KEY_OPTION, $before, false );
            return self::failure( 'enroll_audit_failed', 'Audit rejected attestor key; original state restored.' );
        }
        return array( 'state' => 'verified', 'public_key_sha256' => $plan['public_key_sha256'],
            'site_uuid' => $plan['site_uuid'], 'readback_verified' => true,
            'production_authorized' => false, 'private_key_stored' => false );
    }

    private static function failure( $code, $message ) {
        return new WP_Error( 'mad4b_offline_' . $code, $message );
    }

    /** Validate without modifying files, options, grants or authorizations. */
    public static function verify( $attestation, array $manifest, array $site, $now = null, $enrolled_key = null ) {
        if ( ! is_array( $attestation ) ||
            ! isset( $attestation['claims_b64'], $attestation['signature_b64'] ) ||
            count( $attestation ) !== 2 )
            return self::failure( 'format_invalid', 'Exact signed claims and signature required.' );
        if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) )
            return self::failure( 'crypto_unavailable', 'Ed25519 verifier is required.' );
        $key = is_string( $enrolled_key ) ? $enrolled_key : get_option( self::KEY_OPTION, '' );
        $key_bytes = is_string( $key ) ? base64_decode( $key, true ) : false;
        if ( ! is_string( $key_bytes ) || strlen( $key_bytes ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES )
            return self::failure( 'trust_key_unenrolled', 'A separate owner-enrolled Staging attestor public key is required.' );
        if ( strlen( (string) $attestation['claims_b64'] ) > 11000 ||
            strlen( (string) $attestation['signature_b64'] ) > 200 )
            return self::failure( 'receipt_oversized', 'Offline attestation exceeded fixed limits.' );
        $raw = base64_decode( (string) $attestation['claims_b64'], true );
        $sig = base64_decode( (string) $attestation['signature_b64'], true );
        if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > self::MAX_CLAIMS_BYTES ||
            ! is_string( $sig ) || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES )
            return self::failure( 'receipt_invalid', 'Offline attestation signature or payload invalid.' );
        if ( ! sodium_crypto_sign_verify_detached( $sig, $raw, $key_bytes ) )
            return self::failure( 'signature_invalid', 'Offline receipt not signed by the enrolled independent authority.' );
        $claims = json_decode( $raw, true );
        if ( ! is_array( $claims ) || array_keys( $claims ) !== array(
            'contract', 'repository', 'source_commit_sha', 'archive_sha256',
            'build_fingerprint', 'package_manifest_digest', 'size_bytes',
            'site_uuid', 'site_origin', 'environment', 'issued_at', 'expires_at',
            'evidence_bundle_sha256', 'test_gates', 'owner_reviewed',
        ) )
            return self::failure( 'schema_invalid', 'Signed receipt fields or ordering invalid.' );
        if ( self::CONTRACT !== $claims['contract'] ||
            'mad4bdigital-ai/WordPress' !== $claims['repository'] ||
            'staging' !== $claims['environment'] || true !== $claims['owner_reviewed'] )
            return self::failure( 'scope_invalid', 'Receipt is not a reviewed Staging-only artifact.' );
        $fields = array( 'source_commit_sha', 'archive_sha256', 'build_fingerprint', 'package_manifest_digest' );
        foreach ( $fields as $field ) {
            if ( ! isset( $manifest[ $field ], $claims[ $field ] ) ||
                ! is_string( $manifest[ $field ] ) || ! is_string( $claims[ $field ] ) ||
                ! hash_equals( $manifest[ $field ], $claims[ $field ] ) )
                return self::failure( 'package_identity_mismatch', 'Offline attestation source or package differs.' );
        }
        if ( ! isset( $manifest['size_bytes'] ) || ! is_int( $claims['size_bytes'] ) ||
            (int) $manifest['size_bytes'] !== $claims['size_bytes'] )
            return self::failure( 'package_size_mismatch', 'Attested archive size differs.' );
        if ( ! isset( $site['configured_environment'], $site['site_uuid'], $site['canonical_origin'] ) ||
            'staging' !== (string) $site['configured_environment'] ||
            ! hash_equals( (string) $site['site_uuid'], (string) $claims['site_uuid'] ) ||
            ! hash_equals( (string) $site['canonical_origin'], (string) $claims['site_origin'] ) ||
            empty( $site['origin_match'] ) || empty( $site['authority_ready'] ) )
            return self::failure( 'site_identity_mismatch', 'Offline receipt is not bound to this enrolled Staging site.' );
        $now = null === $now ? time() : (int) $now;
        if ( ! is_int( $claims['issued_at'] ) || ! is_int( $claims['expires_at'] ) ||
            $claims['issued_at'] > $now + 300 || $claims['expires_at'] <= $now ||
            $claims['expires_at'] <= $claims['issued_at'] ||
            $claims['expires_at'] - $claims['issued_at'] > self::MAX_LIFETIME )
            return self::failure( 'expired', 'Receipt timestamp or maximum validity invalid.' );
        if ( ! isset( $manifest['evidence_bundle_sha256'] ) ||
            ! is_string( $claims['evidence_bundle_sha256'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $claims['evidence_bundle_sha256'] ) ||
            ! hash_equals( (string) $manifest['evidence_bundle_sha256'], $claims['evidence_bundle_sha256'] ) )
            return self::failure( 'test_bundle_mismatch', 'Reviewed native test bundle digest differs.' );
        $gates = $claims['test_gates'];
        if ( ! is_array( $gates ) || count( $gates ) !== count( self::REQUIRED_GATES ) ||
            array_keys( $gates ) !== self::REQUIRED_GATES )
            return self::failure( 'test_inventory_invalid', 'Source-native test inventory incomplete or unexpected.' );
        foreach ( $gates as $value )
            if ( 'PASS' !== $value )
                return self::failure( 'test_failure', 'A mandatory independently attested test did not pass.' );
        return array(
            'verified' => true, 'evidence_mode' => 'owner_signed_ci_outage',
            'source_commit_sha' => $claims['source_commit_sha'],
            'receipt_sha256' => hash( 'sha256', $raw ),
            'expires_at' => $claims['expires_at'], 'production_allowed' => false,
            'ci_result_required' => false, 'read_only' => true,
        );
    }
}
