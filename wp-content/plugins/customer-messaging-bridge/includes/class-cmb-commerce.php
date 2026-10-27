<?php
/**
 * WooCommerce event outbox: local, idempotent, privacy-minimal, no outbound POST.
 * Captures a bounded set of order/customer/cart events for review/approval.
 */
defined('ABSPATH') || exit;

final class CMB_Commerce {
    public const OPTION = 'cmb_commerce_settings_v1';
    public const OUTBOX = 'cmb_commerce_outbox_v1';
    public const MAX_EVENTS = 200;

    public static function boot(): void {
        // Preserve the Lab's previously enabled, anonymized Woo order audit
        // when upgrading from bridge v0.1.0. Never enable cart tracking.
        if (get_option(self::OPTION, false) === false &&
            Customer_Messaging_Bridge::settings()['woo_local_audit'] === '1') {
            update_option(self::OPTION, self::sanitize([
                'local_event_capture' => '1', 'orders' => '1',
            ]), false);
        }
        add_action('woocommerce_new_order', [self::class, 'new_order'], 20, 2);
        add_action('woocommerce_order_status_changed', [self::class, 'status_changed'], 20, 4);
        add_action('woocommerce_created_customer', [self::class, 'new_customer'], 20, 1);
        add_action('woocommerce_cart_updated', [self::class, 'cart_updated'], 50, 0);
        add_action('woocommerce_payment_complete', [self::class, 'payment_complete'], 20, 1);
        add_action('woocommerce_order_refunded', [self::class, 'refund_created'], 20, 2);
        add_action('woocommerce_order_status_failed', [self::class, 'payment_failed'], 20, 1);
        add_action('woocommerce_order_status_cancelled', [self::class, 'cancelled'], 20, 1);
        add_action('admin_post_cmb_commerce_save', [self::class, 'save']);
        add_action('admin_post_cmb_commerce_clear', [self::class, 'clear']);
    }

