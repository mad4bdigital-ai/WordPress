<?php
/**
 * Plugin Name: MAD4B Feature 009 Portable Mobile Archive Filters
 * Description: Scope-limited mobile disclosure of the existing JetSmartFilters archive panel.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts', static function (): void {
    // Company-neutral: the archive and its native Elementor widgets own this UI.
    // A missing DFSB SEO profile must never disable the mobile filter disclosure.
    if (!is_post_type_archive('tours-and-activities')) { return; }
    $base = WP_CONTENT_DIR . '/mu-plugins/assets/feature009-mobile-filters/';
    $url = content_url('/mu-plugins/assets/feature009-mobile-filters/');
    $css = $base . 'mad4b-feature009-mobile-filters.css';
    $js = $base . 'mad4b-feature009-mobile-filters.js';
    if (is_readable($css)) {
        wp_enqueue_style('mad4b-feature009-mobile-archive-filters', $url . basename($css), [], (string)filemtime($css));
    }
    if (is_readable($js)) {
        wp_enqueue_script('mad4b-feature009-mobile-archive-filters', $url . basename($js), [], (string)filemtime($js), ['in_footer'=>true, 'strategy'=>'defer']);
    }
}, 110);
