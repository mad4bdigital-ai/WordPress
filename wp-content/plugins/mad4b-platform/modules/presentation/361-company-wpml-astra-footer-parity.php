<?php
/**
 * Plugin Name: Company WPML Astra Footer Parity
 * Description: Read-only fallback for original Astra admin-text WPML translations; site title remains local.
 */
defined('ABSPATH') || exit;
add_filter('option_astra-settings', static function ($options) {
    if (!is_array($options) || !isset($options['footer-copyright-editor']) ||
        !function_exists('icl_t')) return $options;
    $language = (string) apply_filters('wpml_current_language', null);
    if ($language === '' || $language === (string) apply_filters('wpml_default_language','en')) return $options;
    $raw = $options['footer-copyright-editor'];
    if (!is_string($raw) || $raw === '') return $options;
    // A real WPML admin-option translation always wins; never replace it.
    $native = icl_t('admin_texts_astra-settings','[astra-settings]footer-copyright-editor',$raw);
    if (is_string($native) && $native !== '' && $native !== $raw) {
        $options['footer-copyright-editor'] = $native;
        return $options;
    }
    static $cache = [];
    $key = $language . ':' . hash('sha256', $raw);
    if (!array_key_exists($key, $cache)) {
        global $wpdb;
        $strings=$wpdb->prefix.'icl_strings';
        $translations=$wpdb->prefix.'icl_string_translations';
        $value=$wpdb->get_var($wpdb->prepare(
            "SELECT t.value FROM {$strings} s INNER JOIN {$translations} t ON t.string_id=s.id WHERE s.context=%s AND s.name=%s AND s.language=%s AND s.value=%s AND t.language=%s AND t.status=%d LIMIT 1",
            'admin_texts_astra-settings','[astra-settings]footer-copyright-editor','en',$raw,$language,3
        ));
        $cache[$key] = (is_string($value) && $value!=='' && str_contains($value,'[site_title]') && str_contains($value,'[current_year]')) ? $value : '';
    }
    if ($cache[$key] !== '') $options['footer-copyright-editor']=$cache[$key];
    return $options;
}, 999, 1);
