<?php
/**
 * Plugin Name: MAD4B Native Navigation Signed Lazy Listings
 * Description: Supply JetEngine's canonical, server-signed lazy listing settings for Elementor navigation widgets.
 * Version: 1.2.1
 */
defined('ABSPATH') || exit;

add_filter('jet-engine/listing/grid/lazy-load/options', static function ($options, $settings) {
    if (!is_array($settings) || !is_array($options) || !function_exists('jet_engine')) return $options;
    $classes = preg_split('/\s+/', trim((string) ($settings['_css_classes'] ?? '')));
    if (!in_array('navigation-editor-grid', $classes, true)) return $options;
    $handler = jet_engine()->listings->ajax_handlers ?? null;
    if (!$handler || !method_exists($handler, 'maybe_add_settings_signature')) return $options;
    $signed = $handler->maybe_add_settings_signature($settings);
    if (!is_array($signed) || empty($signed['_jet_engine_settings_signature'])) return $options;
    // Only replay exact server-rendered settings with the identical JetEngine HMAC.
    if (!hash_equals((string) ($options['settings_signature'] ?? ''), (string) $signed['_jet_engine_settings_signature'])) return $options;
    // The original signature normalizer removes empty arrays recursively.
    // Ship only those settings that jQuery would actually serialize, but keep
    // the full payload unless JetEngine itself confirms the HMAC is unchanged.
    $strip_empty_arrays = static function (array $data) use (&$strip_empty_arrays): array {
        foreach ($data as $key => $value) {
            if (!is_array($value)) continue;
            $value = $strip_empty_arrays($value);
            if ($value === []) unset($data[$key]);
            else $data[$key] = $value;
        }
        return $data;
    };
    $compact = $strip_empty_arrays($signed);
    $resigned = $handler->maybe_add_settings_signature($compact);
    if (is_array($resigned) && hash_equals(
        (string) $signed['_jet_engine_settings_signature'],
        (string) ($resigned['_jet_engine_settings_signature'] ?? '')
    )) {
        $signed = $compact;
    }
    $options['signed_widget_settings'] = $signed;
    return $options;
}, 20, 2);

add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin()) return;
    $file = __DIR__ . '/assets/mad4b-navigation-signed-lazy-listings.20261106.js';
    if (!is_readable($file)) return;
    wp_enqueue_script('mad4b-navigation-signed-lazy-listings',
        content_url('mu-plugins/assets/mad4b-navigation-signed-lazy-listings.20261106.js'),
        [], (string) filemtime($file), false);
}, 15);

add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin()) return;
    $name = 'mad4b-navigation-context-layer.20261106.css';
    $path = __DIR__ . '/assets/' . $name;
    if (is_readable($path)) wp_enqueue_style('mad4b-navigation-context-layer',
        content_url('mu-plugins/assets/' . $name), [], (string) filemtime($path));
}, 26);

add_filter('script_loader_tag', static function ($tag, $handle) {
    if ($handle !== 'mad4b-navigation-signed-lazy-listings') return $tag;
    return preg_replace('/<script\b/i', '<script data-no-optimize="1" data-no-defer="1"', $tag, 1);
}, 50, 2);
