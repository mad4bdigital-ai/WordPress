<?php
/**
 * Plugin Name: MAD4B Tour Share Dropbar First Paint
 * Description: Keep the closed Jet Dropbar from reserving hero space before its JS initializes. Open panels retain native behavior.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) exit;
add_action('wp_head',static function () {
 if (!is_singular('tours-and-activities')) return;
 echo "\n<style id=\"mad4b-tour-share-first-paint\">\n";
 echo 'body.single-tours-and-activities .elementor-element-3c17f4e .jet-dropbar:not(.jet-dropbar-open) .jet-dropbar__content-wrapper{display:none!important;visibility:hidden!important}';
 echo "\n</style>\n";
},0);
