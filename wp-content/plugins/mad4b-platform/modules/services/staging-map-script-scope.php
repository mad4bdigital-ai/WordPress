<?php
/**
 * Staging performance experiment: do not load Jet Map-specific filter JS
 * on three verified public URLs without any maps or geodistance filters.
 * No plugin state, product, user, payment or pricing mutation.
 */
defined('ABSPATH') || exit;
add_filter('script_loader_tag', static function ($tag, $handle) {
    if (wp_get_environment_type() !== 'staging' || is_admin()
        || is_user_logged_in() || wp_doing_ajax() || is_preview()) {
        return $tag;
    }
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') return $tag;
    $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!in_array($path, ['/', '/ar/', '/tours-and-activities/'], true)) return $tag;
    $handles = [
        'jet-maps-listings-user-geolocation',
        'jet-maps-listings-location-distance',
        'jet-maps-listings-map-sync',
    ];
    return in_array($handle, $handles, true) ? '' : $tag;
}, 999, 2);
