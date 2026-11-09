<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Transport only: fetch a measured, immutable central Staging readback from
 * a fixed MAD4B origin and verify its detached signature. Not an installer,
 * entitlement grant, or separate plugin/package certificate.
 *
 * The central release readback producer is a trusted host executor: no admin
 * input, MCP argument or site-local mutable WordPress option can assert PASS.
 */
final class MAD4B_SCP_Central_Release_Registry {
    const CONTRACT = 'mad4b.central-release-evidence-envelope.v1';
    const RECEIPT = 'mad4b.central-staging-release-readback.v1';
    const SOURCE = 'mad4b.central-staging-source.v1';
    const MAX_BODY_BYTES = 65536;

    public static function configuration() {
        $p = dirname( __DIR__ ) . '/config/central-staging-source.json';
        if ( ! is_readable( $p ) ) return array();
        $a = json_decode( (string) file_get_contents( $p ), true );
        if ( ! is_array( $a ) || ( $a['contract'] ?? '' ) !== self::SOURCE ||
            ( $a['signature_algorithm'] ?? '' ) !== 'RS256' ||
            ( $a['host_origin_provider'] ?? '' ) !== 'read_only_host_mount' ||
            ( $a['host_origin_contract'] ?? '' ) !== 'mad4b.central-staging-origin.v1' ||
            ! is_string( $a['readback_route'] ?? null ) ||
            ! preg_match( '#^/wp-json/[a-z0-9/-]+/$#D', $a['readback_route'] ) )
            return array();

        // A host-owned read-only binding chooses the same central endpoint
        // for every company. Never hardcode tenant-specific origins in code
        // or read an address supplied by the caller.
        $host_file = defined( 'MAD4B_CENTRAL_STAGING_SOURCE_FILE' )
            ? (string) MAD4B_CENTRAL_STAGING_SOURCE_FILE
            : '/run/mad4b-native-abilities/central-staging-source.json';
        if ( '' === $host_file || ! is_file( $host_file ) || is_link( $host_file ) ||
            ! is_readable( $host_file ) || filesize( $host_file ) > 8192 )
            return array();
        $host = json_decode( (string) file_get_contents( $host_file ), true );
        if ( ! is_array( $host ) ||
            ( $host['contract'] ?? '' ) !== 'mad4b.central-staging-origin.v1' ||
            ! is_string( $host['origin'] ?? null ) ||
            ! preg_match( '#^https://[a-z0-9.-]+$#D', $host['origin'] ) )
            return array();
        $a['origin'] = $host['origin'];
        return $a;
    }

