<?php
defined('ABSPATH') || exit;

/**
 * Safe presentational adapter for three confirmed MAD4B-owned Settings API
 * pages; no submit interception, DOM replacement, global wp-admin skin,
 * or option mutation. Any non-allowlisted page stays native.
 */
final class MAD4B_Native_WP_Settings_Adapter_V1 {
    private const ALLOWLIST = [
        'mad4b-visitor-experience',
        'mad4b-presentation-experience',
        'mad4b-site-shell',
    ];

    private static function slug(): string {
        $raw = $_GET['page'] ?? '';
        return is_string($raw) ? sanitize_key($raw) : '';
    }
    private static function eligible(): bool {
        return is_admin() && in_array(self::slug(), self::ALLOWLIST, true)
            && current_user_can('manage_options');
    }
    public static function boot(): void {
        if (!is_admin()) return;
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_filter('admin_body_class', [__CLASS__, 'body_class']);
    }
    public static function enqueue(): void {
        if (!self::eligible()) return;
        $file=__DIR__.'/assets/native-wp-settings-adapter.css';
        wp_enqueue_style('mad4b-native-wp-settings-adapter',
            plugins_url('mad4b-jetengine/modules/functional-modes/assets/native-wp-settings-adapter.css'),
            ['forms','common'], (string)filemtime($file));
    }
    public static function body_class(string $classes): string {
        return self::eligible() ? $classes . ' mad4b-native-wp-settings-optin' : $classes;
    }
    public static function allowlisted_pages(): array {
        return self::ALLOWLIST;
    }
}
MAD4B_Native_WP_Settings_Adapter_V1::boot();
