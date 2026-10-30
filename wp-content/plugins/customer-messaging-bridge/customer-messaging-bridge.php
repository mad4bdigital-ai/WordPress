<?php
/**
 * Plugin Name: Customer Messaging Bridge
 * Description: Lab-safe Webchat and WooCommerce integration bridge for an external messaging platform.
 * Version: 0.4.1
 * Requires PHP: 8.0
 * Requires at least: 6.2
 * Author: MAD4B Digital
 * Text Domain: customer-messaging-bridge
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-cmb-channels.php';
require_once __DIR__ . '/includes/class-cmb-commerce.php';
require_once __DIR__ . '/includes/class-cmb-business-context.php';
require_once __DIR__ . '/includes/class-cmb-provider-gateway.php';
require_once __DIR__ . '/includes/class-cmb-webhooks.php';

final class Customer_Messaging_Bridge {
    public const OPTION = 'cmb_settings_v1';
    public const AUDIT = 'cmb_woocommerce_audit_v1';
    public const API_ORIGIN = 'https://chatbotx.mad4b.com';
    private static bool $rendered = false;

    public static function boot(): void {
        // Expose metadata to the WordPress Settings Registry for passive
        // Control Plane inventory; REST serialization remains OFF.
        add_action('admin_init', [self::class, 'register_settings_metadata']);
        add_action('admin_init', [self::class, 'business_settings_metadata']);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_cmb_save', [self::class, 'save']);
        add_action('admin_post_cmb_test', [self::class, 'test']);
        add_action('wp_footer', [self::class, 'render_footer'], 99);
        add_shortcode('customer_messaging_webchat', [self::class, 'shortcode']);
        CMB_Commerce::boot();
        CMB_Business_Context::boot();
        CMB_Webhooks::boot();
        add_action('cmb_admin_sections', [CMB_Channels::class, 'admin_panel'], 10);
        add_action('cmb_admin_sections', [CMB_Commerce::class, 'admin_panel'], 20);
        add_action('cmb_admin_sections', [CMB_Webhooks::class, 'admin_panel'], 30);
    }

    public static function settings(): array {
        return wp_parse_args(get_option(self::OPTION, []), [
            'origin' => self::API_ORIGIN,
            'workspace_id' => '',
            'webchat_id' => '',
            'brand_color' => '#2563eb',
            'enabled' => '0',
            'woo_local_audit' => '0',
        ]);
    }

    private static function valid_id($id): string {
        $id = trim((string) $id);
        return preg_match('/^[0-9]{1,20}$/D', $id) ? $id : '';
    }

    public static function sanitize(array $input): array {
        // The remote host is fixed by the integration contract to prevent
        // a WordPress admin setting becoming an SSRF or arbitrary JS source.
        $color = sanitize_text_field((string) ($input['brand_color'] ?? ''));
        return [
            'origin' => self::API_ORIGIN,
            'workspace_id' => self::valid_id($input['workspace_id'] ?? ''),
            'webchat_id' => self::valid_id($input['webchat_id'] ?? ''),
            'brand_color' => preg_match('/^#[0-9a-fA-F]{6}$/D', $color) ? strtolower($color) : '#2563eb',
            'enabled' => !empty($input['enabled']) ? '1' : '0',
            'woo_local_audit' => !empty($input['woo_local_audit']) ? '1' : '0',
        ];
    }

    public static function ready(): bool {
        $s = self::settings();
        return $s['enabled'] === '1' && self::valid_id($s['workspace_id']) !== ''
            && self::valid_id($s['webchat_id']) !== '';
    }

    public static function render_footer(): void {
        if (is_admin() || !self::ready() || self::$rendered) { return; }
        self::$rendered = true;
        $s = self::settings();
        $config = [
            'webchatId' => (string) $s['webchat_id'],
            'workspaceId' => (string) $s['workspace_id'],
            'brandColor' => (string) $s['brand_color'],
        ];
        // Matches the published ChatbotX embed contract. IDs are public
        // resource identifiers, not API tokens; no secrets enter HTML.
        $json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '<script type="module" async crossorigin="anonymous" src="' .
            esc_url(self::API_ORIGIN . '/chat-widget/plugin.js') .
            '" onload="window.csmChatWidget?.init(' . esc_attr($json) . ');"></script>' . "\n";
    }

    public static function shortcode(): string {
        // Rendering is centralized in wp_footer to prevent duplicate widget
        // bootstrap when Elementor/templates include the shortcode.
        if (!self::ready()) { return ''; }
        return '<span class="cmb-webchat-anchor" data-cmb-webchat="enabled" hidden></span>';
    }

    public static function register_settings_metadata(): void {
        register_setting('cmb_review', self::OPTION, [
            'type' => 'object',
            'show_in_rest' => false,
            'sanitize_callback' => static function ($v) {
                return self::sanitize(is_array($v) ? $v : []);
            },
            'default' => [],
            'description' => 'Lab ChatbotX bridge non-secret connection preferences.',
        ]);
        register_setting('cmb_review', CMB_Commerce::OPTION, [
            'type' => 'object',
            'show_in_rest' => false,
            'sanitize_callback' => static function ($v) {
                return CMB_Commerce::sanitize(is_array($v) ? $v : []);
            },
            'default' => [],
            'description' => 'Lab-only privacy-minimal WooCommerce event observation settings.',
        ]);
    }

    public static function business_settings_metadata(): void {
        register_setting('cmb_review', CMB_Business_Context::OPTION, [
            'type' => 'object', 'show_in_rest' => false,
            'sanitize_callback' => static function ($v) {
                return CMB_Business_Context::sanitize(is_array($v) ? $v : []);
            },
            'default' => [],
            'description' => 'Lab-only Fluent Forms and Fluent Support event observation controls.',
        ]);
    }

    public static function admin_menu(): void {
        add_options_page('Customer Messaging Bridge', 'Customer Messaging', 'manage_options',
            'customer-messaging-bridge', [self::class, 'admin_page']);
    }

    public static function admin_page(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        $s = self::settings();
        $notice = isset($_GET['cmb_notice']) ? sanitize_key(wp_unslash($_GET['cmb_notice'])) : '';
        echo '<div class="wrap"><h1>Customer Messaging Bridge</h1>';
        echo '<p>ETG WooCommerce Lab: connect the existing messaging workspace to this local WordPress website. No customer or order data is transmitted by this plugin.</p>';
        if ($notice) {
            echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('cmb_save');
        echo '<input type="hidden" name="action" value="cmb_save"><table class="form-table"><tbody>';
        self::input_row('ChatbotX origin', 'origin', self::API_ORIGIN, true);
        self::input_row('Workspace ID', 'workspace_id', $s['workspace_id']);
        self::input_row('Webchat ID', 'webchat_id', $s['webchat_id']);
        self::input_row('Brand color (#RRGGBB)', 'brand_color', $s['brand_color']);
        self::check_row('Enable public Webchat widget', 'enabled', $s['enabled'] === '1');
        echo '<tr><th>Commerce integration</th><td>Configured in the WooCommerce panel below (no customer or order data leaves WordPress).</td></tr>';
        echo '</tbody></table>';
        submit_button('Save messaging settings');
        echo '</form><hr>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('cmb_test');
        echo '<input type="hidden" name="action" value="cmb_test">';
        submit_button('Test ChatbotX API health', 'secondary');
        echo '</form><p>Widget status: <strong>' .
            esc_html(self::ready() ? 'Enabled and configured' : 'Disabled / awaiting Workspace & Webchat IDs') .
            '</strong></p>';
        echo '<p>WooCommerce: ' . esc_html(class_exists('WooCommerce') ? 'Detected' : 'Not detected') .
            '. Outbound order/CRM synchronization is intentionally not active.</p>';
        $events = get_option(self::AUDIT, []);
        echo '<p>Archived v0.1 WooCommerce status observations: ' . esc_html((string) count(is_array($events) ? $events : [])) . ' (up to 30 retained; no customer information).</p>';
        do_action('cmb_admin_sections');
        echo '</div>';
    }

    private static function input_row(string $label, string $key, string $value, bool $readonly = false): void {
        echo '<tr><th><label for="cmb-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input class="regular-text" type="text" id="cmb-' . esc_attr($key) .
            '" name="cmb[' . esc_attr($key) . ']" value="' . esc_attr($value) .
            '"' . ($readonly ? ' readonly' : '') . '></td></tr>';
    }

    private static function check_row(string $label, string $key, bool $checked): void {
        echo '<tr><th>' . esc_html($label) . '</th><td><label><input type="checkbox" name="cmb[' .
            esc_attr($key) . ']" value="1" ' . checked($checked, true, false) . '> Enabled</label></td></tr>';
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', 403); }
        check_admin_referer('cmb_save');
        $raw = isset($_POST['cmb']) && is_array($_POST['cmb']) ? wp_unslash($_POST['cmb']) : [];
        update_option(self::OPTION, self::sanitize($raw), false);
        wp_safe_redirect(add_query_arg('cmb_notice', 'saved', admin_url('options-general.php?page=customer-messaging-bridge')));
        exit;
    }

    public static function test(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', 403); }
        check_admin_referer('cmb_test');
        $response = wp_remote_get(self::API_ORIGIN . '/api/health', [
            'timeout' => 10, 'redirection' => 0, 'sslverify' => true,
            'headers' => ['Accept' => 'application/json'],
        ]);
        $pass = !is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response);
        wp_safe_redirect(add_query_arg('cmb_notice', $pass ? 'chatbotx_healthy' : 'chatbotx_health_unavailable',
            admin_url('options-general.php?page=customer-messaging-bridge')));
        exit;
    }


}
Customer_Messaging_Bridge::boot();