    public static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'orders' => '0', 'customers' => '0', 'abandoned_cart' => '0',
            'local_event_capture' => '1', 'cart_idle_minutes' => 30,
            'consent_required' => '1',
        ]);
    }

    public static function sanitize(array $raw): array {
        return [
            'orders' => !empty($raw['orders']) ? '1' : '0',
            'customers' => !empty($raw['customers']) ? '1' : '0',
            'abandoned_cart' => !empty($raw['abandoned_cart']) ? '1' : '0',
            'local_event_capture' => !empty($raw['local_event_capture']) ? '1' : '0',
            'consent_required' => '1', // never offer a bypass
            'cart_idle_minutes' => max(15, min(1440, (int) ($raw['cart_idle_minutes'] ?? 30))),
        ];
    }

    public static function consent_allowed(): bool {
        // Explicit customer-facing opt-in via a third-party consent manager;
        // do not treat "logged in" or the WooCommerce terms checkbox as consent.
        return true === apply_filters('cmb_customer_marketing_consent', false);
    }

    public static function event_fingerprint(string $kind, string $source_id, string $value): string {
        return hash_hmac('sha256', $kind . '|' . $source_id . '|' . $value, wp_salt('auth'));
    }

    public static function record(string $kind, string $source_id, array $meta = []): bool {
        $s = self::settings();
        if ($s['local_event_capture'] !== '1') { return false; }
        if (!in_array($kind, ['order.created', 'order.status_changed', 'order.payment_complete',
            'order.refunded', 'order.payment_failed', 'order.cancelled',
            'customer.created', 'cart.observed'], true)) { return false; }
        if ((str_starts_with($kind, 'order.') && $s['orders'] !== '1') ||
            ($kind === 'customer.created' && $s['customers'] !== '1') ||
            ($kind === 'cart.observed' && ($s['abandoned_cart'] !== '1' || !self::consent_allowed()))) {
            return false;
        }
        $id = self::event_fingerprint($kind, $source_id, (string) ($meta['status'] ?? '') . '|' . ($meta['revision'] ?? ''));
        $items = get_option(self::OUTBOX, []);
        if (!is_array($items)) { $items = []; }
        foreach ($items as $event) {
            if (is_array($event) && ($event['id'] ?? null) === $id) { return false; }
        }
        // No email, name, phone, address, basket items, or full customer/order IDs.
        $event = [
            'id' => $id,
            'kind' => $kind,
            'status' => sanitize_key((string) ($meta['status'] ?? '')),
            'revision' => sanitize_key((string) ($meta['revision'] ?? '')),
            'at' => gmdate('c'),
            'delivery' => 'local_only',
        ];
        $items[] = $event;
        update_option(self::OUTBOX, array_slice($items, -self::MAX_EVENTS), false);
        return true;
    }

    public static function new_order($order_id, $order = null): void {
        if (self::settings()['orders'] !== '1') { return; }
        self::record('order.created', (string) $order_id);
    }

    public static function status_changed($order_id, $old_status, $new_status, $order = null): void {
        if (self::settings()['orders'] !== '1') { return; }
        self::record('order.status_changed', (string) $order_id, [
            'status' => (string) $new_status,
            'revision' => (string) $old_status . '-' . (string) $new_status,
        ]);
    }

    public static function payment_complete($order_id): void {
        self::record('order.payment_complete', (string) $order_id, ['status' => 'paid']);
    }

    public static function refund_created($order_id, $refund_id): void {
        // A refund can be partial: key the event by both order and refund IDs,
        // never by the final Woo order status alone.
        self::record('order.refunded', (string) $order_id . ':' . (string) $refund_id, [
            'status' => 'refunded',
        ]);
    }

    public static function payment_failed($order_id): void {
        self::record('order.payment_failed', (string) $order_id, ['status' => 'failed']);
    }

    public static function cancelled($order_id): void {
        self::record('order.cancelled', (string) $order_id, ['status' => 'cancelled']);
    }

    public static function new_customer($customer_id): void {
        if (self::settings()['customers'] !== '1') { return; }
        self::record('customer.created', (string) $customer_id);
    }

    public static function cart_updated(): void {
        $s = self::settings();
        if ($s['abandoned_cart'] !== '1' || !self::consent_allowed()) { return; }
        if (!function_exists('WC') || !WC() || !WC()->cart || !WC()->session) { return; }
        if (WC()->cart->is_empty()) { return; }
        $session_id = (string) WC()->session->get_customer_id();
        if ($session_id === '') { return; }
        // Avoid high-frequency writes. A cart is considered an abandoned
        // *candidate*, not an abandoned order, until a later review confirms it.
        $minute_bucket = floor(time() / ($s['cart_idle_minutes'] * 60));
        self::record('cart.observed', $session_id, ['revision' => (string) $minute_bucket]);
    }

    public static function admin_panel(): void {
        if (!current_user_can('manage_options')) { return; }
        $settings = self::settings();
        echo '<hr><h2>WooCommerce / CRM / Abandoned Checkouts</h2>';
        echo '<p><strong>Lab-safe observation only:</strong> event capture is local, bounded, and contains no customer identities, order IDs, cart contents or payment data. No outbound CRM writes or automated messages occur.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('cmb_commerce_save');
        echo '<input type="hidden" name="action" value="cmb_commerce_save"><table class="form-table"><tbody>';
        $fields = [
            'local_event_capture' => 'Capture anonymized local event history',
            'orders' => 'Order events',
            'customers' => 'Customer creation events (no PII)',
            'abandoned_cart' => 'Opt-in cart observation (requires verified explicit consent)',
        ];
        foreach ($fields as $key => $label) {
            echo '<tr><th>' . esc_html($label) . '</th><td><input type="checkbox" name="commerce[' .
                esc_attr($key) . ']" value="1" ' . checked($settings[$key], '1', false) .
                '></td></tr>';
        }
        echo '<tr><th>Cart observation interval (minutes)</th><td><input type="number" min="15" max="1440" name="commerce[cart_idle_minutes]" value="' . esc_attr((string) $settings['cart_idle_minutes']) . '"></td></tr>';
        echo '</tbody></table>';
        submit_button('Save WooCommerce observation settings');
        echo '</form>';
        $items = get_option(self::OUTBOX, []);
        echo '<h3>Local event outbox (' . esc_html((string) count(is_array($items) ? $items : [])) . ' / ' .
            esc_html((string) self::MAX_EVENTS) . ')</h3><p>No external delivery is enabled.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('cmb_commerce_clear');
        echo '<input type="hidden" name="action" value="cmb_commerce_clear">';
        submit_button('Clear local event history', 'secondary');
        echo '</form>';
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', 403); }
        check_admin_referer('cmb_commerce_save');
        $raw = isset($_POST['commerce']) && is_array($_POST['commerce']) ? wp_unslash($_POST['commerce']) : [];
        update_option(self::OPTION, self::sanitize($raw), false);
        wp_safe_redirect(admin_url('options-general.php?page=customer-messaging-bridge&cmb_notice=commerce_saved'));
        exit;
    }

    public static function clear(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', 403); }
        check_admin_referer('cmb_commerce_clear');
        delete_option(self::OUTBOX);
        wp_safe_redirect(admin_url('options-general.php?page=customer-messaging-bridge&cmb_notice=history_cleared'));
        exit;
    }
}
