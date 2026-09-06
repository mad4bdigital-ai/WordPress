<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPL server authentication and HTTP transport.
 *
 * Credentials are intentionally resolved at runtime instead of being embedded in
 * the public plugin source. A package-specific credential may be supplied by an
 * optional includes/wpl-package-credential.php file in a private distribution.
 */
class WPL_Server_Client {

    const CREDENTIAL_OPTION = 'wpl_server_credential';
    const AUTH_STATE_OPTION = 'wpl_server_auth_state';
    const PACKAGE_FILE      = 'includes/wpl-package-credential.php';

    /**
     * Import a private package credential into a non-autoloaded option.
     * Safe to call repeatedly; an already-saved site credential wins.
     */
    public static function bootstrap_package_credential() {
        if ( self::option_credential() !== '' ) return;

        $package = self::package_credential();
        if ( $package === '' ) return;

        update_option( self::CREDENTIAL_OPTION, $package, false );
        self::record_state( 'configured', 0, 'package' );
    }

    /**
     * Resolve the current credential without ever exposing it in diagnostics.
     */
    public static function credential() {
        $credential = '';

        // wp-config.php / bootstrap constant has highest precedence.
        if ( defined( 'WPL_SERVER_API_KEY' ) && is_string( WPL_SERVER_API_KEY ) ) {
            $credential = trim( WPL_SERVER_API_KEY );
        }

        if ( $credential === '' ) {
            $env = getenv( 'WPL_SERVER_API_KEY' );
            if ( is_string( $env ) ) $credential = trim( $env );
        }

        if ( $credential === '' ) {
            $credential = self::option_credential();
        }

        if ( $credential === '' ) {
            $credential = self::package_credential();
        }

        /**
         * Allows a host/MU-plugin/secret manager to provide a site credential.
         * Do not log the value returned by this filter.
         */
        $credential = (string) apply_filters( 'wpl_client_server_credential', $credential );
        return self::valid_format( $credential ) ? trim( $credential ) : '';
    }

    public static function credential_source() {
        if ( defined( 'WPL_SERVER_API_KEY' ) && self::valid_format( (string) WPL_SERVER_API_KEY ) ) return 'constant';

        $env = getenv( 'WPL_SERVER_API_KEY' );
        if ( is_string( $env ) && self::valid_format( $env ) ) return 'environment';
        if ( self::option_credential() !== '' ) return 'site_option';
        if ( self::package_credential() !== '' ) return 'private_package';
        return 'missing';
    }

    public static function status() {
        $state = get_option( self::AUTH_STATE_OPTION, [] );
        if ( ! is_array( $state ) ) $state = [];

        return [
            'configured' => self::credential() !== '',
            'source'     => self::credential_source(),
            'state'      => sanitize_key( (string) ( $state['state'] ?? ( self::credential() !== '' ? 'configured' : 'missing' ) ) ),
            'http_code'  => (int) ( $state['http_code'] ?? 0 ),
            'checked_at' => (int) ( $state['checked_at'] ?? 0 ),
        ];
    }

    public static function valid_format( $credential ) {
        return is_string( $credential ) && (bool) preg_match( '/^wpl_[A-Za-z0-9_-]{20,}$/', trim( $credential ) );
    }

    /**
     * Read-only validation against the products endpoint.
     * No registration or entitlement mutation is performed by this probe.
     */
    public static function probe_credential( $credential ) {
        $credential = trim( (string) $credential );
        if ( ! self::valid_format( $credential ) ) {
            return [
                'ok'          => false,
                'auth_status' => 'invalid_format',
                'http_code'   => 0,
                'message'     => 'صيغة بيانات اتصال WPL غير صحيحة.',
            ];
        }

        $result = self::request(
            'GET',
            '/products?search=__wpl_auth_probe__',
            [ 'timeout' => 20 ],
            $credential
        );

        // The live WPL contract returns 200 for an accepted credential and 401
        // for a rejected credential. Fail closed for every other response.
        if ( (int) $result['http_code'] === 200 ) {
            $result['ok']          = true;
            $result['auth_status'] = 'accepted';
            $result['message']     = 'تم التحقق من بيانات اتصال WPL بنجاح.';
        }

        return $result;
    }

    public static function save_credential( $credential ) {
        $probe = self::probe_credential( $credential );
        if ( empty( $probe['ok'] ) ) return $probe;

        update_option( self::CREDENTIAL_OPTION, trim( (string) $credential ), false );
        self::record_state( 'accepted', 200, 'site_option' );
        $probe['source'] = 'site_option';
        return $probe;
    }

