<?php
/**
 * MAD4B / Wayfatours original 18 hotel publication certification.
 *
 * Read-only WP-CLI audit. Tracks the explicitly approved Silver/Gold/Diamond
 * editorial Property cohort, its native Unit relations and WPML languages.
 * No catalog, prices, tour rates, stock or post-status mutations.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('WP CLI only');
}
if (rtrim((string)home_url(), '/') !== 'https://wayfatours.com') {
    WP_CLI::error('unexpected_site');
}
$cohort = [
    'Silver' => [70732, 36475, 36316, 36197, 36209],
    'Gold' => [36479, 36473, 36474, 36477, 36140, 70733],
    'Diamond' => [36355, 70734, 70735, 70736, 70737, 70738, 70739],
];
$all = array_merge(...array_values($cohort));
if (count($all) !== 18 || count(array_unique($all)) !== 18) {
    WP_CLI::error('invalid_expected_cohort');
}
global $wpdb;
$relations = $wpdb->get_results(
    "SELECT parent_object_id,child_object_id FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=223",
    ARRAY_A
);
$unitLinks = [];
foreach ($relations as $relation) {
    $unitLinks[(int)$relation['parent_object_id']][] =
        (int)$relation['child_object_id'];
}
$translationTable = $wpdb->prefix . 'icl_translations';
$hasWPML = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $translationTable))
    === $translationTable);
$violations = [];
$results = [];
$summary = ['total' => 18, 'published' => 0, 'tier_valid' => 0,
    'linked_units_published' => 0, 'linked_units_draft' => 0,
    'without_units' => [], 'spanish_published' => 0, 'spanish_missing' => []];
$tiers = [];
foreach ($cohort as $tier => $ids) {
    $tiers[$tier] = ['total' => count($ids), 'published' => 0,
        'correct_taxonomy' => 0, 'linked_units_published' => 0];
    foreach ($ids as $id) {
        $p = get_post($id);
        $status = $p ? $p->post_status : 'missing';
        $terms = wp_get_post_terms($id, 'package_levels', ['fields' => 'names']);
        $termNames = is_wp_error($terms) ? [] : $terms;
        sort($termNames);
        $tierValid = $termNames === [$tier];
        $units = array_values(array_unique($unitLinks[$id] ?? []));
        $unitStates = ['publish' => 0, 'draft' => 0, 'other' => 0];
        foreach ($units as $uid) {
            $state = get_post_status($uid);
            if ($state === 'publish') ++$unitStates['publish'];
            elseif ($state === 'draft') ++$unitStates['draft'];
            else ++$unitStates['other'];
        }
        $es = null;
        if ($hasWPML) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT trid,language_code FROM {$translationTable}
                 WHERE element_type=%s AND element_id=%d",
                'post_properties', $id), ARRAY_A);
            if (!$row || ($row['language_code'] ?? '') !== 'en') {
                $violations[] = 'wpml_original_language_not_en_' . $id;
            } else {
                $esRow = $wpdb->get_row($wpdb->prepare(
                    "SELECT element_id FROM {$translationTable}
                     WHERE element_type=%s AND trid=%d AND language_code=%s",
                    'post_properties', (int)$row['trid'], 'es'), ARRAY_A);
                $es = $esRow
                    ? ['id'=>(int)$esRow['element_id'],
                        'status'=>get_post_status((int)$esRow['element_id'])]
                    : null;
            }
        }
        if (!$p || $p->post_type !== 'properties' || $status !== 'publish') {
            $violations[] = 'property_not_published_' . $id;
        } else {
            ++$summary['published']; ++$tiers[$tier]['published'];
        }
        if (!$tierValid) {
            $violations[] = 'property_tier_mismatch_' . $id;
        } else {
            ++$summary['tier_valid']; ++$tiers[$tier]['correct_taxonomy'];
        }
        if ($unitStates['draft'] || $unitStates['other']) {
            $violations[] = 'linked_unit_not_published_' . $id;
        }
        $summary['linked_units_published'] += $unitStates['publish'];
        $summary['linked_units_draft'] += $unitStates['draft'];
        $tiers[$tier]['linked_units_published'] += $unitStates['publish'];
        if (!$units) $summary['without_units'][] = $id;
        if ($es && $es['status'] === 'publish') ++$summary['spanish_published'];
        else $summary['spanish_missing'][] = $id;
        $results[] = [
            'id'=>$id, 'title'=>$p ? html_entity_decode($p->post_title,ENT_QUOTES,'UTF-8') : '',
            'tier'=>$tier, 'status'=>$status, 'tier_valid'=>$tierValid,
            'linked_units'=>$unitStates, 'spanish_translation'=>$es,
        ];
    }
}
$badIds = [36260,37553,37554,37555];
foreach ($badIds as $bad) {
    if (get_post_status($bad) !== 'draft') {
        $violations[] = 'import_quarantine_changed_' . $bad;
    }
}
$publishedRates = (int)(wp_count_posts('property-rates')->publish ?? 0);
if ($publishedRates !== 0) $violations[] = 'property_rates_published';
$plan = get_option('mad4b_stay_editorial_publish_v1');
if (!is_array($plan) || ($plan['catalog_activated'] ?? null) !== false) {
    $violations[] = 'catalog_publication_plan_changed';
}
if ($summary['linked_units_published'] !== 131 || $summary['without_units'] !== [36316]) {
    $violations[] = 'linked_unit_cohort_mismatch';
}
$output = [
    'site'=>home_url(), 'audit_time'=>gmdate('c'),
    'cohort_summary'=>$summary, 'tiers'=>$tiers, 'hotels'=>$results,
    'wpml_enabled'=>$hasWPML, 'published_property_rates'=>$publishedRates,
    'catalog_publication_plan_activated'=>is_array($plan)
        ? ($plan['catalog_activated'] ?? null) : null,
    'quarantined_import_statuses'=>array_combine(
        $badIds, array_map('get_post_status', $badIds)),
    'violations'=>$violations,
];
WP_CLI::line(wp_json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
if ($violations) WP_CLI::error('original_18_hotel_publication_audit_failed');
