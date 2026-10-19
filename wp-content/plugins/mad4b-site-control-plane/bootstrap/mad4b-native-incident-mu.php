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
    private static $early_secret = '';

    public static function boot( $staging_enrolled = false ) {
        if ( self::$registered || true !== $staging_enrolled ) return false;
        // wp_salt() is pluggable and is not available while ordinary plugins
        // are being included. Use only already configured strong secrets;
        // never load pluggable.php early or create a public/default HMAC key.
        if ( defined( 'AUTH_KEY' ) && defined( 'AUTH_SALT' )
            && is_string( AUTH_KEY ) && is_string( AUTH_SALT )
            && strlen( AUTH_KEY ) >= 32 && strlen( AUTH_SALT ) >= 32
            && false === stripos( AUTH_KEY, 'put your unique phrase here' )
            && false === stripos( AUTH_SALT, 'put your unique phrase here' ) ) {
            self::$early_secret = hash_hmac( 'sha256', self::CONTRACT . '|' . AUTH_SALT, AUTH_KEY, true );
        }
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
        // The plugin/theme slug is safe operational context; do not reveal the
        // absolute path or filename. It is a *suspected* origin, not root cause.
        $slug = '';
        $root = 'plugin' === $family ? '/wp-content/plugins/' : ( 'theme' === $family ? '/wp-content/themes/' : '' );
        if ( '' !== $root ) {
            $suffix = substr( $file, strpos( $file, $root ) + strlen( $root ) );
            $first = strtok( $suffix, '/' );
            if ( is_string( $first ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $first ) )
                $slug = strtolower( $first );
        }
        // Source hint only for our own publicly versioned plugin files.
        // Never persist a host filesystem prefix, other plugin/theme filename,
        // arbitrary suffix, traversal segment or raw PHP error message.
        $first_party_file = '';
        $first_party_line = 0;
        if ( 'plugin' === $family && 'mad4b-site-control-plane' === $slug ) {
            $prefix = '/wp-content/plugins/mad4b-site-control-plane/';
            $at = strpos( $file, $prefix );
            if ( false !== $at ) {
                $suffix = substr( $file, $at + strlen( $prefix ) );
                if ( strlen( $suffix ) <= 180
                    && 1 === preg_match( '/^(?:[A-Za-z0-9_-]+\\/)*[A-Za-z0-9._-]+\\.php$/D', $suffix ) ) {
                    $first_party_file = $suffix;
                    $line = isset( $error['line'] ) ? (int) $error['line'] : 0;
                    $first_party_line = $line >= 1 && $line <= 2000000 ? $line : 0;
                }
            }
        }
        $message = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '';
        $secret = '' !== self::$early_secret ? self::$early_secret
            : ( function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '' );
        if ( '' === $secret ) return array(); // Never persist an unsalted guess about sensitive source text.
        $request = ( defined( 'DOING_CRON' ) && DOING_CRON ) ? 'cron'
            : ( defined( 'REST_REQUEST' ) && REST_REQUEST ? 'rest'
            : ( function_exists( 'is_admin' ) && is_admin() ? 'admin' : 'frontend' ) );
        return array(
            'type' => $type,
            'component_family' => $family,
            'suspected_component_slug' => $slug,
            'first_party_file' => $first_party_file,
            'first_party_line' => $first_party_line,
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
        try {
            $event = self::classify( $error );
            if ( ! $event ) return false;
            $now = time();
            $stored = get_option( self::OPTION, array() );
            $history = is_array( $stored ) && isset( $stored['events'] ) && is_array( $stored['events'] ) ? $stored['events'] : array();
            $history = array_values( array_filter( $history, static function ( $row ) use ( $now ) {
                return is_array( $row ) && isset( $row['last_at'] )
                    && (int) $row['last_at'] <= $now && (int) $row['last_at'] >= $now - 7 * DAY_IN_SECONDS;
            } ) );
            // Throttle each distinct fingerprint, including alternating faults.
            foreach ( $history as $previous ) {
                if ( isset( $previous['incident_fingerprint'] )
                    && hash_equals( (string) $previous['incident_fingerprint'], $event['incident_fingerprint'] )
                    && $now - (int) ( $previous['last_at'] ?? 0 ) < 60 ) return false;
            }
            $event['last_at'] = $now;
            $history[] = $event;
            $history = array_slice( $history, -self::MAX_EVENTS );
            // WordPress option storage is available only after core DB bootstrap.
            // A hard outage before that point is explicitly NOT captured.
            $next = array( 'contract' => self::CONTRACT, 'events' => $history );
            if ( ! update_option( self::OPTION, $next, false ) ) return false;
            return get_option( self::OPTION, array() ) === $next;
        } catch ( Throwable $ignored ) {
            return false; // Incident handling must never crash the request again.
        }
    }

    public static function status() {
        $stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
        $events = is_array( $stored ) && isset( $stored['events'] ) && is_array( $stored['events'] ) ? $stored['events'] : array();
        $now = time();
        $events = array_slice( array_values( array_filter( $events, static function ( $row ) use ( $now ) {
            return is_array( $row ) && isset( $row['last_at'] )
                && (int) $row['last_at'] <= $now && (int) $row['last_at'] >= $now - 7 * DAY_IN_SECONDS;
        } ) ), -self::MAX_EVENTS );
        // Only select metadata fields; no untrusted stored text echoed to UI/MCP.
        $safe = array();
        foreach ( $events as $row ) {
            if ( ! is_array( $row ) ) continue;
            $safe[] = array(
                'type' => (int) ( $row['type'] ?? 0 ),
                'component_family' => in_array( (string) ( $row['component_family'] ?? '' ), array( 'mu_plugin', 'plugin', 'theme', 'wordpress_core', 'unknown' ), true ) ? $row['component_family'] : 'unknown',
                'request_family' => in_array( (string) ( $row['request_family'] ?? '' ), array( 'cron', 'rest', 'admin', 'frontend' ), true ) ? $row['request_family'] : 'unknown',
                'suspected_component_slug' => isset( $row['suspected_component_slug'] ) && is_string( $row['suspected_component_slug'] )
                    && 1 === preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', $row['suspected_component_slug'] ) ? $row['suspected_component_slug'] : '',
                // Stored data is untrusted: validate again before projection.
                'first_party_file' => ( isset( $row['suspected_component_slug'], $row['component_family'], $row['first_party_file'] )
                    && 'mad4b-site-control-plane' === $row['suspected_component_slug']
                    && 'plugin' === $row['component_family']
                    && is_string( $row['first_party_file'] )
                    && strlen( $row['first_party_file'] ) <= 180
                    && 1 === preg_match( '/^(?:[A-Za-z0-9_-]+\\/)*[A-Za-z0-9._-]+\\.php$/D', $row['first_party_file'] ) )
                    ? $row['first_party_file'] : '',
                'first_party_line' => ( isset( $row['suspected_component_slug'], $row['component_family'], $row['first_party_file'], $row['first_party_line'] )
                    && 'mad4b-site-control-plane' === $row['suspected_component_slug']
                    && 'plugin' === $row['component_family']
                    && is_string( $row['first_party_file'] )
                    && strlen( $row['first_party_file'] ) <= 180
                    && 1 === preg_match( '/^(?:[A-Za-z0-9_-]+\\/)*[A-Za-z0-9._-]+\\.php$/D', $row['first_party_file'] )
                    && (int) $row['first_party_line'] >= 1 && (int) $row['first_party_line'] <= 2000000 )
                    ? (int) $row['first_party_line'] : 0,
                'last_at' => max( 0, (int) ( $row['last_at'] ?? 0 ) ),
                'incident_fingerprint' => isset( $row['incident_fingerprint'] ) && preg_match( '/^[a-f0-9]{64}$/D', (string) $row['incident_fingerprint'] ) ? $row['incident_fingerprint'] : '',
            );
        }
        return array( 'contract' => self::CONTRACT, 'recorder_loaded' => self::$enabled,
            'pre_pluggable_capture_available' => self::$enabled && '' !== self::$early_secret,
            'state' => ! self::$enabled ? 'RECORDER_NOT_ACTIVE' : ( $safe ? 'CAPTURED' : 'NO_CAPTURED_INCIDENTS' ),
            'events' => $safe, 'count' => count( $safe ), 'read_only' => true );
    }
}
