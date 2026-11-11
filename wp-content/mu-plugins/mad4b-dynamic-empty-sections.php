<?php
/**
 * Plugin Name: MAD4B Dynamic Empty Section Visibility
 * Description: Hide selected destination-related sections only after JetEngine reports a settled zero-result grid; restore when results return.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin() || (!is_tax('destinations') && !is_singular('tours-and-activities'))) return;
    $root = WPMU_PLUGIN_DIR . '/assets/';
    $base = content_url('mu-plugins/assets/');
    foreach (['css' => 'style', 'js' => 'script'] as $ext => $kind) {
        $name = 'mad4b-dynamic-empty-sections.' . $ext;
        $file = $root . $name;
        if (!is_readable($file)) continue;
        if ($kind === 'style') wp_enqueue_style('mad4b-dynamic-empty-sections', $base . $name, [], (string)filemtime($file));
        else wp_enqueue_script('mad4b-dynamic-empty-sections', $base . $name, [], (string)filemtime($file), true);
    }
}, 100);
