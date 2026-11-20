<?php
/**
 * Plugin Name: Wayfa — Eager Header Brand Assets
 * Version: 1.0.0
 * Description: Prevent LiteSpeed lazy placeholders on logo and WPML flags
 * above the fold while retaining lazy loading for all below-fold media.
 */
defined('ABSPATH') || exit;
add_filter('litespeed_media_lazy_img_excludes', static function ($patterns) {
    if (wp_parse_url(home_url('/'), PHP_URL_HOST) !== 'wayfatours.com') {
        return $patterns;
    }
    if (!is_array($patterns)) $patterns = (array) $patterns;
    $patterns[] = '/company-brands/wayfa/WAYFA-Full-01-Navy-Metallic';
    $patterns[] = '/sitepress-multilingual-cms/res/flags/';
    return array_values(array_unique($patterns));
}, 50);
