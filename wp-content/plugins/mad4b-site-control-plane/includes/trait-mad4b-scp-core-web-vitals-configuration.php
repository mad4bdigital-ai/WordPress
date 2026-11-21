<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Exact source-extracted CWV configuration, encrypted key and URL validation; no behavior changes. */
trait MAD4B_SCP_Core_Web_Vitals_Configuration_Trait {
    private static function settings() {
        $raw = get_option( self::SETTINGS_OPTION, array() );
        return is_array( $raw ) ? $raw : array();
    }

    private static function encryption_key() {
        if ( ! function_exists( 'sodium_crypto_secretbox' ) || ! function_exists( 'wp_salt' ) ) return false;
        return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|mad4b:cwv:api-key:v1', true );
    }

    private static function encrypt_api_key( $value ) {
        $key = self::encryption_key();
        if ( false === $key ) return new WP_Error( 'cwv_crypto_unavailable', 'Secret encryption is unavailable on this server.' );
        try {
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            return base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, $key ) );
        } catch ( Throwable $e ) {
            return new WP_Error( 'cwv_crypto_failed', 'Unable to encrypt the API key.' );
        }
    }

    private static function decrypt_api_key( $ciphertext ) {
        if ( ! is_string( $ciphertext ) || '' === $ciphertext ) return '';
        $key = self::encryption_key();
        if ( false === $key ) return '';
        $raw = base64_decode( $ciphertext, true );
        if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) return '';
        $nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $text = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
        return false === $text ? '' : $text;
    }

    private static function server_api_key() {
        foreach ( array( 'MAD4B_CWV_API_KEY', 'MAD4B_PAGESPEED_API_KEY' ) as $constant ) {
            if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== trim( constant( $constant ) ) ) {
                return trim( constant( $constant ) );
            }
        }
        foreach ( array( 'MAD4B_CWV_API_KEY', 'MAD4B_PAGESPEED_API_KEY' ) as $environment_key ) {
            $value = getenv( $environment_key );
            if ( is_string( $value ) && '' !== trim( $value ) ) return trim( $value );
        }
        return '';
    }

    private static function api_key() {
        $server_key = self::server_api_key();
        if ( '' !== $server_key ) return $server_key;
        $settings = self::settings();
        return self::decrypt_api_key( $settings['encrypted_api_key'] ?? '' );
    }

    public static function validate_monitored_url( $input ) {
        if ( ! is_string( $input ) || strlen( $input ) > 2048 ) {
            return new WP_Error( 'cwv_url_invalid', 'Enter a valid website URL (maximum 2048 characters).' );
        }
        $home = home_url( '/' );
        $candidate = trim( $input );
        if ( '' === $candidate ) $candidate = $home;
        if ( 0 === strpos( $candidate, '/' ) && 0 !== strpos( $candidate, '//' ) ) {
            $candidate = home_url( $candidate );
        }
        $base = wp_parse_url( $home );
        $parts = wp_parse_url( $candidate );
        if ( ! is_array( $parts ) || ! is_array( $base ) ||
            ! isset( $parts['scheme'], $parts['host'], $base['host'] ) ||
            'https' !== strtolower( (string) $parts['scheme'] ) ||
            strtolower( (string) $parts['host'] ) !== strtolower( (string) $base['host'] ) ||
            (int) ( $parts['port'] ?? 443 ) !== (int) ( $base['port'] ?? 443 ) ||
            isset( $parts['user'] ) || isset( $parts['pass'] ) ||
            isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return new WP_Error( 'cwv_url_not_same_site', 'Use an HTTPS URL on this WordPress site, without query parameters or fragments.' );
        }
        $path = $parts['path'] ?? '/';
        $base_path = $base['path'] ?? '/';
        if ( ! is_string( $path ) || ! is_string( $base_path ) ||
            0 !== strpos( $path, '/' ) ||
            0 !== strpos( $path, $base_path ) ||
            false !== strpos( strtolower( rawurldecode( $path ) ), '/wp-admin' ) ||
            false !== strpos( strtolower( rawurldecode( $path ) ), '/wp-json' ) ) {
            return new WP_Error( 'cwv_url_path_invalid', 'Use a public page URL within the site.' );
        }
        return esc_url_raw( $candidate );
    }

}
