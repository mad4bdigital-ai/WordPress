<?php
/**
 * Plugin Name: MAD4B Tour Woo Product Gallery Asset Admission
 * Description: Avoid loading Woo single-product photo gallery JS on regular Tour presentations that have no Woo product gallery.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) { exit; }
add_action('wp_enqueue_scripts', static function(): void {
    if (is_admin() || wp_doing_ajax() || is_preview()
        || (defined('REST_REQUEST') && REST_REQUEST)
        || (defined('WP_CLI') && WP_CLI)
        || (string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        || !is_singular('tours-and-activities')) { return; }
    if (class_exists('\Elementor\Plugin')
        && isset(\Elementor\Plugin::$instance->editor)
        && \Elementor\Plugin::$instance->editor->is_edit_mode()) { return; }
    $tour_id = absint(get_queried_object_id());
    $tour = $tour_id ? get_post($tour_id) : null;
    if (!$tour || $tour->post_status !== 'publish') { return; }

    // Real Woo product galleries, nested product pages or explicit company
    // exceptions keep their normal scripts. Never affect product pages.
    $own_content = (string)$tour->post_content . (string)get_post_meta($tour_id, '_elementor_data', true);
    if (preg_match('/(?:\[product_page|\[products|woocommerce-product-gallery|woocommerce-product-images|jet-woo-product-gallery)/i', $own_content)
        || (bool)apply_filters('mad4b/tour/needs_woocommerce_product_gallery', false, $tour_id)) { return; }

    // Existing shared Tour templates are inspected as data, not modified.
    // Fail safe if a known gallery widget or product shortcode is introduced.
    foreach ([24784, 48366, 48317] as $template_id) {
        $raw = (string)get_post_meta($template_id, '_elementor_data', true);
        if ($raw === '' || preg_match('/(?:\[product_page|\[products|woocommerce-product-gallery|woocommerce-product-images|jet-woo-product-gallery)/i', $raw)) { return; }
    }

    // Tour Gallery uses Elementor/JetEngine popups, not Woo single-product
    // gallery. Keep Woo core, cart, checkout, order attribution and Fluent Forms.
    foreach (['wc-single-product','wc-zoom','wc-flexslider','wc-photoswipe','wc-photoswipe-ui-default'] as $handle) {
        wp_dequeue_script($handle);
    }
}, 9999);
