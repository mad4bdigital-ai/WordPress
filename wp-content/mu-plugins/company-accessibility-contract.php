<?php
/** Shared accessibility contracts — ETG isolated Local Lab. */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)) return;

// Each WPML instance needs its own ARIA target, even when a slot is rendered
// more than once in Elementor headers, sticky headers or popups.
add_filter('wpml_ls_html', static function ($html) {
    if (!is_string($html) || $html === '') return $html;
    static $seen = [];
    if (!preg_match_all('/\bid="(wpml-ls-submenu-[A-Za-z0-9_-]+)"/', $html, $matches)) return $html;
    foreach (array_unique($matches[1]) as $id) {
        $seen[$id] = ($seen[$id] ?? 0) + 1;
        if ($seen[$id] === 1) continue;
        $new_id = $id . '-instance-' . $seen[$id];
        $html = str_replace('id="' . $id . '"', 'id="' . $new_id . '"', $html);
        $html = str_replace('aria-controls="' . $id . '"', 'aria-controls="' . $new_id . '"', $html);
    }
    return $html;
}, 200, 1);

// Use the owning site's canonical Address field, not a branded domain.
// Older WPML widgets may serialize an HTTP asset URL on HTTPS origins.
$local_public_asset_scheme = static function ($url) {
    if (!is_string($url) || $url === '') return $url;
    $origin = wp_parse_url(home_url('/'));
    $resource = wp_parse_url($url);
    if (!is_array($origin) || !is_array($resource)
        || ($origin['scheme'] ?? '') !== 'https'
        || ($resource['scheme'] ?? '') !== 'http'
        || strtolower((string)($resource['host'] ?? '')) !== strtolower((string)($origin['host'] ?? ''))
        || isset($resource['port'])) return $url;
    return 'https://' . substr($url, strlen('http://'));
};
add_filter('script_loader_src', $local_public_asset_scheme, 999);
add_filter('style_loader_src', $local_public_asset_scheme, 999);

add_action('wp_enqueue_scripts', static function () {
    if (is_admin()) return;
    $base = content_url('/mu-plugins/assets/');
    wp_enqueue_style('mad4b-local-a11y', $base . 'accessibility-contract.css', [], '20261028-a11y-12');
    wp_enqueue_script('mad4b-local-gallery-a11y', $base . 'accessibility-gallery.js', [], '20261028-a11y-12', true);
    wp_enqueue_script('mad4b-local-listing-a11y', $base . 'accessibility-listing.js', [], '20261031-a11y-layout-14', true);
    wp_enqueue_script('mad4b-local-swiper-a11y', $base . 'accessibility-swiper.js', [], '20261031-a11y-layout-14', true);
    wp_enqueue_script('mad4b-local-archive-a11y', $base . 'accessibility-archive.js', [], '20261028-a11y-12', true);
}, 100);
