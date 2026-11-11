<?php
/**
 * Plugin Name: MAD4B Checkout Core Dependency Order
 * Description: Preserve native WordPress core script dependency order on Woo checkout, while LiteSpeed Combine remains active elsewhere.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
add_filter('litespeed_optimize_js_excludes', static function ($patterns): array {
    $patterns = is_array($patterns) ? $patterns : [];
    if (is_admin() || !function_exists('is_checkout') || !is_checkout()) return $patterns;
    $registry = wp_scripts()->registered ?? [];
    $queue = ['lodash','underscore','wp-util','wp-api-fetch','wp-data'];
    $seen = [];
    while ($queue) {
        $handle = array_pop($queue);
        if (!is_string($handle) || isset($seen[$handle])) continue;
        $seen[$handle] = true;
        $obj = $registry[$handle] ?? null;
        if (!$obj) continue;
        foreach ((array)($obj->deps ?? []) as $dep) if(is_string($dep))$queue[]=$dep;
        $src = $obj->src ?? '';
        if (!is_string($src) || $src === '') continue;
        $path = parse_url($src, PHP_URL_PATH);
        if (is_string($path) && preg_match('~/(wp-includes/js)/~', $path)
            && !in_array($path, $patterns, true)) $patterns[]=$path;
    }
    return $patterns;
},10000);
