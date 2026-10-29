<?php
/**
 * Plugin Name: MAD4B Client Header & Mobile Tour Navigation
 * Description: Client-facing passport label, Elementor Kit color inheritance and mobile nested tour contents.
 * Version: 1.0.0
 * Author: MAD4B
 *
 * UI-only enhancement. Existing Elementor JSON, visitor-nationality pricing,
 * WooCommerce, WPML, and booking context remain unchanged.
 */

defined('ABSPATH') || exit;

if (!function_exists('mad4b_customer_header_passport_label')) {
    function mad4b_customer_header_passport_label($locale = null) {
        $names = [
            'en' => 'Passport',
            'ar' => 'جواز السفر',
            'fr' => 'Passeport',
            'de' => 'Reisepass',
            'it' => 'Passaporto',
            'es' => 'Pasaporte',
        ];
        if (!is_string($locale) || !isset($names[$locale])) {
            $locale = apply_filters('wpml_current_language', null);
        }
        $locale = is_string($locale) ? $locale : '';
        if (!isset($names[$locale])) {
            $locale = substr((string)get_locale(), 0, 2);
        }
        return $names[$locale] ?? $names['en'];
    }
}

if (!function_exists('mad4b_customer_header_label_html')) {
    function mad4b_customer_header_label_html($html, $label) {
        if (!is_string($html) || strpos($html, 'data-header-context-label') === false) {
            return $html;
        }
        // Replace *only* the dedicated client-facing context label element.
        // Market remains a distinct INTERNAL pricing/availability identifier.
        return preg_replace_callback(
            '~(<span\s+data-header-context-label\s+data-fallback=")[^"]*(">)[^<]*(</span>)~u',
            static function ($matches) use ($label) {
                return $matches[1] . esc_attr($label) . $matches[2]
                    . esc_html($label) . $matches[3];
            },
            $html,
            1
        );
    }
}

add_filter('elementor/widget/render_content', static function ($html, $widget) {
    if (!is_object($widget) || !method_exists($widget, 'get_name')
        || !method_exists($widget, 'get_id') || $widget->get_name() !== 'html'
        || !in_array($widget->get_id(), ['c0a29f0', 'c0a29f5'], true)) {
        return $html;
    }
    return mad4b_customer_header_label_html($html, mad4b_customer_header_passport_label());
}, 55, 2);

add_action('wp_enqueue_scripts', static function () {
    $base_url = content_url('mu-plugins/assets/');
    $base_path = WPMU_PLUGIN_DIR . '/assets/';

    $main_css = $base_path . 'mad4b-header-tour-ux.css';
    if (is_readable($main_css)) {
        wp_enqueue_style(
            'mad4b-header-tour-ux',
            $base_url . 'mad4b-header-tour-ux.css',
            [],
            (string)filemtime($main_css)
        );
    }

    // Never load/execute mobile TOC code on other content types or archives.
    if (is_singular('tours-and-activities')) {
        $js = $base_path . 'mad4b-mobile-tour-toc.js';
        if (is_readable($js)) {
            wp_enqueue_script(
                'mad4b-mobile-tour-toc',
                $base_url . 'mad4b-mobile-tour-toc.js',
                [],
                (string)filemtime($js),
                true
            );
        }
    }
}, 95);
