<?php
/**
 * Plugin Name: MAD4B WooCommerce Public Shop Visibility
 * Description: Tenant-local switch for hiding public WooCommerce catalog routes without disabling commerce APIs, quotes or bookings.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;

// Portable, tenant-local policy: public storefront is closed unless enabled explicitly.
add_action('template_redirect', static function () {
    if ((string) get_option('mad4b_woocommerce_shop_public_v1', '0') !== '0') return;
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
    if (!function_exists('is_shop')) return;
    // Preserve direct products, API endpoints, bookings, cart and checkout.
    if (!is_shop() && !is_post_type_archive('product')
        && !is_tax(['product_cat', 'product_tag'])) return;
    global $wp_query;
    if ($wp_query instanceof WP_Query) $wp_query->set_404();
    status_header(404);
    nocache_headers();
    $template = get_404_template();
    if (is_string($template) && is_readable($template)) {
        include $template;
    } else {
        echo 'Page not found';
    }
    exit;
}, 0);
