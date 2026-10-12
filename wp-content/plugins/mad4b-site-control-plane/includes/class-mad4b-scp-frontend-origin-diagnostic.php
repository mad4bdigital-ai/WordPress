<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded, same-origin Staging HTTP probe available through governed MCP Read.
 * This is neither a browser agent nor a source of frontend performance
 * acceptance samples. It deliberately never follows redirects or accepts URLs.
 */
final class MAD4B_SCP_Frontend_Origin_Diagnostic {
    const CONTRACT = 'mad4b.frontend-origin-diagnostic.v1';
    const ABILITY = 'mad4b/frontend-origin-diagnostic';
    const MARKER = 'mad4b.frontend-origin.synthetic.v1';
    const TIMEOUT_SECONDS = 6;

    public static function boot() {
        if ( function_exists( 'add_action' ) )
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Diagnose Staging Frontend Origin HTTP Reachability',
            'description' => 'Plan or run one bounded HTTPS GET to the exact enrolled site homepage from its WordPress host; this is not browser acceptance.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'inspect' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array( 'mode' => array( 'type' => 'string', 'enum' => array( 'plan', 'probe' ), 'default' => 'plan' ) ),
                'additionalProperties' => false,
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    private static function marker( $period ) {
        return hash_hmac( 'sha256', self::MARKER . '|' . (int) $period, wp_salt( 'auth' ) );
    }

    public static function is_signed_synthetic_request() {
        $header = isset( $_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] ) && is_string( $_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] )
            ? (string) $_SERVER['HTTP_X_MAD4B_SYNTHETIC_PROBE'] : '';
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $header ) ) return false;
        $period = (int) floor( time() / 120 );
        return hash_equals( self::marker( $period ), $header ) || hash_equals( self::marker( $period - 1 ), $header );
    }

    private static function transport_error_class( $error ) {
        if ( ! is_wp_error( $error ) || ! method_exists( $error, 'get_error_message' ) )
            return 'unknown_transport_failure';
        $message = strtolower( (string) $error->get_error_message() );
        if ( preg_match( '/curl error 6|could not resolve|name or service not known|dns lookup/i', $message ) )
            return 'dns_resolution_failure';
        if ( preg_match( '/curl error 60|ssl certificate|tls certificate|certificate verify/i', $message ) )
            return 'tls_verification_failure';
        if ( preg_match( '/curl error 28|timed out|timeout/i', $message ) )
            return 'network_timeout';
        if ( preg_match( '/curl error 7|connection refused|could not connect/i', $message ) )
            return 'tcp_connection_failure';
        if ( preg_match( '/not a valid url|blocked external|blocked request|disallowed/i', $message ) )
            return 'local_http_policy_block';
        return 'unknown_transport_failure';
    }

    public static function inspect( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $mode = isset( $input['mode'] ) ? (string) $input['mode'] : 'plan';
        if ( ! in_array( $mode, array( 'plan', 'probe' ), true ) )
            return new WP_Error( 'mad4b_frontend_probe_mode_invalid', 'Only plan and probe modes are allowed.' );
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || true !== MAD4B_SCP_Policy::can_read() )
            return new WP_Error( 'mad4b_frontend_probe_read_denied', 'Governed read permission is required.' );
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::configured() ||
            'staging' !== MAD4B_SCP_Site_Profile::current_environment() ||
            ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
            ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ||
            ! MAD4B_SCP_Site_Profile::acceptance_enabled() )
            return new WP_Error( 'mad4b_frontend_probe_staging_enrollment_required', 'Exact enrolled Staging profile is required.' );
        $profile = MAD4B_SCP_Site_Profile::status();
        $url = function_exists( 'home_url' ) ? home_url( '/' ) : '';
        $canonical = is_array( $profile ) ? (string) ( $profile['canonical_origin'] ?? '' ) : '';
        $a = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : false;
        $b = function_exists( 'wp_parse_url' ) ? wp_parse_url( $canonical ) : false;
        if ( ! is_array( $a ) || ! is_array( $b ) ||
            ( $a['scheme'] ?? '' ) !== 'https' || ( $b['scheme'] ?? '' ) !== 'https' ||
            ! isset( $a['host'], $b['host'] ) ||
            ! hash_equals( strtolower( (string) $b['host'] ), strtolower( (string) $a['host'] ) ) ||
            (int) ( $a['port'] ?? 443 ) !== (int) ( $b['port'] ?? 443 ) ||
            ! in_array( (string) ( $a['path'] ?? '/' ), array( '', '/' ), true ) ||
            ! empty( $a['user'] ) || ! empty( $a['pass'] ) || ! empty( $a['query'] ) || ! empty( $a['fragment'] ) )
            return new WP_Error( 'mad4b_frontend_probe_origin_mismatch', 'Homepage must match the exact enrolled HTTPS origin.' );
        $base = array(
            'contract' => self::CONTRACT, 'mode' => $mode,
            'origin_identity_sha256' => hash( 'sha256', strtolower( $a['host'] ) . ':' . (int) ( $a['port'] ?? 443 ) ),
            'target' => 'exact_enrolled_homepage_only', 'max_requests' => 1,
            'request_timeout_seconds' => self::TIMEOUT_SECONDS,
            'redirects_allowed' => false, 'cookies_sent' => false,
            'javascript_executed' => false, 'external_browser_attestation' => false,
            'frontend_acceptance_certificate_issued' => false,
            'measurement_scope' => 'wordpress_host_http_reachability_only',
            'production_allowed' => false, 'governed_content_mutation' => false,
        );
        if ( 'plan' === $mode ) return $base + array( 'state' => 'READY_FOR_EXPLICIT_PROBE', 'network_request_performed' => false );
        if ( ! function_exists( 'wp_safe_remote_get' ) )
            return $base + array( 'state' => 'WP_HTTP_CLIENT_UNAVAILABLE', 'network_request_performed' => false );
        $start = microtime( true );
        $response = wp_safe_remote_get( $url, array(
            'method' => 'GET', 'timeout' => self::TIMEOUT_SECONDS,
            'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true,
            'blocking' => true, 'limit_response_size' => 4096, 'cookies' => array(),
            'headers' => array(
                'Accept' => 'text/html',
                'X-MAD4B-Synthetic-Probe' => self::marker( floor( time() / 120 ) ),
            ),
        ) );
        $elapsed_ms = round( ( microtime( true ) - $start ) * 1000, 2 );
        if ( is_wp_error( $response ) ) return $base + array(
            'state' => 'HTTP_PROBE_FAILED', 'network_request_performed' => true,
            'elapsed_ms' => $elapsed_ms,
            'error_code' => (string) $response->get_error_code(),
            'transport_error_class' => self::transport_error_class( $response ),
            'next_action' => 'Inspect host DNS, TLS, firewall and WordPress loopback connectivity without weakening TLS validation.',
        );
        $code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
        return $base + array(
            'state' => $code >= 200 && $code < 300 ? 'HTTP_REACHABLE_NOT_BROWSER_VERIFIED' : 'HTTP_RESPONSE_NOT_ACCEPTED',
            'network_request_performed' => true, 'elapsed_ms' => $elapsed_ms, 'http_status' => $code,
            'http_success' => $code >= 200 && $code < 300,
            'next_action' => 'Use separately attested managed browser acceptance to verify JavaScript, page render and public-network reachability.',
        );
    }
}
MAD4B_SCP_Frontend_Origin_Diagnostic::boot();
