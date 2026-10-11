<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * CSO opt-in and immutable, non-authorizing site/actor binding.
 * No request parameter, option, conversation, OAuth client or other plugin
 * may activate components. A deploy-time configuration constant must opt in.
 */
final class MAD4B_SCP_CSO_Scope {
    const CONTRACT = 'mad4b.cso.operational-scope.v1';
    const FLAGS = array( 'discovery','forms','secrets','single_write','bulk','workflow','multisite','operations','production_proposal' );

    public static function boot() { /* No database migration or implicit feature activation. */ }

    public static function enabled( $component ) {
        if ( ! is_string( $component ) || ! in_array( $component, self::FLAGS, true ) ) return false;
        if ( ! defined( 'MAD4B_CSO_ENABLED_COMPONENTS' ) || ! is_array( MAD4B_CSO_ENABLED_COMPONENTS ) )
            return false;
        if ( array_diff( array_keys( MAD4B_CSO_ENABLED_COMPONENTS ), self::FLAGS ) ) return false;
        return true === ( MAD4B_CSO_ENABLED_COMPONENTS[ $component ] ?? false );
    }

    public static function error( $reason ) {
        $reason = is_string( $reason ) && preg_match( '/^[A-Z0-9_]{1,72}$/D', $reason ) ? $reason : 'DENIED';
        return new WP_Error( 'mad4b_cso_' . strtolower( $reason ),
            'The requested site operation is not available under the current governed scope.',
            array( 'reason' => $reason, 'authorizing' => false,
                'plaintext_in_chat' => false, 'mutation_performed' => false ) );
    }

    public static function bounded( $input ) {
        if ( ! is_array( $input ) || count( $input ) > 128 ) return false;
        $raw = wp_json_encode( $input );
        return is_string( $raw ) && strlen( $raw ) <= 131072;
    }

    public static function safe_data( $input, $depth = 0 ) {
        if ( $depth > 8 ) return false;
        if ( is_array( $input ) ) {
            if ( count( $input ) > 128 ) return false;
            foreach ( $input as $key => $value ) {
                if ( is_string( $key ) &&
                    preg_match( '/(?:api[_-]?key|secret|password|private[_-]?key|access[_-]?token|authorization|credential)/i', $key ) )
                    return false;
                if ( ! self::safe_data( $value, $depth + 1 ) ) return false;
            }
            return true;
        }
        if ( is_string( $input ) ) {
            return strlen( $input ) <= 8192 &&
                ! preg_match( '/-----BEGIN [A-Z ]*PRIVATE KEY-----|Bearer\\s+[A-Za-z0-9._~-]{12,}|sk-[A-Za-z0-9_-]{12,}/i', $input );
        }
        return is_null( $input ) || is_bool( $input ) || is_int( $input ) ||
            ( is_float( $input ) && is_finite( $input ) );
    }

