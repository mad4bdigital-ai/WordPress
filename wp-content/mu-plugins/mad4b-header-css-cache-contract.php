<?php
/**
 * Plugin Name: MAD4B Header CSS Cache Contract
 * Description: Keep the native Elementor header preferences CSS on its own file-versioned asset while retaining LiteSpeed Combine elsewhere.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) { exit; }

add_filter('litespeed_optimize_css_excludes', static function ($items) {
    $items = is_array($items) ? $items : [];
    if (!in_array('header-context-controls.css', $items, true)) {
        $items[] = 'header-context-controls.css';
    }
    return $items;
}, 20);

add_filter('style_loader_tag', static function ($tag, $handle) {
    if ($handle !== 'company-header-context-controls' || str_contains($tag, 'data-no-optimize')) {
        return $tag;
    }
    // Some asset optimizers strip the WordPress ?ver= parameter. Restore a
    // site-local filemtime key after style URL processing so browser cache
    // revalidation is deterministic on each future header CSS change.
    $css_path = WPMU_PLUGIN_DIR . '/assets/header-context-controls.css';
    if (is_file($css_path)) {
        $stamp = (int) filemtime($css_path);
        $tag = preg_replace('~(header-context-controls\.css)(?=[\x22\x27\s>])~', '$1?mad4b_asset_v=' . $stamp, $tag, 1) ?: $tag;
    }
    return preg_replace('/^<link\b/i', '<link data-no-optimize="1"', $tag, 1) ?: $tag;
}, 10, 2);
