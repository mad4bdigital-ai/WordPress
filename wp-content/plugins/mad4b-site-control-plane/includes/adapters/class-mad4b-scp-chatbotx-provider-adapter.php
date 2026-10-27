<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Source-reviewed ChatbotX provider adapter for ETG WooCommerce Lab.
 *
 * Three explicit read-only abilities. No arbitrary endpoint invocation,
 * plugin callbacks, order/customer data, token output or mutation authority.
 * Registration through the existing reviewed bundled-class policy only.
 */
final class MAD4B_SCP_ChatbotX_Provider_Adapter extends MAD4B_SCP_Adapter_Base {
    const PLUGIN = 'customer-messaging-bridge/customer-messaging-bridge.php';
    // The approved provider owns its pinned origin; Control Plane is site-neutral.
    private function origin() {
        return $this->is_available() ? Customer_Messaging_Bridge::API_ORIGIN : '';
    }
    const CONTRACT = 'mad4b.chatbotx-provider.v1';

    public function id() { return 'chatbotx-provider'; }
    public function label() { return 'ChatbotX Omnichannel Provider'; }
    protected function detect_plugin_version() {
        $p = WP_PLUGIN_DIR . '/' . self::PLUGIN;
        if ( ! is_file($p) || ! function_exists('get_file_data') ) return '';
        $meta = get_file_data($p, array('Version' => 'Version'), 'plugin');
        return isset($meta['Version']) && is_string($meta['Version']) ? substr($meta['Version'], 0, 30) : '';
    }
    public function is_available() {
        return defined('WP_PLUGIN_DIR')
            && in_array(self::PLUGIN, (array) get_option('active_plugins', array()), true)
            && class_exists('Customer_Messaging_Bridge', false)
            && class_exists('CMB_Channels', false)
            && class_exists('CMB_Commerce', false)
            && class_exists('CMB_Webhooks', false);
    }
    public function ability_names() {
        return array('read' => array(
            'mad4b/chatbotx-provider-snapshot',
            'mad4b/chatbotx-channels-read',
            'mad4b/chatbotx-remote-health',
        ), 'content' => array(), 'write' => array(), 'admin' => array());
    }
    protected function mutation_requires_certification() { return false; }
    protected function provider_certification($available) { return null; }
    public function reversible_contracts() { return array(); }
    public function register_abilities() {
        if ( ! $this->is_available() ) return;
        $permission = array('MAD4B_SCP_Policy', 'can_read');
        $this->add_ability('mad4b/chatbotx-provider-snapshot',
            'Read ChatbotX Provider and WordPress Surface Inventory',
            'provider_snapshot', $permission, $this->schema(array()));
        $this->add_ability('mad4b/chatbotx-channels-read',
            'Read Connected ChatbotX Workspace Inboxes',
            'channels_read', $permission, $this->schema(array()));
        $this->add_ability('mad4b/chatbotx-remote-health',
            'Probe Fixed ChatbotX Health Endpoint',
            'remote_health', $permission, $this->schema(array()));
    }

