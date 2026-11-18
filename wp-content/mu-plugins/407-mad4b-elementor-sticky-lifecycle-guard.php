<?php
/**
 * Plugin Name: MAD4B Tour Sticky Lifecycle Guard
 * Description: Selective Elementor Sticky lifecycle compatibility for tour templates.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/**
 * Match on behavior/source bytes, never Elementor's version number.
 * Unknown future implementations fall back to upstream unmodified.
 */
function mad4b_tour_sticky_guard_asset(): array {
    static $result = null;
    if ($result !== null) return $result;
    $result = [];
    if (is_admin() || !is_singular('tours-and-activities')) return $result;
    $vendor = WP_PLUGIN_DIR . '/elementor-pro/assets/lib/sticky/jquery.sticky.js';
    $verifiedVendor = '02c0d6068d4dbfb5c047c145d32a1f6965c402006f21fcd6f6adfd6c0aea7c29';
    if (!is_readable($vendor) ||
        !hash_equals($verifiedVendor, (string)hash_file('sha256', $vendor))) return $result;
    $files = glob(WPMU_PLUGIN_DIR . '/assets/mad4b-elementor-sticky-lifecycle-*.js');
    if (!is_array($files) || count($files) !== 1) return $result;
    $file = $files[0];
    if (!is_file($file) || !is_readable($file)) return $result;
    $base = basename($file);
    if (!preg_match('/^mad4b-elementor-sticky-lifecycle-([a-f0-9]{16})\.js$/', $base, $match))
        return $result;
    $digest = (string)hash_file('sha256', $file);
    if (!hash_equals($match[1], substr($digest, 0, 16))) return $result;
    return $result = [
        'url' => content_url('mu-plugins/assets/' . $base),
        'version' => substr($digest, 0, 16),
    ];
}

function mad4b_tour_sticky_guard_register(): void {
    $asset = mad4b_tour_sticky_guard_asset();
    if (!$asset) return;
    $scripts = wp_scripts();
    foreach (['e-sticky', 'elementor-sticky'] as $handle) {
        if (!isset($scripts->registered[$handle])) continue;
        $scripts->registered[$handle]->src = $asset['url'];
        $scripts->registered[$handle]->ver = $asset['version'];
    }
    // Elementor may load e-sticky lazily from this separate asset catalog.
    if (class_exists('\Elementor\Plugin')
        && \Elementor\Plugin::$instance
        && isset(\Elementor\Plugin::$instance->assets_loader)
        && \Elementor\Plugin::$instance->assets_loader) {
        \Elementor\Plugin::$instance->assets_loader->add_assets([
            'scripts' => ['e-sticky' => [
                'src' => $asset['url'],
                'version' => $asset['version'],
                'dependencies' => ['jquery'],
            ]],
        ]);
    }
}
add_action('elementor/frontend/before_register_scripts', 'mad4b_tour_sticky_guard_register', 999);
add_action('wp_enqueue_scripts', 'mad4b_tour_sticky_guard_register', 999);
add_action('wp_print_scripts', 'mad4b_tour_sticky_guard_register', 999);
add_filter('script_loader_src', static function($src, $handle) {
    if (!in_array($handle, ['e-sticky','elementor-sticky'], true)) return $src;
    $asset = mad4b_tour_sticky_guard_asset();
    return $asset ? add_query_arg('ver', $asset['version'], $asset['url']) : $src;
}, 999, 2);
