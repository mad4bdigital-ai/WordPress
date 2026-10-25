<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Minimal, pre-zero-touch service credential fence.
 * WordPress will otherwise skip the main MAD4B kernel for unrelated REST
 * requests, where authenticated subscriber credentials must still be denied.
 */
final class MAD4B_SCP_AI_Only_Early_Auth_Guard {
    const ROUTE = '/mad4b-local-ai/v1/context-review';
    const DOMAIN_ROUTE = '/mad4b-local-ai/v1/domain-review';
    const META = '_mad4b_ai_only_local_service_site';

    public static function authenticate( $error, $user, $item ) {
        if ( ! ( $error instanceof WP_Error ) || ! ( $user instanceof WP_User )
            || ! function_exists( 'wp_get_environment_type' )
            || 'local' !== wp_get_environment_type() ) return;
        if ( 0 !== strpos( (string) $user->user_login, 'mad4b_ai_local_' ) ) return;
        // Any managed credential must be restricted, even if profile settings
        // drift, the main plugin is not booted, or revocation was delayed.
        $expected = defined( 'MAD4B_LOCAL_CLIENT_SITE_UUID' )
            ? strtolower( (string) constant( 'MAD4B_LOCAL_CLIENT_SITE_UUID' ) ) : '';
        $actual = strtolower( (string) get_user_meta( (int) $user->ID, self::META, true ) );
        if ( '' === $expected || ! hash_equals( $expected, $actual ) ) {
            $error->add( 'mad4b_ai_only_service_site_denied', 'Managed local service is not site-bound.' );
            return;
        }
        $url = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $query = isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : '';
        $rest = '/' . trim( $query, '/' );
        $route = '/wp-json' . self::ROUTE === rtrim( $path, '/' )
            || self::ROUTE === $rest;
        $domain = '/wp-json' . self::DOMAIN_ROUTE === rtrim( $path, '/' )
            || self::DOMAIN_ROUTE === $rest;
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD']??''));
        $accepted = ($route && in_array($method,array('POST','GET'),true))
            || ($domain && $method==='POST');
        if (!$accepted) {
            $error->add( 'mad4b_ai_only_service_route_denied',
                'Managed local AI review credentials cannot authenticate other WordPress routes.' );
        }
    }
    public static function boot() {
        if ( ! function_exists( 'wp_get_environment_type' )
             || 'local' !== wp_get_environment_type() ) return;
        add_action( 'wp_authenticate_application_password_errors', array( __CLASS__, 'authenticate' ), 1, 3 );
    }
}
MAD4B_SCP_AI_Only_Early_Auth_Guard::boot();
