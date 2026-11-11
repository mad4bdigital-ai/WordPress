<?php
/** MAD4B source-native clickable visual card styling for Elementor/JetEngine. */
if (!defined('ABSPATH')) exit;
add_action('wp_enqueue_scripts', static function ():void {
    if (is_admin() || !is_front_page()) return;
    $css=__DIR__.'/assets/company-listing-card-actions.css';
    if (!is_readable($css)) return;
    wp_enqueue_style('mad4b-company-listing-card-actions',
        plugins_url('assets/company-listing-card-actions.css',__FILE__),[],(string)filemtime($css));
},125);