    public static function central_origin() {
        $config = self::configuration();
        return (string) ( $config['origin'] ?? '' );
    }

    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_route' ), 40 );
    }

    public static function register_route() {
        if ( ! function_exists( 'register_rest_route' ) ) return;
        register_rest_route( 'mad4b/v1', '/central-release/(?P<release_sha256>[a-f0-9]{64})', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'serve_signed_readback' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Central VPS executor may produce these signed JSON records in an isolated
     * host-owned directory. This endpoint NEVER creates or signs a receipt.
     */
    public static function serve_signed_readback( $request ) {
        if ( ! function_exists( 'home_url' ) ||
            rtrim( (string) home_url(), '/' ) !== self::central_origin() ||
            ! function_exists( 'wp_get_environment_type' ) ||
            'staging' !== wp_get_environment_type() ||
            ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::origin_enrolled() )
            return new WP_Error( 'mad4b_central_release_not_source_site', 'Not a trusted central Staging issuer.', array( 'status' => 404 ) );

        $hash = is_object( $request ) && method_exists( $request, 'get_param' )
            ? $request->get_param( 'release_sha256' ) : null;
        if ( ! self::hash_ok( $hash ) )
            return new WP_Error( 'mad4b_central_release_hash_invalid', 'Invalid release identity.', array( 'status' => 400 ) );

        // The central VPS provides this protected host-owned folder through
        // an existing read-only container mount, outside WordPress webroot.
        $dir = realpath( '/run/mad4b-native-abilities/central-release-readbacks' );
        $wp = realpath( ABSPATH );
        if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) ||
            ( is_string( $wp ) && ( $dir === $wp || 0 === strpos( $dir, rtrim( $wp, '/' ) . '/' ) ) ) )
            return new WP_Error( 'mad4b_central_release_store_unavailable', 'The host-owned release journal is unavailable.', array( 'status' => 503 ) );

        $file = $dir . '/' . $hash . '.json';
        if ( ! is_file( $file ) || is_link( $file ) || ! is_readable( $file ) ||
            filesize( $file ) < 1 || filesize( $file ) > self::MAX_BODY_BYTES )
            return new WP_Error( 'mad4b_central_release_readback_absent', 'Release readback is not available.', array( 'status' => 404 ) );
        $record = json_decode( (string) file_get_contents( $file ), true );
        $key = self::pinned_public_key();
        $verified = self::verify_envelope( $record, $hash, self::central_origin(), $key );
        if ( is_wp_error( $verified ) ) return new WP_Error(
            'mad4b_central_release_readback_invalid', 'The central readback is not verified.', array( 'status' => 404 ) );
        return $record; // Signed public readback; no private credentials.
    }

    public static function verified_readback( $hash ) {
        if ( ! self::hash_ok( $hash ) ) return array();
        $config = self::configuration();
        if ( ! $config || ! function_exists( 'wp_safe_remote_get' ) ) return array();
        $key = self::pinned_public_key();
        if ( ! $key ) return array(); // No silent trust-on-first-use.
        $url = (string) $config['origin'] . (string) $config['readback_route'] . $hash;
        $response = wp_safe_remote_get( $url, array(
            'timeout' => 8, 'redirection' => 0, 'limit_response_size' => self::MAX_BODY_BYTES,
            'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'MAD4B-Central-Release-Readback' ),
        ) );
        if ( is_wp_error( $response ) ||
            200 !== (int) wp_remote_retrieve_response_code( $response ) ) return array();
        $body = (string) wp_remote_retrieve_body( $response );
        if ( strlen( $body ) < 2 || strlen( $body ) > self::MAX_BODY_BYTES ) return array();
        $record = json_decode( $body, true );
        $receipt = self::verify_envelope( $record, $hash, (string) $config['origin'], $key );
        if ( is_wp_error( $receipt ) ) return array();
        $receipt['registry_readback_verified'] = true; // Set here, never copied from the issuer JSON.
        return $receipt;
    }

    private static function pinned_public_key() {
        // Public trust material is pinned by the deployed plugin source,
        // not a caller-provided field or a mutable WordPress option.
        $file = dirname( __DIR__ ) . '/config/central-release-issuer-public.pem';
        if ( ! is_file( $file ) || is_link( $file ) || ! is_readable( $file ) ||
            filesize( $file ) < 100 || filesize( $file ) > 8192 )
            return '';
        $pem = file_get_contents( $file );
        return is_string( $pem ) && false !== strpos( $pem, '-----BEGIN PUBLIC KEY-----' )
            ? $pem : '';
    }

    public static function verify_envelope( $record, $hash, $central_origin, $public_key ) {
        if ( ! is_array( $record ) || ( $record['contract'] ?? '' ) !== self::CONTRACT ||
            ! is_array( $record['receipt'] ?? null ) ||
            ! is_string( $record['signature_b64'] ?? null ) ||
            ! is_string( $public_key ) || strlen( $public_key ) < 80 ||
            ! self::hash_ok( $hash ) )
            return new WP_Error( 'mad4b_central_evidence_envelope_invalid' );
        $receipt = $record['receipt'];
        if ( ( $receipt['contract'] ?? '' ) !== self::RECEIPT ||
            ( $receipt['staging_origin'] ?? '' ) !== $central_origin ||
            ( $receipt['environment'] ?? '' ) !== 'staging' ||
            ( $receipt['artifact_sha256'] ?? '' ) !== $hash ||
            ! self::hash_ok( $receipt['file_manifest_sha256'] ?? null ) ||
            true !== ( $receipt['staging_pass'] ?? null ) ||
            ! in_array( $receipt['source_type'] ?? '', array( 'local_git', 'remote_git' ), true ) ||
            ! preg_match( '/^[a-f0-9]{40}$/D', (string) ( $receipt['source_commit'] ?? '' ) ) )
            return new WP_Error( 'mad4b_central_evidence_source_mismatch' );
        $tested_at = $receipt['tested_at'] ?? null;
        $checks = $receipt['checks'] ?? null;
        if ( ! is_int( $tested_at ) || $tested_at > time() + 60 ||
            $tested_at < time() - 604800 ||
            ! is_array( $checks ) || true !== ( $checks['install'] ?? null ) ||
            true !== ( $checks['smoke'] ?? null ) ||
            true !== ( $checks['rollback'] ?? null ) ||
            ! is_array( $receipt['requirements'] ?? null ) )
            return new WP_Error( 'mad4b_central_evidence_tests_unverified' );
        $signature = base64_decode( $record['signature_b64'], true );
        if ( false === $signature || strlen( $signature ) < 128 || strlen( $signature ) > 1024 ||
            ! function_exists( 'openssl_verify' ) )
            return new WP_Error( 'mad4b_central_evidence_signature_invalid' );
        if ( ! function_exists( 'openssl_pkey_get_public' ) ||
            ! function_exists( 'openssl_pkey_get_details' ) ||
            false === ( $pub = openssl_pkey_get_public( $public_key ) ) )
            return new WP_Error( 'mad4b_central_evidence_key_untrusted' );
        $details = openssl_pkey_get_details( $pub );
        if ( ! is_array( $details ) || (int) ( $details['bits'] ?? 0 ) < 2048 ||
            (int) ( $details['type'] ?? -1 ) !== OPENSSL_KEYTYPE_RSA )
            return new WP_Error( 'mad4b_central_evidence_key_weak' );
        $canonical = self::canonicalize( $receipt );
        $encoded = json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_BODY_BYTES ||
            1 !== openssl_verify( $encoded, $signature, $public_key, OPENSSL_ALGO_SHA256 ) )
            return new WP_Error( 'mad4b_central_evidence_signature_untrusted' );
        unset( $receipt['registry_readback_verified'] ); // Never import a claimed verifier flag.
        return $receipt;
    }

    private static function hash_ok( $v ) {
        return is_string( $v ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $v );
    }
    private static function canonicalize( $value ) {
        if ( ! is_array( $value ) ) return $value;
        if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) )
            ksort( $value, SORT_STRING );
        foreach ( $value as &$v ) $v = self::canonicalize( $v );
        unset( $v );
        return $value;
    }
}
