<?php
/**
 * Plugin Name: MAD4B — FAQ Elementor Brand Kit Skin
 * Description: One shared FAQ skin for JetEngine/Nested and JetElements/Classic accordion, inherited from the site's active Elementor Global Kit.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts', static function (): void {
    // This stylesheet uses native Elementor Global Kit tokens, but is safe
    // to enqueue before Elementor initializes because every selector is scoped.
    $file = __DIR__ . '/assets/mad4b-faq-brand-kit.css';
    if (!is_file($file) || !is_readable($file)) {
        return;
    }
    wp_enqueue_style(
        'mad4b-faq-elementor-brand-kit',
        content_url('mu-plugins/assets/mad4b-faq-brand-kit.css'),
        [],
        (string) filemtime($file)
    );
}, 120);
