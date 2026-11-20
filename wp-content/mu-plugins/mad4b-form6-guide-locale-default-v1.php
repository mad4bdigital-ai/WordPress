<?php
/**
 * Plugin Name: MAD4B Form 6 Guide Locale Default
 * Description: Maps the WPML display language identifier to one verified Guide Language taxonomy term for original Fluent Form #6.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Form6_Guide_Locale_Default_V1 {
    public const IDENTITY_META = '_mad4b_guide_locale_code';

    public static function display_locale_code(): string {
        $candidate = apply_filters('wpml_current_language', null);
        if (!is_string($candidate) || $candidate === '') {
            $candidate = function_exists('determine_locale') ? determine_locale() : get_locale();
        }
        $candidate = strtolower(str_replace('_', '-', (string)$candidate));
        if (!preg_match('/^([a-z]{2})(?:-[a-z0-9]{2,8})*$/D', $candidate, $match)) {
            return '';
        }
        return $match[1];
    }

    public static function render(array $field, $form): array {
        if ((int)($form->id ?? 0) !== 6 ||
            ($field['element'] ?? '') !== 'select' ||
            ($field['attributes']['name'] ?? '') !== 'guide_language' ||
            !taxonomy_exists('guide-languages')) {
            return $field;
        }
        // Do not overwrite a manually configured default or a restored user selection.
        if ((string)($field['attributes']['value'] ?? '') !== '' ||
            (string)($field['settings']['dynamic_default_value'] ?? '') !== '') {
            return $field;
        }
        $locale = self::display_locale_code();
        if ($locale === '') return $field;
        $options = $field['settings']['advanced_options'] ?? null;
        if (!is_array($options) || count($options) > 50) return $field;
        $matches = [];
        foreach ($options as $option) {
            $slug = $option['value'] ?? null;
            if (!is_string($slug) || !preg_match('/^[a-z0-9-]{2,64}$/D', $slug)) continue;
            $term = get_term_by('slug', $slug, 'guide-languages');
            if (!$term || is_wp_error($term)) continue;
            $termIdentity = (string)get_term_meta((int)$term->term_id, self::IDENTITY_META, true);
            if ($termIdentity === $locale) $matches[] = $slug;
        }
        // Fail closed for no matching Guide or ambiguous taxonomy associations.
        if (count($matches) === 1) {
            $field['attributes']['value'] = $matches[0];
        }
        return $field;
    }
}
add_filter('fluentform/rendering_field_data_select', ['MAD4B_Form6_Guide_Locale_Default_V1', 'render'], 25000, 2);
