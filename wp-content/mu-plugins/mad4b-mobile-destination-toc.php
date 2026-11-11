<?php
/**
 * Plugin Name: MAD4B Mobile Destination Contents
 * Description: Reuses the proven mobile Tour Contents presentation on the destination archive TOC, preserving Elementor anchors, desktop behavior and translations.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin() || !is_tax('destinations')) return;
    $root = WPMU_PLUGIN_DIR . '/assets/';
    $base = content_url('mu-plugins/assets/');
    $css = $root . 'mad4b-mobile-destination-toc.css';
    $js = $root . 'mad4b-mobile-destination-toc.js';
    if (is_readable($css)) wp_enqueue_style('mad4b-mobile-destination-toc', $base . basename($css), [], (string)filemtime($css));
    if (is_readable($js)) wp_enqueue_script('mad4b-mobile-destination-toc', $base . basename($js), [], (string)filemtime($js), true);
}, 95);
