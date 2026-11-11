<?php
/**
 * Plugin Name: MAD4B Rates — Context-Aware Elementor Render Cache
 * Description: Never reuse an Elementor document cache prepared in another tour/rate context.
 * Version: 1.1.1
 *
 * The Elementor documents here contain JetEngine's live Query 310/313,
 * company-level visibility and per-tour Accordion/Grid Listing policy.
 * Cached document trees can be written while the query has no items, which
 * silently strips the 17 children of rate container 8ab1e90 for other tours.
 * Keep Element Cache enabled everywhere else.
 */
defined('ABSPATH') || exit;

add_filter('pre_option_elementor_element_cache_ttl', static function ($pre) {
    if (!class_exists('\Elementor\Plugin')
        || !isset(\Elementor\Plugin::$instance)
        || !is_object(\Elementor\Plugin::$instance->documents)) {
        return $pre;
    }
    $document = \Elementor\Plugin::$instance->documents->get_current();
    if (!is_object($document) || !method_exists($document, 'get_id')) {
        return $pre;
    }

    // Native Elementor documents used by rates and the WPML-linked variants.
    // IDs grounded in current JetEngine/Elementor document and WPML inventory.
    static $rate_docs = [
        48317 => true, 48359 => true, 56054 => true,
        46193 => true, 46557 => true,
        69043 => true, 69044 => true, 69045 => true, 69046 => true,
        56197 => true,
    ];

    return isset($rate_docs[(int) $document->get_id()]) ? 'disable' : $pre;
}, 30);

/**
 * Bust only Elementor rate-template CSS whenever Elementor regenerates it.
 * Browser caches otherwise retain the old incomplete rate-card rules under
 * the unchanged post-*.css URL.
 */
add_filter('style_loader_src', static function ($src, $handle) {
    static $rate_css = [
        'elementor-post-48317' => 48317,
        'elementor-post-48359' => 48359,
        'elementor-post-56054' => 56054,
        'elementor-post-46193' => 46193,
        'elementor-post-46557' => 46557,
        'elementor-post-69043' => 69043,
        'elementor-post-69044' => 69044,
        'elementor-post-69045' => 69045,
        'elementor-post-69046' => 69046,
        'elementor-post-56197' => 56197,
    ];
    if (!isset($rate_css[$handle]) || !is_string($src)) {
        return $src;
    }
    $file = WP_CONTENT_DIR . '/uploads/elementor/css/post-' . $rate_css[$handle] . '.css';
    if (!is_file($file)) {
        return $src;
    }
    $modified = filemtime($file);
    return $modified ? add_query_arg('mad4b_rate_css', (string) $modified, $src) : $src;
}, 2002, 2);
