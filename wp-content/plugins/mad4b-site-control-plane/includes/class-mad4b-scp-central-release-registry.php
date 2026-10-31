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
            ! is_string( $a['origin'] ?? null ) ||
            ! preg_match( '#^https://[a-z0-9.-]+$#D', $a['origin'] ) ||
            ! is_string( $a['readback_route'] ?? null ) ||
            ! preg_match( '#^/wp-json/[a-z0-9/-]+/$#D', $a['readback_route'] ) )
            return array();
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

        $dir = defined( 'MAD4B_CENTRAL_RELEASE_READBACK_DIR' ) ?
            realpath( (string) MAD4B_CENTRAL_RELEASE_READBACK_DIR ) : false;
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
        if ( ! defined( 'MAD4B_CENTRAL_RELEASE_PUBLIC_KEY_PEM' ) ||
            ! is_string( MAD4B_CENTRAL_RELEASE_PUBLIC_KEY_PEM ) ||
            strlen( MAD4B_CENTRAL_RELEASE_PUBLIC_KEY_PEM ) > 8192 )
            return '';
        return MAD4B_CENTRAL_RELEASE_PUBLIC_KEY_PEM;
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
        $signature = base64_decode( $record['signature_b64'], true );
        if ( false === $signature || strlen( $signature ) < 128 || strlen( $signature ) > 1024 ||
            ! function_exists( 'openssl_verify' ) )
            return new WP_Error( 'mad4b_central_evidence_signature_invalid' );
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
