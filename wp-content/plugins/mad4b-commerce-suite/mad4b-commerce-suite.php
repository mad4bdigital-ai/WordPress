<?php
/**
 * Plugin Name: MAD4B Commerce Suite
 * Description: Governed company module inventory and enrollment for existing WooCommerce commerce engines.
 * Version: 0.1.0-dev
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Text Domain: mad4b-commerce-suite
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/includes/module-registry.php';

final class MAD4B_Commerce_Suite {
    public const OPTION = 'mad4b_commerce_suite_desired_modules_v1';
    public const SLUG = 'mad4b-commerce-suite';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 30);
        add_action('admin_post_mad4b_commerce_suite_save', [__CLASS__, 'save']);
        add_action('rest_api_init', [__CLASS__, 'register_rest']);
    }

    public static function allowed(): bool {
        return current_user_can('manage_woocommerce');
    }

    public static function desired(): array {
        return MAD4B_Commerce_Suite_Registry::normalize_desired(get_option(self::OPTION, []));
    }

    public static function status(): array {
        $snapshot = MAD4B_Commerce_Suite_Registry::snapshot(self::desired());
        $snapshot['version'] = '0.1.0-dev';
        $snapshot['mode'] = 'source-only-inventory';
        $snapshot['site_environment'] = wp_get_environment_type();
        return $snapshot;
    }

    public static function register_rest(): void {
        register_rest_route('mad4b-commerce/v1', '/status', [
            'methods' => 'GET',
            'callback' => static fn() => rest_ensure_response(self::status()),
            'permission_callback' => [__CLASS__, 'allowed'],
        ]);
    }

    public static function admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            'MAD4B Commerce Suite',
            'Commerce Suite',
            'manage_woocommerce',
            self::SLUG,
            [__CLASS__, 'render']
        );
    }

    public static function save(): void {
        if (!self::allowed()) {
            wp_die(esc_html__('Permission denied.', 'mad4b-commerce-suite'), '', ['response' => 403]);
        }
        check_admin_referer('mad4b_commerce_suite_save');
        // A desired enrollment is NOT an activation and cannot bootstrap engines.
        $posted = isset($_POST['modules']) && is_array($_POST['modules'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['modules']))
            : [];
        update_option(self::OPTION, MAD4B_Commerce_Suite_Registry::normalize_desired($posted), false);
        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }

    public static function render(): void {
        if (!self::allowed()) {
            wp_die(esc_html__('Permission denied.', 'mad4b-commerce-suite'), '', ['response' => 403]);
        }
        $snapshot = self::status();
        echo '<div class="wrap"><h1>MAD4B Commerce Suite</h1>';
        echo '<p>Company enrollment inventory only. Saving does not activate, update or load any engine. Deployment requires Control Plane approval, compatibility certification and per-tenant readback.</p>';
        if (isset($_GET['updated']) && $_GET['updated'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Desired enrollment saved; no runtime changes made.</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="mad4b_commerce_suite_save">';
        wp_nonce_field('mad4b_commerce_suite_save');
        echo '<table class="widefat striped"><thead><tr><th scope="col">Desired</th><th scope="col">Module</th><th scope="col">Runtime</th><th scope="col">Dependencies</th><th scope="col">Owner</th></tr></thead><tbody>';
        foreach ($snapshot['modules'] as $id => $module) {
            $missing = $module['missing_dependencies'];
            echo '<tr><td><label><input type="checkbox" name="modules[]" value="' . esc_attr($id) . '"' . checked($module['desired'], true, false) . '> Enroll</label></td>';
            echo '<td><strong>' . esc_html($module['label']) . '</strong><p>' . esc_html($module['purpose']) . '</p></td>';
            echo '<td>' . ($module['runtime_detected'] ? 'Detected' : 'Not detected') . '</td>';
            echo '<td>' . esc_html($missing ? implode(', ', $missing) : 'None missing') . '</td>';
            echo '<td>' . esc_html($module['owner']) . '</td></tr>';
        }
        echo '</tbody></table>';
        submit_button('Save desired enrollment');
        echo '</form><p><strong>Note:</strong> No module activation is authorized by this page. Rates, orders and other company-owned data are not modified.</p></div>';
    }
}

MAD4B_Commerce_Suite::boot();
