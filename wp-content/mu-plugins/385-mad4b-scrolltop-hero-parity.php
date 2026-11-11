<?php
/**
 * Plugin Name: MAD4B – Sitewide Scroll-to-Top & Archive Hero Overlay Parity
 * Description: Keeps Astra's native scroll button on the physical left and preserves the Elementor hero overlay above dynamic background slides.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

add_action('wp_enqueue_scripts',static function (): void {
    if (is_admin()) return;
    $relative='assets/mad4b-scrolltop-hero-parity.css';
    $file=__DIR__.'/'.$relative;
    if (!is_readable($file)) return;
    wp_enqueue_style(
        'mad4b-scrolltop-hero-parity',
        content_url('mu-plugins/'.$relative),
        [],
        (string)filemtime($file),
        'all'
    );
}, 999);
