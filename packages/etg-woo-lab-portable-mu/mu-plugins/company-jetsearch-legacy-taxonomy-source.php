<?php
/**
 * Local Lab legacy content compatibility for JetSearch taxonomy controls.
 * Historical Elementor revisions refer to deleted taxonomy `tour-types_jet`.
 * Published template source uses registered canonical `tour-types`.
 * Prevent invalid-taxonomy WP_Error being fed to wp_dropdown_categories walker.
 * Do not rewrite saved revision history or modify WordPress/JetSearch vendor files.
 */
defined('ABSPATH') || exit;

add_filter('jet-search/ajax-search/categories-select/args', static function($args) {
    if (!is_array($args) || ($args['taxonomy'] ?? null) !== 'tour-types_jet') return $args;
    if (taxonomy_exists('tour-types_jet') || !taxonomy_exists('tour-types')) return $args;
    $args['taxonomy'] = 'tour-types';
    return $args;
}, 20);
