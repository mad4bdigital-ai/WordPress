<?php
/**
 * Plugin Name: MAD4B Central About Page Presentation
 * Description: Reuses Company Brand / Elementor kit typography and colour tokens on the Gutenberg About page.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'staging' || rtrim(home_url(),'/') !== 'https://tours.dev.mad4b.com') return;
add_action('wp_enqueue_scripts',static function():void {
    if(!is_page(68813)) return;
    $file=WPMU_PLUGIN_DIR.'/assets/mad4b-central-about-page.css';
    if(!is_file($file))return;
    wp_enqueue_style('mad4b-central-about-page',content_url('mu-plugins/assets/mad4b-central-about-page.css'),[],(string)filemtime($file));
},90);
