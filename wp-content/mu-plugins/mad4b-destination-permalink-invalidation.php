<?php
/**
 * Plugin Name: MAD4B Destination Permalink Invalidation
 * Description: Refresh localized archive links after destination edits.
 */
defined('ABSPATH') || exit;

add_action('edited_destinations', static function ($term_id) {
    if (!defined('WPML_Tax_Permalink_Filters::CACHE_GROUP')) {
        return;
    }
    $children = get_term_children($term_id, 'destinations');
    $ids = is_wp_error($children) ? [$term_id] : array_merge([$term_id], $children);
    foreach ($ids as $id) {
        foreach ([false, true] as $switcher) {
            $key = md5(json_encode([(int) $id, 'destinations', $switcher]));
            wp_cache_delete($key, WPML_Tax_Permalink_Filters::CACHE_GROUP);
        }
    }
}, 1);
