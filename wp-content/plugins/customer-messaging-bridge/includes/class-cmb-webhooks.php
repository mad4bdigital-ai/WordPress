<?php
/**
 * Optional signed inbound webhook receiver, guarded until independently
 * configured with a long random server-side secret. Does not mutate orders.
 */
defined('ABSPATH') || exit;

final class CMB_Webhooks {
    public const AUDIT = 'cmb_inbound_webhook_events_v1';
    public const EVENT_KINDS = [
        'conversation.created', 'conversation.closed', 'message.received',
        'contact.updated', 'channel.connected', 'automation.completed',
    ];

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function secret(): string {
        if (!defined('CMB_INBOUND_HMAC_SECRET')) { return ''; }
        $secret = constant('CMB_INBOUND_HMAC_SECRET');
        return is_string($secret) && strlen($secret) >= 32 ? $secret : '';
    }

    public static function routes(): void {
        register_rest_route('cmb/v1', '/events', [
            'methods' => 'POST',
            'callback' => [self::class, 'receive'],
            // Custom HMAC validation below: no WordPress cookie/session auth.
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('cmb/v1', '/status', [
            'methods' => 'GET',
            'callback' => [self::class, 'status'],
            'permission_callback' => static fn() => current_user_can('manage_options'),
        ]);
    }

    public static function verify(string $body, string $timestamp, string $signature, int $now = 0): bool {
        $secret = self::secret();
        if ($secret === '' || strlen($body) < 2 || strlen($body) > 32768) { return false; }
        if (!preg_match('/^\d{10}$/D', $timestamp)) { return false; }
        $time = (int) $timestamp;
        if ($now === 0) { $now = time(); }
        if (abs($now - $time) > 300) { return false; }
        if (!preg_match('/^sha256=[a-f0-9]{64}$/D', $signature)) { return false; }
        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        return hash_equals($expected, $signature);
    }

    public static function receive($request) {
        if (!self::secret()) {
            return new WP_Error('cmb_not_configured', 'Webhook receiver is disabled', ['status' => 503]);
        }
        $body = (string) $request->get_body();
        $timestamp = (string) $request->get_header('X-CMB-Timestamp');
        $signature = (string) $request->get_header('X-CMB-Signature');
        if (!self::verify($body, $timestamp, $signature)) {
            return new WP_Error('cmb_invalid_signature', 'Invalid webhook signature', ['status' => 401]);
        }
        $payload = json_decode($body, true);
        if (!is_array($payload) || !isset($payload['kind']) ||
            !in_array($payload['kind'], self::EVENT_KINDS, true) ||
            !isset($payload['workspaceId']) ||
            (string) $payload['workspaceId'] !== (string) Customer_Messaging_Bridge::settings()['workspace_id']) {
            return new WP_Error('cmb_invalid_event', 'Unsupported event or workspace', ['status' => 422]);
        }
        $eventId = isset($payload['id']) && is_string($payload['id']) && strlen($payload['id']) <= 120
            ? $payload['id'] : '';
        if ($eventId === '' || !preg_match('/^[a-zA-Z0-9._:-]{1,120}$/D', $eventId)) {
            return new WP_Error('cmb_invalid_event_id', 'Event id required', ['status' => 422]);
        }
        // A WordPress transient read-then-set is NOT atomic. Reserve a
        // uniquely indexed wp_options option instead: concurrent requests
        // cannot both claim the same workspace + event ID.
        $dedup = 'cmb_rx_' . hash_hmac('sha256',
            (string) $payload['workspaceId'] . ':' . $eventId, self::secret());
        $expiry = time() + DAY_IN_SECONDS;
        self::prune_expired_reservations();
        if (!add_option($dedup, (string) $expiry, '', false)) {
            return rest_ensure_response(['accepted' => true, 'duplicate' => true]);
        }
        // Do not retain payload, sender, message, or customer contact details.
        $events = get_option(self::AUDIT, []);
        if (!is_array($events)) { $events = []; }
        $events[] = ['kind' => sanitize_key($payload['kind']), 'at' => gmdate('c')];
        if (!update_option(self::AUDIT, array_slice($events, -40), false)) {
            delete_option($dedup); // Failed to persist the observation: retry permitted.
            return new WP_Error('cmb_storage_unavailable', 'Temporary event storage failure', ['status' => 503]);
        }
        return rest_ensure_response(['accepted' => true, 'duplicate' => false]);
    }

    public static function prune_expired_reservations(): void {
        // WordPress Cron is disabled in ETG Lab. Prune up to 40 expired
        // reservations opportunistically using delete_option, which keeps
        // object-cache state coherent (unlike raw SQL DELETE).
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) { return; }
        $prefix = $wpdb->esc_like('cmb_rx_') . '%';
        $expired = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 40",
            $prefix, time()
        ));
        if (!is_array($expired)) { return; }
        foreach ($expired as $optionName) {
            if (is_string($optionName) && str_starts_with($optionName, 'cmb_rx_')) {
                delete_option($optionName);
            }
        }
    }

    public static function status() {
        $events = get_option(self::AUDIT, []);
        return rest_ensure_response([
            'webchat_enabled' => Customer_Messaging_Bridge::ready(),
            'channels' => array_keys(CMB_Channels::CHANNELS),
            'webhook_configured' => self::secret() !== '',
            'commerce_outbound' => false,
            'received_event_count' => is_array($events) ? count($events) : 0,
        ]);
    }

    public static function admin_panel(): void {
        if (!current_user_can('manage_options')) { return; }
        echo '<h2>Automation Events / Signed Webhooks</h2>';
        echo '<p>Inbound REST endpoint: <code>' .
            esc_html(rest_url('cmb/v1/events')) . '</code>. ';
        echo 'State: <strong>' . esc_html(self::secret() ? 'Configured' : 'Disabled — define CMB_INBOUND_HMAC_SECRET in wp-config.php') . '</strong>.</p>';
        echo '<p>Webhook requests must carry X-CMB-Timestamp and X-CMB-Signature = sha256=HMAC_SHA256(secret, timestamp + "." + raw_body). Timestamp tolerance: five minutes, replay protection: one day. Payloads are never stored.</p>';
        echo '<p>AI responses, human handoff, flows, team assignment and outgoing provider messaging are managed inside ChatbotX, not duplicated in WordPress.</p>';
    }
}
