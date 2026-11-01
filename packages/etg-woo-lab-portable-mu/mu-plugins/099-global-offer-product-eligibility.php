<?php
/**
 * Global Offer per-item exclusion contract — isolated to local WooCommerce Lab.
 * Product provenance: integrated CPTs; no standalone WooCommerce product required.
 */
if (!defined('ABSPATH')) return;

function global_offer_integrated_types() {
    return ['tours-and-activities', 'properties', 'transportations', 'travel-visa'];
}

function global_offer_excluded($product) {
    $post = $product instanceof WP_Post ? $product : get_post((int) $product);
    if (!$post || !in_array($post->post_type, global_offer_integrated_types(), true)) return false;
    $value = get_post_meta($post->ID, 'exclude_from_global_offer', true);
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

function global_offer_product_eligible($product) {
    $post = $product instanceof WP_Post ? $product : get_post((int) $product);
    if (!$post || !in_array($post->post_type, global_offer_integrated_types(), true)) return false;
    if ($post->post_status !== 'publish' || global_offer_excluded($post)) return false;
    if (function_exists('service_visibility_active')) {
        $service = ['tours-and-activities'=>'tours', 'properties'=>'properties', 'transportations'=>'transportations', 'travel-visa'=>'visas'][$post->post_type];
        if (!service_visibility_active($service)) return false;
    }
    return (bool) apply_filters('global_offer/product_eligible', true, $post);
}
