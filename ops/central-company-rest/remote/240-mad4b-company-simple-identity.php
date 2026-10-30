<?php
/**
 * Plugin Name: MAD4B Company Simple REST Identity (MU)
 * Description: Read-only WordPress Application Password identity endpoint; no custom signing protocol.
 * Version: 0.1.0
 *
 * Opt-in: configure mad4b_simple_service_user_id_v1 on the company site with
 * an existing, dedicated low-privilege WordPress user. Does not create users,
 * change capabilities, or permit content/price/customer exports.
 */
if (!defined('ABSPATH')) { exit; }

final class MAD4B_Company_Simple_Identity_V1 {
    const ROUTE = '/mad4b-company/v1/simple-identity';
    const USER_OPTION = 'mad4b_simple_service_user_id_v1';
    const HOSTS = [
        'www.allroyalegypt.com' => 'all-royal-egypt',
        'egypttourgates.com' => 'egypt-tour-gates',
        'tourism.almallahgroup-mg.com' => 'tourism-almallahgroup',
    ];
    private static $app_password_user = 0;

    public static function mark_app_password_auth($user, $item = null): void {
        if (is_object($user) && isset($user->ID)) {
            self::$app_password_user = (int) $user->ID;
        }
    }
    public static function identity_for_host(): array {
        $url = home_url('/');
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($scheme !== 'https' || !isset(self::HOSTS[$host])) {
            return [];
        }
        return ['company_id' => self::HOSTS[$host], 'canonical_host' => $host, 'canonical_url' => 'https://' . $host];
    }
    public static function authorized_for($request, $method, $route) {
        $site = self::identity_for_host();
        if (!$site || !($request instanceof WP_REST_Request) ||
            $request->get_method() !== $method || $request->get_route() !== $route) {
            return new WP_Error('mad4b_simple_invalid_site', 'Connection unavailable.', ['status' => 403]);
        }
        $service_user_id = (int) get_option(self::USER_OPTION, 0);
        if ($service_user_id <= 0 || !function_exists('wp_is_application_passwords_available') ||
            !wp_is_application_passwords_available() || self::$app_password_user !== $service_user_id ||
            get_current_user_id() !== $service_user_id || !current_user_can('read')) {
            return new WP_Error('mad4b_simple_auth_required', 'Application Password access required.', ['status' => 403]);
        }
        return true;
    }
    public static function permission($request) {
        return self::authorized_for($request, 'GET', self::ROUTE);
    }
    public static function identity() {
        $site = self::identity_for_host();
        $result = new WP_REST_Response([
            'contract' => 'mad4b.company.simple-identity.v1',
            'company_id' => $site['company_id'],
            'canonical_host' => $site['canonical_host'],
            'canonical_url' => $site['canonical_url'],
            'auth_method' => 'wp_application_password',
            'authenticated' => true,
            'reference_only' => true,
            'writes_enabled' => false,
            'checkout_enabled' => false,
        ], 200);
        $result->header('Cache-Control', 'no-store, private');
        return $result;
    }
    public static function boot(): void {
        add_action('application_password_did_authenticate', [__CLASS__, 'mark_app_password_auth'], 10, 2);
        add_action('rest_api_init', static function() {
            if (!self::identity_for_host()) { return; }
            register_rest_route('mad4b-company/v1', '/simple-identity', [
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => [__CLASS__, 'permission'],
                'callback' => [__CLASS__, 'identity'],
            ]);
        });
    }
}
MAD4B_Company_Simple_Identity_V1::boot();
