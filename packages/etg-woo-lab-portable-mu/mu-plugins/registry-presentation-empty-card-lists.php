<?php
/**
 * Local Lab: suppress the empty nested destination listing inside Tour cards.
 * Do not suppress parent search results, native empty states or actual cards.
 * The legacy nested JetEngine query is 182; 48562 is its current listing item.
 */
if (!defined('ABSPATH')) return;
add_filter('elementor/widget/render_content', static function ($content, $widget) {
    if (!is_string($content) || !is_object($widget) ||
        !method_exists($widget, 'get_name') || $widget->get_name() !== 'jet-listing-grid') {
        return $content;
    }
    // Match this existing nested City/Destination Listing only, not unrelated
    // empty listings or whole sections that require their empty state.
    if (!str_contains($content, 'data-listing-id="48562"') ||
        !str_contains($content, 'jet-listing-not-found')) return $content;
    if (preg_match('~class=["\'][^"\']*\bjet-listing-grid__item\b~', $content)) return $content;
    return '';
}, 110, 2);
