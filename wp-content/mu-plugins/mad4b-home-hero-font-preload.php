<?php
/**
 * Plugin Name: MAD4B Homepage Hero Font Preload
 * Description: Discover the verified English and Spanish hero font before the combined stylesheet.
 */
defined('ABSPATH') || exit;

add_filter('wp_preload_resources', static function (array $resources): array {
    if (!is_front_page() || !in_array(substr(get_locale(), 0, 2), ['en', 'es'], true)) {
        return $resources;
    }
    $uploads = wp_get_upload_dir();
    $file = '/elementor/google-fonts/fonts/nunito-ff573f56.woff2';
    if (is_readable($uploads['basedir'] . $file)) {
        $resources[] = [
            'href' => $uploads['baseurl'] . $file,
            'as' => 'font',
            'type' => 'font/woff2',
            'crossorigin' => 'anonymous',
        ];
    }
    return $resources;
});
