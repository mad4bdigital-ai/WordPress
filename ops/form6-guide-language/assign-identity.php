<?php
/**
 * Bind stable Guide Language identity to WPML language codes.
 * Dry-run by default; apply only via MAD4B_FORM6_APPLY=1.
 */
if (!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
global $wpdb;
if (!taxonomy_exists('guide-languages')) WP_CLI::error('Guide Language taxonomy missing');
$map = [
    'ar' => 'arabic', 'en' => 'english', 'fr' => 'french',
    'de' => 'german', 'es' => 'spanish', 'it' => 'italian',
];
$displayLanguages = array_keys($map);
$originalLocale = apply_filters('wpml_current_language', null);
if (has_filter('wpml_current_language')) do_action('wpml_switch_language', 'en');
$base = [];
foreach ($map as $code => $slug) {
    $term = get_term_by('slug', $slug, 'guide-languages');
    if (!$term || is_wp_error($term)) {
        WP_CLI::warning("Guide $code not configured; skip (no implied availability)");
        continue;
    }
    $base[$code] = (int)$term->term_id;
}
$plan = [];
$ids = [];
foreach ($base as $code => $termId) {
    foreach ($displayLanguages as $siteLocale) {
        $translated = has_filter('wpml_object_id')
            ? apply_filters('wpml_object_id', $termId, 'guide-languages', false, $siteLocale)
            : ($siteLocale === 'en' ? $termId : false);
        if (!is_numeric($translated) || (int)$translated <= 0) continue;
        $translated = (int)$translated;
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy=%s",
            $translated, 'guide-languages'
        ));
        if ($exists !== 1) WP_CLI::error("Mapped term $translated missing taxonomy");
        if (isset($ids[$translated]) && $ids[$translated] !== $code) {
            WP_CLI::error("Conflicting language identities on term $translated");
        }
        $ids[$translated] = $code;
        $old = (string)get_term_meta($translated, '_mad4b_guide_locale_code', true);
        if ($old !== '' && $old !== $code) {
            WP_CLI::error("Existing identity conflict term=$translated current=$old new=$code");
        }
        $plan[$translated] = ['term_id'=>$translated,'code'=>$code,'site_locale'=>$siteLocale,'old'=>$old];
    }
}
if (!$plan) WP_CLI::error('No recognized guide-language term identities');
if (is_string($originalLocale) && $originalLocale !== '' && has_filter('wpml_current_language')) {
    do_action('wpml_switch_language', $originalLocale);
}
$dry = getenv('MAD4B_FORM6_APPLY') !== '1';
WP_CLI::log(($dry?'DRY_RUN':'APPLY')." site=".site_url()." identity_terms=".count($plan)." guide_codes=".implode(',',array_keys($base)));
if ($dry) return;
$backup = '/tmp/mad4b-guide-locale-term-meta-before-'.gmdate('Ymd-His').'.json';
$payload = wp_json_encode(['site'=>site_url(),'schema'=>1,'identities'=>array_values($plan)], JSON_PRETTY_PRINT);
if (!$payload || file_put_contents($backup, $payload) === false) WP_CLI::error('Cannot store identity backup');
foreach ($plan as $id => $row) {
    if ((string)get_term_meta($id, '_mad4b_guide_locale_code', true) !== $row['old']) {
        WP_CLI::error("Concurrent identity change term=$id; backup $backup");
    }
    if (!update_term_meta($id, '_mad4b_guide_locale_code', $row['code'])) {
        if ((string)get_term_meta($id, '_mad4b_guide_locale_code', true) !== $row['code']) {
            WP_CLI::error("Identity write failed term=$id; backup $backup");
        }
    }
}
foreach ($plan as $id => $row) {
    if ((string)get_term_meta($id, '_mad4b_guide_locale_code', true) !== $row['code']) {
        WP_CLI::error("Readback failed term=$id; backup $backup");
    }
}
WP_CLI::success("GUIDE_IDENTITIES_VERIFIED mapped=".count($plan)." backup=".$backup);
