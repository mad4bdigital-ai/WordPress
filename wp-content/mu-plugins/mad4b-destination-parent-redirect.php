<?php
/**
 * Plugin Name: MAD4B Destination Parent Redirect
 * Description: Redirect a plain destination archive with obsolete ancestors to its localized permalink.
 */
defined('ABSPATH') || exit;

add_action('wp', static function () {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true) || !is_tax('destinations') || is_feed() || is_paged()) {
        return;
    }
    $uri = wp_unslash($_SERVER['REQUEST_URI'] ?? '');
    if (str_contains($uri, '?')) {
        return;
    }
    $term = get_queried_object();
    $url = get_term_link($term);
    if (is_wp_error($url)) {
        return;
    }
    $parts = static function ($path) {
        return array_map('rawurldecode', explode('/', trim($path, '/')));
    };
    $requested = $parts($uri);
    $canonical = $parts((string) wp_parse_url($url, PHP_URL_PATH));
    $locale = apply_filters('wpml_current_language', null);
    $prefix = $canonical[0] === $locale ? 2 : 1;
    if ($requested !== $canonical && end($requested) === end($canonical)
        && array_slice($requested, 0, $prefix) === array_slice($canonical, 0, $prefix)
        && wp_safe_redirect($url, 302, 'MAD4B Destination')) {
        exit;
    }
}, 0);
