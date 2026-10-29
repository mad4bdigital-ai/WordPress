<?php
/**
 * Plugin Name: MAD4B Elementor Kit Integrity Gate
 * Description: Reject ambiguous Elementor global-color identities before saving the active company Kit.
 * Version: 1.0.0
 * Author: MAD4B
 *
 * Runtime enforcement for WordPress/Elementor API writes, not direct SQL.
 * Company provisioning must invoke mad4b_kit_integrity_admission() before cloning.
 */
defined('ABSPATH') || exit;

if (!function_exists('mad4b_kit_integrity_validate_palette')) {
    /**
     * Require a single unambiguous definition of each Kit color identity.
     * Return WP_Error rather than normalizing potentially conflicting values.
     */
    function mad4b_kit_integrity_validate_palette($settings) {
        if (!is_array($settings)) {
            return new WP_Error('mad4b_kit_invalid_settings', 'Kit palette payload must be an array.');
        }
        $colors = $settings['custom_colors'] ?? null;
        if (!is_array($colors)) {
            return new WP_Error('mad4b_kit_missing_palette', 'Custom palette must be present in approved Kit settings.');
        }
        $seen = [];
        foreach ($colors as $item) {
            if (!is_array($item) || !isset($item['_id']) || !is_string($item['_id']) ||
                !preg_match('/^[a-zA-Z0-9_]{1,48}$/', $item['_id']) ||
                !isset($item['color']) || !is_string($item['color']) ||
                !preg_match('/^#[a-fA-F0-9]{6}(?:[a-fA-F0-9]{2})?$/', $item['color'])) {
                return new WP_Error('mad4b_kit_invalid_color', 'Every Kit color must have a valid unique identity and hex value.');
            }
            if (isset($seen[$item['_id']])) {
                return new WP_Error('mad4b_kit_duplicate_color_id', 'Duplicate Elementor Kit custom color ID: ' . $item['_id']);
            }
            $seen[$item['_id']] = true;
        }
        if (!isset($seen['bnav001'])) {
            return new WP_Error('mad4b_kit_missing_header_surface', 'Current company Kit must define exactly one bnav001 navigation surface color.');
        }
        $system = $settings['system_colors'] ?? null;
        if (!is_array($system)) {
            return new WP_Error('mad4b_kit_missing_system_colors', 'Kit system colors must be present.');
        }
        $count = [];
        foreach ($system as $item) {
            if (!is_array($item) || !is_string($item['_id'] ?? null)) {
                return new WP_Error('mad4b_kit_invalid_system_palette', 'Invalid system Kit color.');
            }
            $id = $item['_id'];
            $count[$id] = ($count[$id] ?? 0) + 1;
        }
        foreach (['primary', 'secondary', 'text', 'accent'] as $required) {
            if (($count[$required] ?? 0) !== 1) {
                return new WP_Error('mad4b_kit_missing_semantic_color', 'Missing or duplicate semantic Kit color: ' . $required);
            }
        }
        return true;
    }
}

if (!function_exists('mad4b_kit_integrity_admission')) {
    function mad4b_kit_integrity_admission($kit_id = null) {
        if (!class_exists('Elementor\\Plugin')) {
            return new WP_Error('mad4b_kit_elementor_unavailable', 'Elementor active Kit is unavailable.');
        }
        $active = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
        if ($kit_id !== null && (int)$kit_id !== $active) {
            return new WP_Error('mad4b_kit_wrong_identity', 'Specified Kit is not the active company Kit.');
        }
        if (!$active) {
            return new WP_Error('mad4b_kit_unconfigured', 'Company has no active Elementor Kit.');
        }
        return mad4b_kit_integrity_validate_palette(
            get_post_meta($active, '_elementor_page_settings', true)
        );
    }
}

if (!function_exists('mad4b_kit_integrity_enforce')) {
    function mad4b_kit_integrity_enforce($candidate) {
        $result = mad4b_kit_integrity_validate_palette($candidate);
        if (is_wp_error($result)) {
            throw new RuntimeException('MAD4B_KIT_INTEGRITY_DENIED: ' .
                $result->get_error_code() . ': ' . $result->get_error_message());
        }
        return $candidate;
    }
}

/* Elementor editor: before its Kit page-settings update is executed. */
add_filter('elementor/document/save/data', static function ($data, $document) {
    if (!is_object($document) || !method_exists($document, 'get_type') ||
        !method_exists($document, 'get_id') || $document->get_type() !== 'kit') {
        return $data;
    }
    if ((int)$document->get_id() !== (int)\Elementor\Plugin::$instance->kits_manager->get_active_id()) {
        return $data;
    }
    $settings = $data['settings'] ?? null;
    if (is_array($settings) && (isset($settings['custom_colors']) || isset($settings['system_colors']))) {
        $current = get_post_meta((int)$document->get_id(), '_elementor_page_settings', true);
        if (!is_array($current)) {
            throw new RuntimeException('MAD4B_KIT_INTEGRITY_DENIED: existing company Kit settings unavailable.');
        }
        mad4b_kit_integrity_enforce(array_merge($current, $settings));
    }
    return $data;
}, 8, 2);

/* WordPress API: last opportunity to stop a direct update_post_meta Kit write. */
add_filter('update_post_metadata', static function ($check, $object_id, $meta_key, $meta_value, $prev_value) {
    if ($check !== null || $meta_key !== '_elementor_page_settings' ||
        !class_exists('Elementor\\Plugin')) {
        return $check;
    }
    $active = (int)\Elementor\Plugin::$instance->kits_manager->get_active_id();
    if ($active !== (int)$object_id) {
        return $check;
    }
    mad4b_kit_integrity_enforce($meta_value);
    return $check;
}, 8, 5);

/* Same guard for fresh Kit settings created by add_post_meta(). */
add_filter('add_post_metadata', static function ($check, $object_id, $meta_key, $meta_value, $unique) {
    if ($check !== null || $meta_key !== '_elementor_page_settings' ||
        !class_exists('Elementor\\Plugin')) {
        return $check;
    }
    if ((int)$object_id === (int)\Elementor\Plugin::$instance->kits_manager->get_active_id()) {
        mad4b_kit_integrity_enforce($meta_value);
    }
    return $check;
}, 8, 5);