    public static function clear_credential() {
        delete_option( self::CREDENTIAL_OPTION );
        delete_option( self::AUTH_STATE_OPTION );
    }

    /**
     * Normalized WPL HTTP request. Raw credentials are never returned.
     */
    public static function request( $method, $path, array $args = [], $credential = null ) {
        $method     = strtoupper( (string) $method );
        $credential = $credential === null ? self::credential() : trim( (string) $credential );

        if ( $credential === '' || ! self::valid_format( $credential ) ) {
            self::record_state( 'missing', 0, self::credential_source() );
            return [
                'ok'          => false,
                'auth_status' => 'missing',
                'http_code'   => 0,
                'body'        => null,
                'message'     => 'لا توجد بيانات اتصال WPL صالحة.',
            ];
        }

        $url = rtrim( WPL_SERVER_API_URL, '/' ) . '/' . ltrim( (string) $path, '/' );
        $headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : [];
        $headers['X-WPL-Key'] = $credential;
        $headers['Accept']    = 'application/json';

        $request_args = [
            'method'      => $method,
            'headers'     => $headers,
            'timeout'     => isset( $args['timeout'] ) ? max( 1, (int) $args['timeout'] ) : 20,
            'sslverify'   => true,
            'httpversion' => '1.1',
            'blocking'    => array_key_exists( 'blocking', $args ) ? (bool) $args['blocking'] : true,
        ];

        if ( array_key_exists( 'body', $args ) ) {
            $body = $args['body'];
            if ( is_array( $body ) || is_object( $body ) ) {
                $headers['Content-Type']       = 'application/json';
                $request_args['headers']       = $headers;
                $request_args['body']          = wp_json_encode( $body );
            } else {
                $request_args['body'] = $body;
            }
        }

        $response = wp_remote_request( $url, $request_args );
        if ( is_wp_error( $response ) ) {
            self::record_state( 'network_error', 0, self::credential_source() );
            return [
                'ok'          => false,
                'auth_status' => 'network_error',
                'http_code'   => 0,
                'body'        => null,
                'message'     => 'تعذر الاتصال بخادم WPL عبر اتصال TLS موثوق.',
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = (string) wp_remote_retrieve_body( $response );
        $json = json_decode( $raw, true );
        $body = is_array( $json ) ? $json : null;

        if ( $code === 401 ) {
            $state = 'rejected';
            $msg   = 'رفض خادم WPL بيانات الاتصال الحالية.';
        } elseif ( $code === 403 ) {
            $state = 'forbidden';
            $msg   = 'تم التعرف على الاتصال لكن العملية غير مصرح بها لهذا الحساب أو الاستحقاق.';
        } elseif ( $code >= 500 ) {
            $state = 'server_error';
            $msg   = 'خادم WPL غير متاح مؤقتاً.';
        } elseif ( $code >= 200 && $code < 300 ) {
            $state = 'accepted';
            $msg   = '';
        } else {
            $state = 'http_error';
            $msg   = 'أعاد خادم WPL استجابة غير متوقعة.';
        }

        self::record_state( $state, $code, self::credential_source() );

        return [
            'ok'          => $code >= 200 && $code < 300,
            'auth_status' => $state,
            'http_code'   => $code,
            'body'        => $body,
            'message'     => $msg,
        ];
    }

    private static function option_credential() {
        $value = get_option( self::CREDENTIAL_OPTION, '' );
        return self::valid_format( $value ) ? trim( (string) $value ) : '';
    }

    private static function package_credential() {
        $file = defined( 'WPL_CLIENT_DIR' ) ? WPL_CLIENT_DIR . self::PACKAGE_FILE : '';
        if ( $file === '' || ! is_readable( $file ) ) return '';

        require_once $file;
        if ( ! function_exists( 'wpl_package_credential' ) ) return '';

        $value = (string) wpl_package_credential();
        return self::valid_format( $value ) ? trim( $value ) : '';
    }

    private static function record_state( $state, $http_code = 0, $source = '' ) {
        update_option( self::AUTH_STATE_OPTION, [
            'state'      => sanitize_key( (string) $state ),
            'http_code'  => (int) $http_code,
            'source'     => sanitize_key( (string) $source ),
            'checked_at' => time(),
        ], false );
    }
}
