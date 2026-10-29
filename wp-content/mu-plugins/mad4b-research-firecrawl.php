<?php
/**
 * Plugin Name: MAD4B Research Provider - Firecrawl Bridge
 * Description: Private, capability-gated research abilities for MAD4B Control Plane, with Woo Lab as the first consumer.
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_Research_Firecrawl_Bridge {
    const STATUS = 'mad4b/research-firecrawl-status';
    const PREVIEW = 'mad4b/research-firecrawl-preview';
    const CONTRACT = 'mad4b.research.firecrawl.preview.v1';
    const TARGET = 'http://gateway:8080';
    const DEFAULT_SECRET = '/run/mad4b-local-vault/firecrawl-control-plane/consumer.credential';
    private static $transport_scope = '';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 45 );
    }

    public static function permitted() {
        return current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy' )
            && MAD4B_SCP_Policy::can_read();
    }

    public static function register() {
        if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'MAD4B_SCP_Policy' ) ) { return; }
        $meta = array(
            'public' => false,
            'show_in_rest' => false,
            'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ),
            'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
        );
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::STATUS ) ) {
            wp_register_ability( self::STATUS, array(
                'label' => 'Private Research Provider Status',
                'description' => 'Read readiness of the private shared Firecrawl research provider without showing credentials.',
                'category' => 'mad4b-admin',
                'execute_callback' => array( __CLASS__, 'status' ),
                'permission_callback' => array( __CLASS__, 'permitted' ),
                'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => $meta,
            ) );
        }
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::PREVIEW ) ) {
            wp_register_ability( self::PREVIEW, array(
                'label' => 'Private Research Scrape Preview',
                'description' => 'Fetch a bounded public HTTPS webpage preview from approved domains using the private Firecrawl service. Never writes WordPress content.',
                'category' => 'mad4b-admin',
                'execute_callback' => array( __CLASS__, 'preview' ),
                'permission_callback' => array( __CLASS__, 'permitted' ),
                'input_schema' => array(
                    'type' => 'object',
                    'required' => array( 'url' ),
                    'properties' => array( 'url' => array( 'type' => 'string', 'minLength' => 12, 'maxLength' => 2048 ) ),
                    'additionalProperties' => false,
                ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => $meta,
            ) );
        }
    }

    private static function error( $code, $status = 503 ) {
        return new WP_Error( 'mad4b_research_' . $code, 'Private research provider operation is unavailable or forbidden.', array( 'status' => $status, 'retryable' => $status === 503, 'mutation_performed' => false ) );
    }

    /** Allow only the request currently being emitted by this verified Ability. */
    public static function transport_permitted( $url, $args ) {
        if ( '' === self::$transport_scope || ! self::permitted() || ! is_array( $args ) ) { return false; }
        $method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
        if ( isset( $args['redirection'] ) && (int) $args['redirection'] !== 0 ) { return false; }
        if ( 'health' === self::$transport_scope ) {
            return 'GET' === $method && self::TARGET . '/__healthcheck' === $url;
        }
        if ( 'scrape' !== self::$transport_scope || 'POST' !== $method || self::TARGET . '/v1/scrape' !== $url ) { return false; }
        $headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
        $type = isset( $headers['Content-Type'] ) ? strtolower( trim( (string) $headers['Content-Type'] ) ) : '';
        $auth = isset( $headers['Authorization'] ) ? (string) $headers['Authorization'] : '';
        if ( 'application/json' !== $type || 0 !== strpos( $auth, 'Basic ' ) ) { return false; }
        $body = isset( $args['body'] ) && is_string( $args['body'] ) && strlen( $args['body'] ) < 4096 ? json_decode( $args['body'], true ) : null;
        return is_array( $body ) && isset( $body['url'] ) && self::target_allowed( $body['url'] )
            && isset( $body['formats'] ) && array( 'markdown' ) === $body['formats'];
    }

    private static function secret_file() {
        $path = defined( 'MAD4B_FIRECRAWL_SECRET_FILE' ) ? constant( 'MAD4B_FIRECRAWL_SECRET_FILE' ) : self::DEFAULT_SECRET;
        if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) {
            return false;
        }
        // Never accept a credential file readable by other users or a group.
        $mode = @fileperms( $path );
        if ( false === $mode || ( $mode & 0077 ) !== 0 ) { return false; }
        return $path;
    }

    private static function credentials() {
        $path = self::secret_file();
        if ( false === $path ) { return false; }
        $raw = @file_get_contents( $path, false, null, 0, 512 );
        if ( ! is_string( $raw ) || ! preg_match( '/\\Ausername=(mad4b-[a-z0-9-]{3,48})\\npassword=([A-Za-z0-9_-]{40,128})\\n\\z/D', $raw, $matches ) ) {
            return false;
        }
        return array( $matches[1], $matches[2] );
    }

    private static function allowed_hosts() {
        $hosts = defined( 'MAD4B_FIRECRAWL_ALLOWED_HOSTS' ) ? constant( 'MAD4B_FIRECRAWL_ALLOWED_HOSTS' ) : array( 'example.com' );
        if ( ! is_array( $hosts ) || count( $hosts ) > 20 ) { return array(); }
        $allowed = array();
        foreach ( $hosts as $host ) {
            if ( is_string( $host ) && preg_match( '/\\A[a-z0-9][a-z0-9.-]{2,250}\\z/D', $host ) && strpos( $host, '..' ) === false ) {
                $allowed[] = strtolower( $host );
            }
        }
        return array_values( array_unique( $allowed ) );
    }

    private static function target_allowed( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 2048 ) { return false; }
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || strtolower( $parts['scheme'] ?? '' ) !== 'https' || ! isset( $parts['host'] ) ) { return false; }
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) ) { return false; }
        $host = strtolower( $parts['host'] );
        return in_array( $host, self::allowed_hosts(), true )
            && filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME )
            && ! filter_var( $host, FILTER_VALIDATE_IP );
    }

    public static function status( $input = array() ) {
        if ( ! self::permitted() ) { return self::error( 'denied', 403 ); }
        $ready = false !== self::secret_file();
        $connected = false;
        if ( $ready ) {
            self::$transport_scope = 'health';
            try {
                $response = wp_remote_get( self::TARGET . '/__healthcheck', array(
                    'timeout' => 4, 'redirection' => 0, 'reject_unsafe_urls' => false,
                ) );
            } finally { self::$transport_scope = ''; }
            $connected = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
        }
        return array(
            'contract' => self::CONTRACT,
            'provider' => 'firecrawl',
            'configured' => $ready,
            'healthy' => $connected,
            'consumer_scope' => 'site_control_plane',
            'allowed_host_count' => count( self::allowed_hosts() ),
            'public_ingress' => false,
            'credential_exposed' => false,
        );
    }

    public static function preview( $input ) {
        if ( ! self::permitted() ) { return self::error( 'denied', 403 ); }
        if ( ! is_array( $input ) || array_keys( $input ) !== array( 'url' ) || ! self::target_allowed( $input['url'] ) ) {
            return self::error( 'host_not_authorized', 403 );
        }
        $credentials = self::credentials();
        if ( false === $credentials ) { return self::error( 'not_configured' ); }
        self::$transport_scope = 'scrape';
        try {
            $response = wp_remote_post( self::TARGET . '/v1/scrape', array(
            'timeout' => 25,
            'redirection' => 0,
            // The outbound destination is a fixed, internal service URL; caller cannot change it.
            'reject_unsafe_urls' => false,
            'limit_response_size' => 262144,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $credentials[0] . ':' . $credentials[1] ),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'url' => $input['url'], 'formats' => array( 'markdown' ), 'onlyMainContent' => true,
            ) ),
        ) );
        } finally { self::$transport_scope = ''; }
        if ( is_wp_error( $response ) ) { return self::error( 'provider_unavailable' ); }
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) { return self::error( 'provider_rejected' ); }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['success'] ) || ! isset( $data['data']['markdown'] ) || ! is_string( $data['data']['markdown'] ) ) {
            return self::error( 'provider_contract_invalid' );
        }
        // Page-supplied metadata is untrusted evidence, never site authority.
        $candidate_meta = isset( $data['data']['metadata'] ) && is_array( $data['data']['metadata'] ) ? $data['data']['metadata'] : array();
        $safe_meta = array();
        foreach ( array( 'title' => 200, 'description' => 400, 'language' => 32, 'ogTitle' => 200, 'ogDescription' => 400, 'robots' => 100 ) as $key => $max ) {
            if ( isset( $candidate_meta[ $key ] ) && is_string( $candidate_meta[ $key ] ) ) {
                $safe_meta[ $key ] = mb_substr( trim( strip_tags( $candidate_meta[ $key ] ) ), 0, $max, 'UTF-8' );
            }
        }
        return array(
            'contract' => self::CONTRACT, 'provider' => 'firecrawl',
            'url' => $input['url'],
            'preview' => mb_substr( $data['data']['markdown'], 0, 6000, 'UTF-8' ),
            'truncated' => mb_strlen( $data['data']['markdown'], 'UTF-8' ) > 6000,
            'metadata' => $safe_meta,
            'metadata_verified' => false,
            'persisted' => false, 'wordpress_mutated' => false,
        );
    }
}
MAD4B_Research_Firecrawl_Bridge::boot();
