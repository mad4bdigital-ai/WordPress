<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Passive timings for an already validated probe; never change cURL options. */
final class MAD4B_SCP_HTTP_Probe_Timing {
    const FIELDS = array( 'dns_complete_ms', 'tcp_complete_ms', 'tls_complete_ms', 'first_byte_ms', 'total_ms' );

    /** Project cumulative offsets from request start; null means not observed. */
    public static function project( $value ) {
        $value = is_array( $value ) ? $value : array();
        $out = array( 'available' => false, 'measurement_scope' => 'first_curl_attempt_offsets' );
        foreach ( self::FIELDS as $key ) {
            $n = $value[ $key ] ?? null;
            $out[ $key ] = ( is_int( $n ) || is_float( $n ) ) && is_finite( (float) $n ) && $n > 0 && $n <= 60000
                ? round( (float) $n, 2 ) : null;
            if ( null !== $out[ $key ] ) $out['available'] = true;
        }
        return $out;
    }

    public static function request( $url, array $args ) {
        $timing = self::project( array() );
        if ( ! function_exists( 'curl_getinfo' ) || ! function_exists( 'add_action' ) || ! function_exists( 'remove_action' ) )
            return array( 'response' => wp_safe_remote_get( $url, $args ), 'network_timing' => $timing );
        $frames = array();
        $before = static function ( $handle, $request, $request_url ) use ( &$frames, $url ) {
            // A nested HTTP request must never supply evidence for the probe.
            $frames[] = array( 'handle' => $handle, 'matches' => is_string( $request_url ) && hash_equals( $url, $request_url ) );
        };
        $after = static function () use ( &$frames, &$timing ) {
            $frame = array_pop( $frames );
            if ( ! is_array( $frame ) || empty( $frame['matches'] ) ) return;
            try {
                // Read only numeric counters; no URLs, IPs, headers or debug traces.
                $raw = array();
                foreach ( array( 'dns_complete_ms' => 'CURLINFO_NAMELOOKUP_TIME', 'tcp_complete_ms' => 'CURLINFO_CONNECT_TIME',
                    'tls_complete_ms' => 'CURLINFO_APPCONNECT_TIME', 'first_byte_ms' => 'CURLINFO_STARTTRANSFER_TIME',
                    'total_ms' => 'CURLINFO_TOTAL_TIME' ) as $field => $constant ) {
                    if ( ! defined( $constant ) ) continue;
                    $n = curl_getinfo( $frame['handle'], constant( $constant ) );
                    if ( is_int( $n ) || is_float( $n ) ) $raw[ $field ] = $n * 1000;
                }
                $timing = MAD4B_SCP_HTTP_Probe_Timing::project( $raw );
            } catch ( Throwable $ignored ) { /* Optional measurement cannot fail the request. */ }
        };
        add_action( 'http_api_curl', $before, PHP_INT_MAX, 3 );
        add_action( 'requests-curl.after_send', $after, PHP_INT_MAX, 0 );
        try { $response = wp_safe_remote_get( $url, $args ); }
        finally {
            remove_action( 'http_api_curl', $before, PHP_INT_MAX );
            remove_action( 'requests-curl.after_send', $after, PHP_INT_MAX );
            $frames = array();
        }
        return array( 'response' => $response, 'network_timing' => $timing );
    }
}