    public static function current() {
        if ( ! self::enabled( 'discovery' ) ||
            ! class_exists( 'MAD4B_SCP_Operational_Integrity', false ) ||
            ! class_exists( 'MAD4B_SCP_Policy', false ) ||
            ! class_exists( 'MAD4B_SCP_Site_Profile', false ) ||
            ! function_exists( 'get_current_user_id' ) ||
            ! function_exists( 'get_current_blog_id' ) ||
            ! function_exists( 'get_current_network_id' ) ||
            ! function_exists( 'current_user_can' ) ||
            ! current_user_can( 'manage_options' ) ||
            ! MAD4B_SCP_Policy::can_read() ||
            ! MAD4B_SCP_Policy::can_connect_user( get_current_user_id() ) )
            return self::error( 'TRUSTED_SCOPE_UNAVAILABLE' );
        $checkpoint = MAD4B_SCP_Operational_Integrity::capture();
        if ( is_wp_error( $checkpoint ) || ! is_array( $checkpoint ) )
            return self::error( 'OPERATIONAL_INTEGRITY_DENIED' );
        $base = $checkpoint['scope'] ?? array();
        if ( ! is_array( $base ) || ( $base['deployment_mode'] ?? '' ) !== 'wordpress_dedicated' ||
            ! in_array( $base['environment'] ?? '', array( 'staging','development','local' ), true ) ||
            (int) ( $base['blog_id'] ?? 0 ) !== get_current_blog_id() ||
            (int) ( $base['network_id'] ?? 0 ) !== get_current_network_id() )
            return self::error( 'SITE_ENVIRONMENT_OR_BLOG_UNVERIFIED' );
        $origin = method_exists( 'MAD4B_SCP_Site_Profile', 'current_origin' )
            ? MAD4B_SCP_Site_Profile::current_origin() : '';
        if ( ! is_string( $origin ) ) return self::error( 'ORIGIN_UNVERIFIED' );
        $parts = wp_parse_url( $origin );
        if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' ||
            empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ||
            isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
            ( isset( $parts['path'] ) && ! in_array( $parts['path'], array('', '/'), true ) ) )
            return self::error( 'SECURE_ORIGIN_REQUIRED' );
        $canonical = 'https://' . strtolower( $parts['host'] ) .
            ( isset( $parts['port'] ) && (int) $parts['port'] !== 443 ? ':' . (int) $parts['port'] : '' );
        $fingerprint = (string) ( $checkpoint['fingerprint'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) )
            return self::error( 'SCOPE_FINGERPRINT_INVALID' );
        $user = (int) get_current_user_id();
        $scope = $base;
        $scope['origin'] = $canonical;
        $scope['actor_sha256'] = hash( 'sha256', $fingerprint . '|' . $user );
        $scope['binding_sha256'] = $fingerprint;
        $scope['profile_sha256'] = hash( 'sha256', wp_json_encode( $checkpoint['dependency_revision'] ?? array() ) );
        $scope['runtime_generation'] = defined( 'MAD4B_CSO_RUNTIME_GENERATION' ) &&
            is_string( MAD4B_CSO_RUNTIME_GENERATION ) ? MAD4B_CSO_RUNTIME_GENERATION : '';
        $scope['source_sha'] = defined( 'MAD4B_CSO_SOURCE_SHA' ) &&
            is_string( MAD4B_CSO_SOURCE_SHA ) ? MAD4B_CSO_SOURCE_SHA : '';
        $scope['package_sha256'] = defined( 'MAD4B_CSO_PACKAGE_SHA256' ) &&
            is_string( MAD4B_CSO_PACKAGE_SHA256 ) ? MAD4B_CSO_PACKAGE_SHA256 : '';
        $scope['restore_epoch'] = defined( 'MAD4B_CSO_RESTORE_EPOCH' ) ?
            (int) MAD4B_CSO_RESTORE_EPOCH : 0;
        $scope['locale'] = function_exists( 'get_user_locale' ) ? get_user_locale( $user ) : 'en_US';
        return $scope;
    }

    public static function assert_current( $expected ) {
        if ( ! is_array( $expected ) ) return self::error( 'SCOPE_ASSERTION_INVALID' );
        $current = self::current();
        if ( is_wp_error( $current ) ) return $current;
        return self::digest( $current ) === self::digest( $expected )
            ? true : self::error( 'SCOPE_CHANGED' );
    }

    public static function digest( $value ) {
        $json = wp_json_encode( $value );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }

    private static function signing_key() {
        if ( ! function_exists( 'wp_salt' ) ) return '';
        $salt = wp_salt( 'auth' );
        return is_string( $salt ) && strlen( $salt ) >= 32
            ? hash_hmac( 'sha256', self::CONTRACT, $salt, true ) : '';
    }

    public static function seal( array $material, $purpose ) {
        $key = self::signing_key();
        if ( '' === $key || ! is_string( $purpose ) || strlen( $purpose ) > 100 ) return self::error( 'SIGNING_KEY_UNAVAILABLE' );
        $json = wp_json_encode( $material );
        if ( ! is_string( $json ) || strlen( $json ) > 32768 ) return self::error( 'SEAL_BOUNDS' );
        return array( 'material' => $material,
            'proof' => hash_hmac( 'sha256', $purpose . ':' . $json, $key ) );
    }

    public static function unseal( array $sealed, $purpose ) {
        if ( array_keys( $sealed ) !== array( 'material','proof' ) ||
            ! is_array( $sealed['material'] ) || ! is_string( $sealed['proof'] ) )
            return self::error( 'SEALED_RECORD_INVALID' );
        $fresh = self::seal( $sealed['material'], $purpose );
        if ( is_wp_error( $fresh ) ) return $fresh;
        return hash_equals( $fresh['proof'], $sealed['proof'] )
            ? $sealed['material'] : self::error( 'SEALED_RECORD_TAMPERED' );
    }

    public static function first_party_session() {
        return function_exists( 'is_user_logged_in' ) && is_user_logged_in() &&
            function_exists( 'wp_get_session_token' ) &&
            is_string( wp_get_session_token() ) && strlen( wp_get_session_token() ) >= 32 &&
            function_exists( 'is_ssl' ) && is_ssl() &&
            current_user_can( 'manage_options' );
    }
}
