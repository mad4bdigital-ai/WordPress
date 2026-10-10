<?php
/**
 * Per-provider offline CI-outage evidence verifier.
 *
 * Read-only: a source-approved provider contract supplies the public key
 * and package identity. Never trust a key, release URL, plugin file or
 * executable callback provided by the assistant. Verifying evidence does not
 * enroll a plugin or grant permission to install it.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Plugin_Update_Evidence {
    const CONTRACT = 'mad4b.plugin-update-native-evidence.v1';
    const VERIFY_ABILITY = 'mad4b/plugin-update-evidence-verify';
    const MAX_LIFETIME = 86400;
    const REQUIRED_GATES = array( 'source_exact', 'php_syntax', 'package_integrity', 'plugin_runtime', 'rollback_readiness' );

    private static function denial( $code ) {
        return new WP_Error( 'mad4b_plugin_evidence_' . $code, 'Source-owned Staging plugin update evidence rejected: ' . $code );
    }

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 37 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::VERIFY_ABILITY ) ) ) return;
        wp_register_ability( self::VERIFY_ABILITY, array(
            'label' => 'Verify Source-Approved Plugin Offline Tests',
            'description' => 'Read-only Ed25519 proof bound to one certified plugin provider, package and Staging origin.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'verify_input' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'additionalProperties' => false,
                'required' => array( 'provider_id', 'attestation' ),
                'properties' => array(
                    'provider_id' => array( 'type' => 'string', 'pattern' => '^[a-z0-9_-]{1,80}$' ),
                    'component' => array( 'type' => 'string', 'maxLength' => 80 ),
                    'attestation' => array( 'type' => 'object', 'additionalProperties' => false,
                        'required' => array( 'claims_b64', 'signature_b64' ),
                        'properties' => array(
                            'claims_b64' => array( 'type' => 'string', 'maxLength' => 11000 ),
                            'signature_b64' => array( 'type' => 'string', 'maxLength' => 200 ),
                        ) ),
                ) ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    public static function verify_input( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'provider_id', 'component', 'attestation' ) ) ) return self::denial( 'input_invalid' );
        $id = (string) ( $input['provider_id'] ?? '' );
        $component = (string) ( $input['component'] ?? '' );
        if ( ! preg_match( '/^[a-z0-9_-]{1,80}$/D', $id ) ||
            ( '' !== $component && ! preg_match( '/^[a-z0-9_-]{1,80}$/D', $component ) ) )
            return self::denial( 'provider_invalid' );
        if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ||
            ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return self::denial( 'runtime_unavailable' );
        $contract = MAD4B_SCP_Provider_Contracts::get( $id );
        if ( ! is_array( $contract ) || empty( $contract ) ) return self::denial( 'provider_uncertified' );
        $authority = '' === $component ? $contract : ( $contract['components'][ $component ] ?? array() );
        if ( ! is_array( $authority ) || empty( $authority ) ) return self::denial( 'component_uncertified' );
        return self::verify( $input['attestation'] ?? null, $authority,
            MAD4B_SCP_Site_Profile::status(), $id, $component );
    }

    public static function verify( $signed, array $authority, array $site, $provider_id, $component = '', $now = null ) {
        if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) return self::denial( 'sodium_unavailable' );
        if ( ! is_array( $signed ) || count( $signed ) !== 2 ||
            ! isset( $signed['claims_b64'], $signed['signature_b64'] ) ||
            ! is_string( $signed['claims_b64'] ) || ! is_string( $signed['signature_b64'] ) ||
            strlen( $signed['claims_b64'] ) > 11000 || strlen( $signed['signature_b64'] ) > 200 )
            return self::denial( 'receipt_format' );
        $key_b64 = (string) ( $authority['offline_update_attestor_public_key'] ?? '' );
        $key = base64_decode( $key_b64, true );
        if ( ! is_string( $key ) || strlen( $key ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
            base64_encode( $key ) !== $key_b64 )
            return self::denial( 'unregistered_provider_signer' );
        $raw = base64_decode( $signed['claims_b64'], true );
        $sig = base64_decode( $signed['signature_b64'], true );
        if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 8192 ||
            ! is_string( $sig ) || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES )
            return self::denial( 'receipt_invalid' );
        if ( ! sodium_crypto_sign_verify_detached( $sig, $raw, $key ) ) return self::denial( 'signature_invalid' );
        $claims = json_decode( $raw, true );
        if ( ! is_array( $claims ) || array_keys( $claims ) !== array(
            'contract', 'provider_id', 'component', 'plugin_file', 'version',
            'archive_sha256', 'source_commit_sha', 'site_uuid', 'site_origin',
            'environment', 'issued_at', 'expires_at', 'evidence_bundle_sha256',
            'test_gates', 'owner_reviewed'
        ) ) return self::denial( 'claims_schema_invalid' );
        if ( self::CONTRACT !== $claims['contract'] ||
            'staging' !== $claims['environment'] || true !== $claims['owner_reviewed'] ||
            ! hash_equals( (string) $provider_id, (string) $claims['provider_id'] ) ||
            ! hash_equals( (string) $component, (string) $claims['component'] ) )
            return self::denial( 'claims_scope_invalid' );
        foreach ( array( 'plugin_file', 'version', 'archive_sha256' ) as $field ) {
            if ( ! is_string( $claims[ $field ] ) ||
                ! hash_equals( (string) ( $authority[ $field ] ?? '' ), $claims[ $field ] ) )
                return self::denial( 'certified_package_mismatch' );
        }
        if ( ! preg_match( '/^[a-f0-9]{40}$/D', (string) $claims['source_commit_sha'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) $claims['archive_sha256'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) $claims['evidence_bundle_sha256'] ) )
            return self::denial( 'digest_invalid' );
        if ( 'staging' !== (string) ( $site['configured_environment'] ?? '' ) ||
            empty( $site['authority_ready'] ) || empty( $site['origin_match'] ) ||
            empty( $site['site_uuid'] ) || empty( $site['canonical_origin'] ) ||
            ! hash_equals( (string) $site['site_uuid'], (string) $claims['site_uuid'] ) ||
            ! hash_equals( (string) $site['canonical_origin'], (string) $claims['site_origin'] ) )
            return self::denial( 'site_identity_mismatch' );
        $now = null === $now ? time() : (int) $now;
        if ( ! is_int( $claims['issued_at'] ) || ! is_int( $claims['expires_at'] ) ||
            $claims['issued_at'] > $now + 300 || $claims['expires_at'] <= $now ||
            $claims['expires_at'] <= $claims['issued_at'] ||
            $claims['expires_at'] - $claims['issued_at'] > self::MAX_LIFETIME )
            return self::denial( 'time_invalid' );
        $gates = $claims['test_gates'];
        if ( ! is_array( $gates ) || array_keys( $gates ) !== self::REQUIRED_GATES )
            return self::denial( 'test_inventory_invalid' );
        foreach ( $gates as $status )
            if ( 'PASS' !== $status ) return self::denial( 'native_test_failed' );
        return array(
            'contract' => self::CONTRACT . '.verification.v1',
            'verified' => true, 'evidence_mode' => 'provider_signed_native_ci_outage',
            'provider_id' => $provider_id, 'component' => $component,
            'plugin_file' => $claims['plugin_file'], 'archive_sha256' => $claims['archive_sha256'],
            'source_commit_sha' => $claims['source_commit_sha'],
            'evidence_bundle_sha256' => $claims['evidence_bundle_sha256'],
            'receipt_sha256' => hash( 'sha256', $raw ), 'expires_at' => $claims['expires_at'],
            'ci_terminal_result_required' => false, 'production_allowed' => false,
            'authorizing' => false, 'read_only' => true, 'mutation_performed' => false,
        );
    }
}
