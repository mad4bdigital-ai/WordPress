<?php
/**
 * Plugin Name: MAD4B – Archive Async Asset Epoch
 * Description: Version the DFSB AJAX state script by its live file revision, independently of the DFSB plugin build ID.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin()) return;
    $handle = 'etg-dfsb-ajax-filter-state';
    $scripts = wp_scripts();
    if (!isset($scripts->registered[$handle]) || !wp_script_is($handle, 'enqueued')) return;
    $file = WP_PLUGIN_DIR . '/dynamic-filter-seo-bridge/assets/js/ajax-filter-state.js';
    if (!is_file($file)) return;
    $mtime = filemtime($file);
    if (!is_int($mtime) || $mtime <= 0) return;
    $scripts->registered[$handle]->ver = (string) $mtime;
}, 999);

/**
 * LiteSpeed optimized JS is immutable and may outlive the WordPress script
 * revision. Exclude only these two stateful archive runtimes from generated
 * bundles; keep every other script eligible for the configured optimizer.
 * Their native URLs retain WordPress's file-revision version query.
 */
add_filter('litespeed_optimize_js_excludes', static function ($excludes): array {
    $excludes = is_array($excludes) ? $excludes : [];
    foreach ([
        'dynamic-filter-seo-bridge/assets/js/ajax-filter-state.js',
        'mu-plugins/assets/mad4b-taxonomy-faq-runtime.js',
    ] as $path) {
        if (!in_array($path, $excludes, true)) $excludes[] = $path;
    }
    return $excludes;
}, 20);

/**
 * LiteSpeed's query-string removal runs at script_loader_src priority 999.
 * Add a content hash *after* that filter, so the two excluded native files
 * receive a new browser-cache key whenever their content changes.
 * This is limited to these scripts; every other asset retains site policy.
 */
add_filter('script_loader_src', static function ($src, $handle) {
    $paths = [
        'etg-dfsb-ajax-filter-state' => WP_PLUGIN_DIR . '/dynamic-filter-seo-bridge/assets/js/ajax-filter-state.js',
        'mad4b-taxonomy-faq-runtime' => WP_CONTENT_DIR . '/mu-plugins/assets/mad4b-taxonomy-faq-runtime.js',
    ];
    if (!isset($paths[$handle]) || !is_string($src)) return $src;
    $file = $paths[$handle];
    if (!is_file($file)) return $src;
    $fingerprint = hash_file('sha256', $file);
    if (!is_string($fingerprint) || $fingerprint === '') return $src;
    return add_query_arg('mad4b_asset', substr($fingerprint, 0, 12), $src);
}, 1001, 2);
