<?php
/**
 * Plugin Name: MAD4B Tour UI Source Parity
 * Description: Scope source-matched Rate actions, quote typography and sticky inquiry to public single tours.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin() || !is_singular('tours-and-activities')) return;
    $base = content_url('mu-plugins/assets/');
    $root = WPMU_PLUGIN_DIR . '/assets/';
    $style = 'mad4b-tour-ui-source-parity-20261106.css';
    $js = 'mad4b-tour-estimate-caption-20261106.js';
    if (is_readable($root.$style)) wp_enqueue_style('mad4b-tour-ui-source-parity', $base.$style, [], (string)filemtime($root.$style));
    if (is_readable($root.$js)) {
        wp_enqueue_script('mad4b-tour-estimate-caption', $base.$js, [], (string)filemtime($root.$js), true);
        $base_caption = 'Tour estimate · subject to confirmation';
        do_action('wpml_register_single_string','MAD4B Tour UI','tour_estimate_caption',$base_caption);
        $caption = apply_filters('wpml_translate_single_string',$base_caption,'MAD4B Tour UI','tour_estimate_caption');
        $language = strtolower(substr((string)apply_filters('wpml_current_language',determine_locale()),0,2));
        $fallback = [
            'ar'=>'تقدير تكلفة الرحلة · يخضع للتأكيد',
            'es'=>'Presupuesto del tour · sujeto a confirmación',
            'fr'=>'Estimation du voyage · sous réserve de confirmation',
            'de'=>'Reisepreis-Schätzung · vorbehaltlich Bestätigung',
            'it'=>'Preventivo del tour · soggetto a conferma',
        ];
        if (!is_string($caption) || $caption === $base_caption) $caption = $fallback[$language] ?? $base_caption;
        wp_add_inline_script('mad4b-tour-estimate-caption',
            'window.MAD4BTourUiSourceParityV1='.wp_json_encode(['caption'=>$caption],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';','before');
    }
    // Wayfatours uses the same Elementor Rate structures but does not have
    // the original source-owned inline Rate-selection UI script/styles.
    if (strpos((string)home_url('/'),'wayfatours.com') === false) return;
    $rate_css='mad4b-source-rate-actions-20261106.css';
    $rate_js='mad4b-source-rate-actions-20261106.js';
    if (is_readable($root.$rate_css)) wp_enqueue_style('mad4b-source-rate-actions', $base.$rate_css, ['mad4b-tour-ui-source-parity'],(string)filemtime($root.$rate_css));
    if (is_readable($root.$rate_js)) wp_enqueue_script('mad4b-source-rate-actions', $base.$rate_js, [],(string)filemtime($root.$rate_js),true);
}, 99);
add_filter('script_loader_tag', static function ($tag,$handle) {
    if (!in_array($handle,['mad4b-tour-estimate-caption','mad4b-source-rate-actions'],true)) return $tag;
    return preg_replace('/<script\b/i','<script data-no-defer="1" data-no-optimize="1"',$tag,1);
}, 50, 2);
