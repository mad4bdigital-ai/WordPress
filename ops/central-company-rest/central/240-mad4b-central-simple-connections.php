<?php
/**
 * Plugin Name: MAD4B Central Simple Connections (MU)
 * Description: Administrator-only Connect / Test / Disconnect using native WordPress Application Passwords.
 * Version: 0.1.0
 *
 * Additive Staging bridge. Existing signed pairing and Content Hub readers
 * remain unchanged; this code never authorizes publication, orders, or writes
 * to a connected company. Credentials are encrypted at rest, not logged.
 */
if (!defined('ABSPATH')) { exit; }

final class MAD4B_Central_Simple_Connections_V1 {
    const API = 'mad4b-central/v1';
    const OPTION_PREFIX = 'mad4b_simple_connection_';
    const STATE_OPTION = 'mad4b_simple_connection_status_v1';

    public static function enabled(): bool {
        return strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)) === 'tours.dev.mad4b.com' &&
            class_exists('MAD4B_Central_Company_REST_V1');
    }
    public static function admin_only(): bool {
        return self::enabled() && current_user_can('manage_options');
    }
    private static function company($id): ?array {
        if (!is_string($id) || !preg_match('/^[a-z0-9-]{2,64}$/D', $id)) { return null; }
        $company = MAD4B_Central_Company_REST_V1::COMPANIES[$id] ?? null;
        if (!is_array($company) || empty($company['host']) || empty($company['url']) ||
            $company['url'] !== 'https://' . $company['host'] ||
            !filter_var($company['url'], FILTER_VALIDATE_URL)) {
            return null;
        }
        return $company;
    }
    private static function key($id): string {
        return self::OPTION_PREFIX . str_replace('-', '_', $id);
    }
    private static function error($code, $message, $status = 409) {
        return new WP_Error($code, $message, ['status' => $status]);
    }
    private static function encryption_key(): string {
        return hash('sha256', wp_salt('auth') . '|mad4b.simple-connection.v1', true);
    }
    private static function seal($username, $password) {
        if (!function_exists('openssl_encrypt') || !function_exists('random_bytes')) {
            return false;
        }
        try { $iv = random_bytes(12); } catch (Throwable $e) { return false; }
        $tag = '';
        $ciphertext = openssl_encrypt($password, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || strlen($tag) !== 16) { return false; }
        return [
            'username' => $username,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
            'format' => 'aes-256-gcm-v1'
        ];
    }
    private static function unseal($value) {
        if (!is_array($value) || ($value['format'] ?? '') !== 'aes-256-gcm-v1' ||
            !isset($value['username'], $value['iv'], $value['tag'], $value['ciphertext'])) {
            return false;
        }
        $iv = base64_decode((string) $value['iv'], true);
        $tag = base64_decode((string) $value['tag'], true);
        $ciphertext = base64_decode((string) $value['ciphertext'], true);
        if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16 ||
            !is_string($ciphertext)) { return false; }
        $password = openssl_decrypt($ciphertext, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($password) ? ['username' => $value['username'], 'application_password' => $password] : false;
    }
    private static function probe($id, $company, $username, $password) {
        // No redirects, caller-provided hosts, unverified TLS, or credential URLs.
        $response = wp_safe_remote_get($company['url'] . '/wp-json/mad4b-company/v1/simple-identity', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
                'Accept' => 'application/json'
            ],
            'timeout' => 12,
            'redirection' => 0,
            'sslverify' => true,
            'reject_unsafe_urls' => true,
            'limit_response_size' => 8192
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::error('mad4b_simple_probe_failed', 'Could not authenticate company connection.', 502);
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) ||
            ($data['contract'] ?? '') !== 'mad4b.company.simple-identity.v1' ||
            ($data['company_id'] ?? '') !== $id ||
            ($data['canonical_host'] ?? '') !== $company['host'] ||
            ($data['canonical_url'] ?? '') !== $company['url'] ||
            ($data['auth_method'] ?? '') !== 'wp_application_password' ||
            ($data['authenticated'] ?? false) !== true ||
            ($data['reference_only'] ?? false) !== true ||
            ($data['writes_enabled'] ?? true) !== false ||
            ($data['checkout_enabled'] ?? true) !== false) {
            return self::error('mad4b_simple_identity_mismatch', 'Remote site identity was not verified.');
        }
        return true;
    }
    private static function mark_connected($id): void {
        $state = get_option(self::STATE_OPTION, []);
        if (!is_array($state)) { $state = []; }
        $state[$id] = ['last_test_at' => gmdate('c'), 'connection_method' => 'wp_application_password'];
        update_option(self::STATE_OPTION, $state, false);
    }
    public static function status() {
        $state = get_option(self::STATE_OPTION, []);
        if (!is_array($state)) { $state = []; }
        $out = [];
        foreach (MAD4B_Central_Company_REST_V1::COMPANIES as $id => $company) {
            $saved = get_option(self::key($id), false);
            $out[] = [
                'company_id' => $id,
                'company_name' => $company['name'],
                'configured' => is_array($saved) && ($saved['format'] ?? '') === 'aes-256-gcm-v1',
                'last_test_at' => $state[$id]['last_test_at'] ?? null,
                'content_distribution_active' => false
            ];
        }
        return new WP_REST_Response(['contract' => 'mad4b.simple-connections.v1', 'companies' => $out], 200);
    }
    public static function connect(WP_REST_Request $request) {
        $id = (string) $request['company'];
        $company = self::company($id);
        if (!$company) { return self::error('mad4b_simple_unknown_company', 'Unknown company.', 404); }
        $body = $request->get_json_params();
        if (!is_array($body) || count($body) !== 2 ||
            !isset($body['username'], $body['application_password']) ||
            !is_string($body['username']) || !is_string($body['application_password'])) {
            return self::error('mad4b_simple_invalid_input', 'Username and Application Password required.', 400);
        }
        $username = trim($body['username']);
        $password = trim($body['application_password']);
        if (!preg_match('/^[A-Za-z0-9_.@+-]{1,100}$/D', $username) ||
            strlen($password) < 16 || strlen($password) > 256 ||
            preg_match('/[\r\n\x00-\x1F\x7F]/', $password)) {
            return self::error('mad4b_simple_invalid_input', 'Invalid connection credentials.', 400);
        }
        $verified = self::probe($id, $company, $username, $password);
        if (is_wp_error($verified)) { return $verified; }
        $sealed = self::seal($username, $password);
        if ($sealed === false) { return self::error('mad4b_simple_encryption_unavailable', 'Credential store unavailable.', 503); }
        if (!update_option(self::key($id), $sealed, false)) {
            return self::error('mad4b_simple_save_failed', 'Could not save company credentials.', 503);
        }
        self::mark_connected($id);
        return new WP_REST_Response(['company_id' => $id, 'connected' => true, 'content_distribution_active' => false], 200);
    }
    public static function test_saved(WP_REST_Request $request) {
        $id = (string) $request['company'];
        $company = self::company($id);
        if (!$company) { return self::error('mad4b_simple_unknown_company', 'Unknown company.', 404); }
        $credentials = self::unseal(get_option(self::key($id), false));
        if (!$credentials) { return self::error('mad4b_simple_not_connected', 'Company needs connecting again.', 409); }
        $verified = self::probe($id, $company, $credentials['username'], $credentials['application_password']);
        if (is_wp_error($verified)) { return $verified; }
        self::mark_connected($id);
        return new WP_REST_Response(['company_id' => $id, 'connected' => true, 'content_distribution_active' => false], 200);
    }
    public static function disconnect(WP_REST_Request $request) {
        $id = (string) $request['company'];
        if (!self::company($id)) { return self::error('mad4b_simple_unknown_company', 'Unknown company.', 404); }
        delete_option(self::key($id));
        $state = get_option(self::STATE_OPTION, []);
        if (is_array($state)) {
            unset($state[$id]);
            update_option(self::STATE_OPTION, $state, false);
        }
        return new WP_REST_Response(['company_id' => $id, 'connected' => false], 200);
    }
    public static function boot(): void {
        if (!self::enabled()) { return; }
        add_action('rest_api_init', static function() {
            register_rest_route(self::API, '/simple-connections', [
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => [__CLASS__, 'admin_only'],
                'callback' => [__CLASS__, 'status']
            ]);
            foreach (['connect' => 'connect', 'test' => 'test_saved', 'disconnect' => 'disconnect'] as $action => $method) {
                register_rest_route(self::API, '/simple-connections/(?P<company>[a-z0-9-]+)/' . $action, [
                    'methods' => WP_REST_Server::CREATABLE,
                    'permission_callback' => [__CLASS__, 'admin_only'],
                    'callback' => [__CLASS__, $method]
                ]);
            }
        });
    }
}
MAD4B_Central_Simple_Connections_V1::boot();