    private function scope() {
        return array(
            'contract' => self::CONTRACT,
            'source' => 'installed_lab_bridge',
            'provider' => 'chatbotx',
            'read_only' => true,
            'authorizing' => false,
            'mutations_enabled' => false,
            'mcp_read_available' => $this->is_available(),
        );
    }
    public function provider_snapshot($input = array()) {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        $site = Customer_Messaging_Bridge::settings();
        $commerce = CMB_Commerce::settings();
        $counts = get_option(CMB_Commerce::OUTBOX, array());
        $legacy = get_option(Customer_Messaging_Bridge::AUDIT, array());
        $routes = array(
            array('method' => 'POST', 'path' => '/cmb/v1/events',
                'state' => CMB_Webhooks::secret() ? 'configured_not_sender_certified' : 'disabled_without_private_secret'),
            array('method' => 'GET', 'path' => '/cmb/v1/status',
                'state' => 'wordpress_administrator_only'),
        );
        // Read the exact registered metadata if the REST server has already
        // been bootstrapped; never trigger rest_api_init during inventory.
        global $wp_rest_server;
        $registered = is_object($wp_rest_server) && method_exists($wp_rest_server, 'get_routes')
            ? array_keys((array) $wp_rest_server->get_routes()) : array();
        foreach ($routes as &$route) $route['registered_in_current_lifecycle'] = in_array($route['path'], $registered, true);
        unset($route);
        return array_merge($this->scope(), array(
            'plugin_file' => self::PLUGIN,
            'plugin_version' => $this->detect_plugin_version(),
            'webchat' => array(
                'enabled' => Customer_Messaging_Bridge::ready(),
                'workspace_id' => (string) $site['workspace_id'],
                'webchat_id' => (string) $site['webchat_id'],
                'browser_round_trip_certified' => false,
            ),
            'local_commerce' => array(
                'orders_observed' => '1' === $commerce['orders'],
                'customers_observed' => '1' === $commerce['customers'],
                'cart_observation' => '1' === $commerce['abandoned_cart'],
                'retained_events' => is_array($counts) ? count($counts) : 0,
                'legacy_retained_events' => is_array($legacy) ? count($legacy) : 0,
                'outbound_sync_enabled' => false,
            ),
            'channels' => array(
                'known_types' => array_keys(CMB_Channels::CHANNELS),
                'workspace_token_present' => '' !== CMB_Channels::api_token(),
                'provider_oauth_verified' => false,
            ),
            'surfaces' => array(
                'rest' => $routes,
                'admin_post' => array('cmb_save','cmb_test','cmb_commerce_save','cmb_commerce_clear'),
                'registered_options' => array('cmb_settings_v1', 'cmb_commerce_settings_v1'),
                'settings_write_via_mcp' => false,
            ),
            'external_contracts' => array(
                array('method' => 'GET', 'path' => '/api/health', 'origin' => $this->origin()),
                array('method' => 'GET', 'path' => '/api/v1/inboxes', 'origin' => $this->origin(),
                    'requires_scope' => 'inbox', 'permission' => 'read_only'),
            ),
            'authorization_notice' => 'Discovered surfaces do not grant permission to invoke external writes, callbacks or arbitrary routes.',
        ));
    }
    public function channels_read($input = array()) {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        $token = CMB_Channels::api_token();
        if ( '' === $token ) return array_merge($this->scope(), array('state' => 'needs_read_only_inbox_token', 'inboxes' => array()));
        // Client discovery caches in wp_options; this adapter intentionally
        // performs an uncached read without any WordPress side effects.
        $items = array();
        for ($page = 1; $page <= 10; $page++) {
            $response = wp_remote_get($this->origin() . '/api/v1/inboxes?page=' . $page . '&perPage=50', array(
                'timeout' => 8, 'redirection' => 0, 'sslverify' => true,
                'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
            ));
            if ( is_wp_error($response) ) return array_merge($this->scope(), array('state' => 'transport_error', 'inboxes' => array()));
            if (200 !== (int) wp_remote_retrieve_response_code($response)) {
                return array_merge($this->scope(), array('state' => 'remote_status_' . (int) wp_remote_retrieve_response_code($response), 'inboxes' => array()));
            }
            $payload = json_decode((string) wp_remote_retrieve_body($response), true);
            $pages = is_array($payload) ? ($payload['pageCount'] ?? 1) : null;
            $entries = is_array($payload) ? ($payload['data'] ?? null) : null;
            if (!is_int($pages) || $pages < 1 || $pages > 10 || !is_array($entries)
                || ($entries && array_keys($entries) !== range(0, count($entries) - 1))) {
                return array_merge($this->scope(), array('state' => 'invalid_remote_response', 'inboxes' => array()));
            }
            foreach ($entries as $item) {
                if (!is_array($item) || !isset($item['channel'], $item['id'])) continue;
                $channel = sanitize_key((string) $item['channel']);
                if (!isset(CMB_Channels::CHANNELS[$channel])) continue;
                $items[] = array('channel' => $channel,
                    'inbox_id' => sanitize_text_field((string) $item['id']),
                    'status' => sanitize_key((string) ($item['status'] ?? 'unknown')));
            }
            if ($page >= $pages) break;
        }
        return array_merge($this->scope(), array('state' => 'connected_to_api', 'inboxes' => $items,
            'provider_round_trip_verified' => false, 'inbox_count' => count($items)));
    }
    public function remote_health($input = array()) {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        $response = wp_remote_get($this->origin() . '/api/health', array(
            'timeout' => 7, 'redirection' => 0, 'sslverify' => true,
            'headers' => array('Accept' => 'application/json'),
        ));
        if ( is_wp_error($response) ) return array_merge($this->scope(), array('http_status' => 0, 'healthy' => false));
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return array_merge($this->scope(), array('http_status' => $status,
            'healthy' => 200 === $status && is_array($data) && ($data['status'] ?? '') === 'healthy'));
    }
}
