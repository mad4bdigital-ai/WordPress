<?php
/**
 * Plugin Name: MAD4B Destination Filter First Root
 * Description: Preserve native JetSmartFilters hierarchy; keep first root expanded in tour archives.
 */
if (!defined('ABSPATH')) { exit; }
add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin() || !is_post_type_archive('tours-and-activities')) return;
    $path=__DIR__.'/assets/mad4b-destination-first-root-expanded.js';
    if (!is_file($path)) return;
    wp_enqueue_script(
       'mad4b-destination-first-root-expanded',
       content_url('mu-plugins/assets/mad4b-destination-first-root-expanded.js'),
       array(), (string)filemtime($path), true
    );
}, 98);
