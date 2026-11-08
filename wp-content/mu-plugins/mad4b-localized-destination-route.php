<?php
/**
 * Plugin Name: MAD4B Localized Destination Route Identity
 * Description: Resolve shared destination slugs by the current language and full public permalink.
 */
defined('ABSPATH') || exit;

add_filter('request', static function (array $vars): array {
    if (is_admin() || !isset($vars['destination']) || !is_string($vars['destination']) || isset($vars['tax_query'])) {
        return $vars;
    }
    $language = apply_filters('wpml_current_language', null);
    if (!$language || !has_filter('wpml_element_language_code')) {
        return $vars;
    }
    $slug = sanitize_title(basename(trim($vars['destination'], '/')));
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE t.slug=%s AND tt.taxonomy=%s",
        $slug, 'destinations'
    ));
    if (count($ids) < 2) {
        return $vars;
    }
    $path = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    foreach ($ids as $id) {
        $term = get_term((int) $id, 'destinations');
        if (!$term instanceof WP_Term) {
            continue;
        }
        $termLanguage = apply_filters('wpml_element_language_code', null, [
            'element_id' => $term->term_taxonomy_id,
            'element_type' => 'tax_destinations',
        ]);
        if ($termLanguage !== $language) {
            continue;
        }
        $url = get_term_link($term);
        if (is_wp_error($url) || rawurldecode((string) $path) !== rawurldecode((string) wp_parse_url($url, PHP_URL_PATH))) {
            continue;
        }
        unset($vars['destination']);
        $vars['tax_query'] = [['taxonomy' => 'destinations', 'field' => 'term_id', 'terms' => [$term->term_id]]];
        break;
    }
    return $vars;
}, 99);
