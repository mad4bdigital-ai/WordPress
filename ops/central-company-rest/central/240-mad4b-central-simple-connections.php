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
    /**
     * Fixed-route, credential-safe internal reference delivery.
     * Only opaque noncommercial reference snapshots; never arbitrary endpoints.
     */

    /** Fetch one page from a fixed published-content endpoint; no custom signer. */
    public static function fetch_published_page($id, $type, $page) {
        $company=self::company($id);
        if (!self::enabled() || !$company ||
            !in_array($type,['tours-and-activities','properties','transportations'],true) ||
            !is_int($page) || $page<1 || $page>100) {
            return self::error('mad4b_simple_source_invalid','Invalid source selection.',400);
        }
        $creds=self::unseal(get_option(self::key($id),false));
        if (!$creds)return self::error('mad4b_simple_not_connected','Connect source company first.',409);
        $url=$company['url'].'/wp-json/mad4b-company/v1/simple-content-feed';
        $resp=wp_safe_remote_post($url,[
            'headers'=>[
                'Authorization'=>'Basic '.base64_encode($creds['username'].':'.$creds['application_password']),
                'Accept'=>'application/json', 'Content-Type'=>'application/json',
            ],
            'body'=>wp_json_encode(['post_type'=>$type,'page'=>$page],JSON_UNESCAPED_SLASHES),
            'timeout'=>18,'redirection'=>0,'sslverify'=>true,
            'reject_unsafe_urls'=>true,'limit_response_size'=>360000,
        ]);
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp)!==200) {
            return self::error('mad4b_simple_source_unavailable','Source content feed unavailable.',502);
        }
        $data=json_decode(wp_remote_retrieve_body($resp),true);
        if (!is_array($data) ||
            ($data['contract']??'')!=='mad4b.company.published-content-page.v1' ||
            ($data['company_id']??'')!==$id ||
            ($data['canonical_host']??'')!==$company['host'] ||
            ($data['source_post_type']??'')!==$type ||
            ($data['page']??null)!==$page || ($data['per_page']??null)!==20 ||
            !is_int($data['total']??null) || $data['total']<0 || $data['total']>2000 ||
            !is_array($data['items']??null) || count($data['items'])>20 ||
            ($data['reference_only']??false)!==true ||
            ($data['may_publish']??true)!==false || ($data['may_checkout']??true)!==false) {
            return self::error('mad4b_simple_source_invalid','Source content identity mismatch.',409);
        }
        return $data;
    }
    public static function deliver_references($id, array $snapshot) {
        $company = self::company($id);
        if (!self::enabled() || !$company || ($snapshot['consumer_company'] ?? '') !== $id ||
            ($snapshot['contract'] ?? '') !== 'mad4b.hub.private-reference-snapshot.v1' ||
            ($snapshot['may_publish'] ?? true) !== false || ($snapshot['may_checkout'] ?? true) !== false) {
            return self::error('mad4b_simple_delivery_invalid', 'Invalid reference delivery.', 400);
        }
        $creds = self::unseal(get_option(self::key($id), false));
        if (!$creds) { return self::error('mad4b_simple_not_connected', 'Connect company first.', 409); }
        $json = wp_json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > 150000) {
            return self::error('mad4b_simple_delivery_oversize', 'Reference snapshot too large.', 400);
        }
        $response = wp_safe_remote_post($company['url'] . '/wp-json/mad4b-company/v1/hub-reference-inbox', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($creds['username'] . ':' . $creds['application_password']),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'body' => $json, 'timeout' => 18, 'redirection' => 0,
            'sslverify' => true, 'reject_unsafe_urls' => true, 'limit_response_size' => 8192
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::error('mad4b_simple_delivery_failed', 'Consumer reference inbox rejected delivery.', 502);
        }
        $receipt = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($receipt) || ($receipt['contract'] ?? '') !== 'mad4b.hub.private-reference-receipt.v1' ||
            ($receipt['consumer_company'] ?? '') !== $id ||
            ($receipt['snapshot_sha256'] ?? '') !== ($snapshot['snapshot_sha256'] ?? '') ||
            ($receipt['reference_only'] ?? false) !== true) {
            return self::error('mad4b_simple_receipt_mismatch', 'Consumer delivery confirmation mismatch.', 502);
        }
        return $receipt;
    }

    public static function menu(): void {
        $parent = post_type_exists('mad4b_hub_nile') ?
            'edit.php?post_type=mad4b_hub_nile' : 'tools.php';
        add_submenu_page($parent, 'Content Hub Connections', 'Connections', 'manage_options',
            'mad4b-simple-connections', [__CLASS__, 'screen']);
    }
    public static function screen(): void {
        if (!self::admin_only()) return;
        $data=self::status()->get_data();
        echo '<div class="wrap"><h1>Content Hub — Connections</h1>';
        echo '<p>Use a dedicated WordPress Application Password per company. Only the selected site identity is checked. No content is published.</p>';
        if(isset($_GET['result']))echo '<p><strong>Result: '.esc_html(sanitize_key(wp_unslash($_GET['result']))).'</strong></p>';
        foreach ($data['companies'] as $row) {
            $id=$row['company_id'];
            echo '<hr><h2>'.esc_html($row['company_name']).'</h2><p>Status: '.
                esc_html($row['configured']?'Configured':'Not connected').
                ' | Last test: '.esc_html((string)($row['last_test_at']?:'Never')).'</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_simple_connections_action');
            echo '<input type="hidden" name="action" value="mad4b_simple_connections_action">';
            echo '<input type="hidden" name="company" value="'.esc_attr($id).'">';
            echo '<p><input required autocomplete="username" name="username" placeholder="Service username" class="regular-text"></p>';
            echo '<p><input required autocomplete="new-password" type="password" name="application_password" placeholder="WordPress Application Password" class="regular-text"></p>';
            echo '<button type="submit" class="button button-primary" name="operation" value="connect">Connect</button>';
            echo '</form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_simple_connections_action');
            echo '<input type="hidden" name="action" value="mad4b_simple_connections_action">';
            echo '<input type="hidden" name="company" value="'.esc_attr($id).'">';
            echo '<p><button type="submit" class="button" name="operation" value="test">Test Connection</button> ';
            echo '<button type="submit" class="button" name="operation" value="disconnect">Disconnect</button></p></form>';
        }
        echo '</div>';
    }
    public static function form_submit(): void {
        if (!self::admin_only() || !check_admin_referer('mad4b_simple_connections_action'))wp_die('Forbidden');
        $id=isset($_POST['company'])?sanitize_key(wp_unslash($_POST['company'])):'';
        $operation=isset($_POST['operation'])?sanitize_key(wp_unslash($_POST['operation'])):'';
        if (!self::company($id) || !in_array($operation,['connect','test','disconnect'],true)) {
            $message='invalid_input';
        } else {
            $request=new WP_REST_Request('POST','/mad4b-central/v1/simple-connections/'.$id.'/'.$operation);
            $request->set_url_params(['company'=>$id]);
            if ($operation==='connect') {
                $name=isset($_POST['username'])?trim((string)wp_unslash($_POST['username'])):'';
                $pass=isset($_POST['application_password'])?trim((string)wp_unslash($_POST['application_password'])):'';
                $request->set_header('Content-Type','application/json');
                $request->set_body(wp_json_encode(['username'=>$name,'application_password'=>$pass]));
                $result=self::connect($request);
            } elseif ($operation==='test') {
                $result=self::test_saved($request);
            } else {
                $result=self::disconnect($request);
            }
            $message=is_wp_error($result)?$result->get_error_code():'success';
        }
        $parent=post_type_exists('mad4b_hub_nile')?'edit.php?post_type=mad4b_hub_nile':'tools.php';
        wp_safe_redirect(add_query_arg([
            'page'=>'mad4b-simple-connections','result'=>sanitize_key($message)
        ],admin_url($parent)));
        exit;
    }
    public static function boot(): void {
        if (!self::enabled()) { return; }
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_mad4b_simple_connections_action', [__CLASS__, 'form_submit']);
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
