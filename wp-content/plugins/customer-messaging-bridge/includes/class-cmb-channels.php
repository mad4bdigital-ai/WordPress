<?php
/**
 * Channel registry and token-scoped, read-only ChatbotX API discovery.
 * Provider OAuth/credentials MUST be connected inside ChatbotX, not WordPress.
 */
defined('ABSPATH') || exit;

final class CMB_Channels {
    public const CHANNELS = [
        'webchat' => ['name' => 'Website Live Chat', 'connect' => 'widget'],
        'whatsapp' => ['name' => 'WhatsApp Business', 'connect' => 'provider'],
        'messenger' => ['name' => 'Facebook Messenger', 'connect' => 'provider'],
        'instagram' => ['name' => 'Instagram Direct', 'connect' => 'provider'],
        'smtp' => ['name' => 'Email / Shared Inbox', 'connect' => 'provider'],
    ];
    public const FEATURES = [
        'crm' => 'CRM / Contact Profiles',
        'orders' => 'WooCommerce Orders',
        'customers' => 'WooCommerce Customers',
        'abandoned_cart' => 'Abandoned Checkout Insights',
        'automations' => 'Flows and Automations',
        'ai' => 'AI Chatbots and Handoffs',
        'shared_inbox' => 'Unified Team Inbox',
    ];

    public static function api_token(): string {
        // Explicit wp-config.php constant only. Never save tokens in wp_options.
        if (!defined('CMB_CHATBOTX_WORKSPACE_TOKEN')) { return ''; }
        $token = constant('CMB_CHATBOTX_WORKSPACE_TOKEN');
        return is_string($token) && strlen($token) > 16 && strlen($token) < 5000 ? trim($token) : '';
    }

    public static function configured_inboxes(bool $force = false): array {
        $token = self::api_token();
        if ($token === '') { return ['state' => 'needs_token', 'inboxes' => [], 'error' => '']; }
        $cacheKey = 'cmb_inboxes_' . substr(hash('sha256', $token), 0, 32);
        if (!$force && function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
            if (is_array($cached)) { return $cached; }
        }
        $inboxes = [];
        $totalPages = 1;
        // The upstream inbox API caps page size at 50. A single request
        // would silently hide connected providers after the first page.
        // Upper bound protects the settings page from runaway HTTP loops.
        for ($page = 1; $page <= 10; $page++) {
            $response = wp_remote_get(Customer_Messaging_Bridge::API_ORIGIN .
                '/api/v1/inboxes?page=' . $page . '&perPage=50', [
                'timeout' => 8,
                'redirection' => 0,
                'sslverify' => true,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
            ]);
            if (is_wp_error($response)) {
                return ['state' => 'connection_error', 'inboxes' => [],
                    'error' => sanitize_text_field($response->get_error_code())];
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                return ['state' => 'http_error', 'inboxes' => [], 'error' => (string) $code];
            }
            $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data']) ||
                (!empty($decoded['data']) && array_keys($decoded['data']) !== range(0, count($decoded['data']) - 1))) {
                return ['state' => 'invalid_response', 'inboxes' => [], 'error' => ''];
            }
            $totalPages = $decoded['pageCount'] ?? 1;
            if (!is_int($totalPages) || $totalPages < 1 || $totalPages > 10 || $totalPages < $page) {
                return ['state' => 'invalid_pagination', 'inboxes' => [], 'error' => ''];
            }
            foreach ($decoded['data'] as $item) {
                if (!is_array($item) || !isset($item['channel'], $item['id'])) { continue; }
                $channel = sanitize_key((string) $item['channel']);
                if (!isset(self::CHANNELS[$channel])) { continue; }
                $inboxes[] = [
                    'channel' => $channel,
                    'id' => sanitize_text_field((string) $item['id']),
                    'name' => sanitize_text_field((string) ($item['name'] ?? $channel)),
                    'status' => sanitize_key((string) ($item['status'] ?? 'unknown')),
                ];
            }
            if ($page >= $totalPages) { break; }
        }
        $status = ['state' => 'connected', 'inboxes' => $inboxes, 'error' => ''];
        if (function_exists('set_transient')) { set_transient($cacheKey, $status, 120); }
        return $status;
    }

    public static function admin_panel(): void {
        if (!current_user_can('manage_options')) { return; }
        $s = self::configured_inboxes();
        echo '<h2>Omnichannel / Shared Inbox</h2>';
        echo '<p>Connect WhatsApp Business, Messenger, Instagram and Email in ChatbotX. This WordPress bridge only reads authorized channel metadata; OAuth keys and inbox messages stay in ChatbotX.</p>';
        echo '<p>Workspace token: <strong>' . esc_html(self::api_token() ? 'Configured server-side' : 'Not configured') . '</strong>. ';
        echo 'Channel discovery: <strong>' . esc_html($s['state']) . '</strong></p>';
        echo '<table class="widefat striped"><thead><tr><th>Channel</th><th>Lab connection</th><th>Management</th></tr></thead><tbody>';
        foreach (self::CHANNELS as $key => $meta) {
            $matches = array_values(array_filter($s['inboxes'], static fn($r) => $r['channel'] === $key));
            $connected = count(array_filter($matches, static fn($r) => ($r['status'] ?? '') === 'connected')) > 0;
            $state = ($key === 'webchat' && Customer_Messaging_Bridge::ready())
                ? 'Webchat configured (round-trip unverified)'
                : ($connected ? 'Provider connected (round-trip unverified)'
                    : (count($matches) ? 'Inbox present — not connected' : 'Not connected'));
            echo '<tr><td>' . esc_html($meta['name']) . '</td><td>' . esc_html($state) .
                '</td><td><a href="' . esc_url(Customer_Messaging_Bridge::API_ORIGIN) .
                '" target="_blank" rel="noopener noreferrer">Open ChatbotX</a></td></tr>';
        }
        echo '</tbody></table><p>These states are configuration/discovery indicators, not evidence that any external provider can send or receive messages. Complete provider OAuth and a real round-trip test before use.</p>';
        echo '<h3>Additional modules</h3><ul>';
        foreach (self::FEATURES as $key => $label) {
            echo '<li>' . esc_html($label) . ': ' . esc_html(self::module_state($key)) . '</li>';
        }
        echo '</ul>';
    }

    public static function module_state(string $key): string {
        if (in_array($key, ['orders','customers','abandoned_cart'], true)) {
            return class_exists('WooCommerce') ? 'Lab event capture available (outbound disabled)' : 'Requires WooCommerce';
        }
        if ($key === 'crm') { return self::api_token() ? 'Read-only discovery ready; CRM writes disabled' : 'Requires scoped API token'; }
        return 'Managed in ChatbotX; provider/channel configuration required';
    }
}
