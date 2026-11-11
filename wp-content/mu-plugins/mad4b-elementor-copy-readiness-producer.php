<?php
/**
 * Plugin Name: MAD4B Elementor Copy Coverage — Readiness Producer
 * Description: Publish bounded read-only coverage advisories to existing MAD4B Content Readiness contract.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/**
 * Only publishes previously persisted findings from offline/admin scans.
 * Never scans Elementor during page render, never dispatches, never changes
 * posts or translations, and never modifies the Readiness validator.
 */
function mad4b_copy_coverage_readiness_advisories($rows) {
    if (!is_array($rows)) return $rows;
    $snapshot = get_option('mad4b_elementor_copy_coverage_v1', []);
    if (!is_array($snapshot) || ($snapshot['contract'] ?? '') !== 'mad4b.elementor-copy-coverage.v1') return $rows;
    if (($snapshot['site'] ?? '') !== home_url('/')) return $rows;
    $rules = [
        'hero_static_locale' => 100,
        'catalog_fallback_en' => 95,
        'same_as_source' => 78,
        'filter_week_typo' => 75,
    ];
    $seen = [];
    foreach (array_slice((array)($snapshot['alerts'] ?? []), 0, 80) as $finding) {
        if (!is_array($finding)) continue;
        $kind = (string)($finding['code'] ?? '');
        $id = absint($finding['post_id'] ?? 0);
        $language = (string)($finding['locale'] ?? '');
        $widget = (string)($finding['widget_id'] ?? '');
        $path = (string)($finding['path'] ?? '');
        if (!$id || !isset($rules[$kind]) ||
            !in_array($language, ['en','es','fr','de','it','ar'], true) ||
            !preg_match('/^[a-z0-9]{4,20}$/D', $widget) ||
            strlen($path) > 240 || $path === '' ||
            get_post_type($id) !== 'elementor_library' ||
            get_post_status($id) !== 'publish') continue;
        $detail = apply_filters('wpml_post_language_details', null, $id);
        if (($detail['language_code'] ?? '') !== $language) continue;
        $priority = $rules[$kind];
        if ((int)($finding['priority'] ?? 0) !== $priority) continue;
        $suffix = substr(hash('sha256', $widget . '|' . $path), 0, 12);
        $code = 'elm_copy_' . $kind . '_' . $language . '_' . $suffix;
        $key = $id . '|' . $code;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $label = 'Elementor text coverage [' . $language . '] ' . $kind .
            ', template #' . $id . ', widget ' . $widget;
        $rows[] = [
            'id' => hash('sha256', get_current_blog_id() . '|elementor_library|' . $id . '|' . $code),
            'post_type' => 'elementor_library',
            'object_id' => $id,
            'code' => $code,
            'severity' => $priority >= 95 ? 'high' : 'medium',
            'label' => $label,
            'priority' => $priority,
            'source' => 'mad4b-elementor-copy-coverage',
        ];
    }
    return $rows;
}
add_filter('mad4b/content_readiness/external_issues', 'mad4b_copy_coverage_readiness_advisories', 41, 1);
