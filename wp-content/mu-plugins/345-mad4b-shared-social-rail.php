<?php
/**
 * Plugin Name: MAD4B Shared Social Rail
 * Description: Unified JetElements Dropbar + UAEL Social Share interaction across frontend pages and Elementor templates.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Shared_Social_Rail {
    public static function scope(): bool {
        // Safe first rollout on an explicitly authorized site; no Production mutation.
        // Runtime is reusable on any company-bound Staging site where this
        // MU plugin is installed. Production remains fail-closed until the
        // deployment policy explicitly authorizes that environment.
        if (wp_get_environment_type() !== 'staging') return false;
        $profile = get_option('mad4b_scp_brand_context_profile_v1', []);
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        return is_array($profile) && !empty($profile['site_uuid'])
            && preg_match('/^[a-z0-9.-]+$/iD', $host) === 1;
    }
    public static function frontend(): bool {
        return self::scope() && !is_admin() && !is_preview();
    }
    public static function assets(): void {
        if (!self::frontend()) return;
        $base = WP_CONTENT_DIR . '/mu-plugins/assets/';
        foreach (['css', 'js'] as $extension) {
            $file = $base . 'mad4b-shared-social-rail.' . $extension;
            if (!is_readable($file)) continue;
            $url = content_url('mu-plugins/assets/mad4b-shared-social-rail.' . $extension);
            if ($extension === 'css') {
                wp_enqueue_style('mad4b-shared-social-rail', $url, [], (string) filemtime($file));
            } else {
                wp_enqueue_script('mad4b-shared-social-rail', $url, [], (string) filemtime($file), ['in_footer' => true]);
            }
        }
    }
    public static function no_optimize(string $tag, string $handle, string $src): string {
        if (!self::frontend() || $handle !== 'mad4b-shared-social-rail') return $tag;
        return str_replace('id="mad4b-shared-social-rail-js"',
            'data-no-optimize="1" data-no-defer="1" data-cfasync="false" id="mad4b-shared-social-rail-js"',
            $tag);
    }
    public static function exclude($list) {
        if (!self::scope() || !is_array($list)) return $list;
        $list[] = 'mad4b-shared-social-rail.js';
        return array_values(array_unique($list));
    }
    public static function boot(): void {
        if (!self::scope()) return;
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 120);
        add_filter('script_loader_tag', [__CLASS__, 'no_optimize'], 560, 3);
        add_filter('litespeed_optimize_js_excludes', [__CLASS__, 'exclude'], 115);
    }
}
add_action('init', ['MAD4B_Shared_Social_Rail', 'boot'], 25);
