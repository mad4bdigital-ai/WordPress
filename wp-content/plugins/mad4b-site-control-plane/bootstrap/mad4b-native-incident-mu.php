<?php
/**
 * Early, best-effort, staging-only PHP fatal incident metadata recorder.
 * Loaded by the *already managed* MAD4B MCP MU bootstrap after exact site
 * enrollment checks. It must not start WordPress, expose raw PHP errors or
 * change Recovery Mode, error display, file permissions, or write authority.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_Native_Incident_MU {
    const OPTION = 'mad4b_scp_native_incidents_v1';
    const CONTRACT = 'mad4b.native-incident-recorder.v1';
    const MAX_EVENTS = 12;
    private static $enabled = false;
    private static $registered = false;

    public static function boot( $staging_enrolled = false ) {
        if ( self::$registered || true !== $staging_enrolled ) return false;
        self::$enabled = true;
        self::$registered = true;
        register_shutdown_function( array( __CLASS__, 'shutdown' ) );
        return true;
    }

    /** No raw message, stack trace, IP, request URL or filesystem path leaves this method. */
    public static function classify( $error ) {
        if ( ! is_array( $error ) ) return array();
        $type = isset( $error['type'] ) ? (int) $error['type'] : 0;
        if ( ! in_array( $type, array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) return array();
        $file = isset( $error['file'] ) && is_string( $error['file'] ) ? str_replace( '\\', '/', $error['file'] ) : '';
        $family = 'unknown';
        if ( false !== strpos( $file, '/wp-content/mu-plugins/' ) ) $family = 'mu_plugin';
        elseif ( false !== strpos( $file, '/wp-content/plugins/' ) ) $family = 'plugin';
        elseif ( false !== strpos( $file, '/wp-content/themes/' ) ) $family = 'theme';
        elseif ( false !== strpos( $file, '/wp-includes/' ) || false !== strpos( $file, '/wp-admin/' ) ) $family = 'wordpress_core';
        $message = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '';
        $secret = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
        if ( '' === $secret ) return array(); // Never persist an unsalted guess about sensitive source text.
        $request = ( defined( 'DOING_CRON' ) && DOING_CRON ) ? 'cron'
            : ( defined( 'REST_REQUEST' ) && REST_REQUEST ? 'rest'
            : ( function_exists( 'is_admin' ) && is_admin() ? 'admin' : 'frontend' ) );
        return array(
            'type' => $type,
            'component_family' => $family,
            'component_fingerprint' => hash_hmac( 'sha256', $file, $secret ),
            'incident_fingerprint' => hash_hmac( 'sha256', $type . '|' . $file . '|' . $message, $secret ),
            'request_family' => $request,
        );
    }

    public static function shutdown() {
        if ( ! self::$enabled || ! function_exists( 'error_get_last' ) ) return;
        self::record( error_get_last() );
    }

    /** Metadata-only best effort; never replace WordPress Recovery Mode. */
    public static function record( $error ) {
        if ( ! self::$enabled || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) return false;
        $event = self::classify( $error );
        if ( ! $event ) return false;
        try {
            $now = time();
            $stored = get_option( self::OPTION, array() );
            $history = is_array( $stored ) && isset( $stored['events'] ) && is_array( $stored['events'] ) ? $stored['events'] : array();
            $history = array_values( array_filter( $history, static function ( $row ) use ( $now ) {
                return is_array( $row ) && isset( $row['last_at'] )
                    && (int) $row['last_at'] <= $now && (int) $row['last_at'] >= $now - 7 * DAY_IN_SECONDS;
            } ) );
            $last = count( $history ) - 1;
            if ( $last >= 0 && isset( $history[ $last ]['incident_fingerprint'] )
                && hash_equals( (string) $history[ $last ]['incident_fingerprint'], $event['incident_fingerprint'] )
                && $now - (int) ( $history[ $last ]['last_at'] ?? 0 ) < 60 ) return false;
            $event['last_at'] = $now;
            $history[] = $event;
            $history = array_slice( $history, -self::MAX_EVENTS );
            // WordPress option storage is available only after core DB bootstrap.
            // A hard outage before that point is explicitly NOT captured.
            update_option( self::OPTION, array( 'contract' => self::CONTRACT, 'events' => $history ), false );
            return true;
        } catch ( Throwable $ignored ) {
            return false; // Incident handling must never crash the request again.
        }
    }

    public static function status() {
        $stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
        $events = is_array( $stored ) && isset( $stored['events'] ) && is_array( $stored['events'] ) ? $stored['events'] : array();
        $events = array_slice( $events, -self::MAX_EVENTS );
        // Only select metadata fields; no untrusted stored text echoed to UI/MCP.
        $safe = array();
        foreach ( $events as $row ) {
            if ( ! is_array( $row ) ) continue;
            $safe[] = array(
                'type' => (int) ( $row['type'] ?? 0 ),
                'component_family' => in_array( (string) ( $row['component_family'] ?? '' ), array( 'mu_plugin', 'plugin', 'theme', 'wordpress_core', 'unknown' ), true ) ? $row['component_family'] : 'unknown',
                'request_family' => in_array( (string) ( $row['request_family'] ?? '' ), array( 'cron', 'rest', 'admin', 'frontend' ), true ) ? $row['request_family'] : 'unknown',
                'last_at' => max( 0, (int) ( $row['last_at'] ?? 0 ) ),
                'incident_fingerprint' => isset( $row['incident_fingerprint'] ) && preg_match( '/^[a-f0-9]{64}$/D', (string) $row['incident_fingerprint'] ) ? $row['incident_fingerprint'] : '',
            );
        }
        return array( 'contract' => self::CONTRACT, 'recorder_loaded' => self::$enabled,
            'state' => ! self::$enabled ? 'RECORDER_NOT_ACTIVE' : ( $safe ? 'CAPTURED' : 'NO_CAPTURED_INCIDENTS' ),
            'events' => $safe, 'count' => count( $safe ), 'read_only' => true );
    }
}
