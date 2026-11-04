<?php
/**
 * Plugin Name: WooCommerce Destination Taxonomy Bridge (Lab)
 * Description: Registers the existing destinations taxonomy for WooCommerce products; no term or product data mutation.
 */
if (!defined('ABSPATH')) { exit; }
add_action('init', static function (): void {
    if (!taxonomy_exists('destinations') || !post_type_exists('product')) { return; }
    if (!is_object_in_taxonomy('product', 'destinations')) {
        register_taxonomy_for_object_type('destinations', 'product');
    }
}, 100);
