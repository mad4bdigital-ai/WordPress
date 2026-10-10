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
            'test_gates', 'owner_reviewed',
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
