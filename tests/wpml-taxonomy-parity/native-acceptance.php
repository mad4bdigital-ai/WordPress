<?php
/**
 * Read-only WPML taxonomy parity acceptance, run inside WP-CLI:
 * wp --path=/var/www/html eval-file tests/wpml-taxonomy-parity/native-acceptance.php
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_WPML_Term_Parity_V1', false)) {
    throw new RuntimeException('PARITY_PROVIDER_NOT_LOADED');
}
$a = MAD4B_WPML_Term_Parity_V1::audit(200);
if (($a['state'] ?? '') !== 'PASS' || ($a['count'] ?? -1) !== 0) {
    throw new RuntimeException('WPML_TAXONOMY_PARITY_NOT_CLEAN');
}
if (empty($a['supported_pairs'])) {
    throw new RuntimeException('NO_COVERED_POST_TYPE_TAXONOMY_PAIRS');
}
global $wpdb;
$violations = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->term_relationships} rel
     JOIN {$wpdb->posts} p ON p.ID=rel.object_id
       AND p.post_type='tours-and-activities' AND p.post_status='publish'
     JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=rel.term_taxonomy_id
       AND tt.taxonomy='tour-types'
     JOIN {$wpdb->prefix}icl_translations postlang
       ON postlang.element_id=p.ID AND postlang.element_type='post_tours-and-activities'
     LEFT JOIN {$wpdb->prefix}icl_translations termlang
       ON termlang.element_id=tt.term_taxonomy_id AND termlang.element_type='tax_tour-types'
     WHERE termlang.language_code IS NULL OR termlang.language_code<>postlang.language_code"
);
if ($violations) throw new RuntimeException('RAW_TERM_LANGUAGE_MISMATCH_COUNT_'.$violations);
$labels = [];
foreach (['de' => [203, 'Reisepakete'], 'fr' => [741, 'Circuits combinés']] as $language=>$config) {
    [$id, $name] = $config;
    // WPML intercepts get_term() and may return the current-language object.
    // Assert the raw taxonomy identity, not WPML's frontend remap.
    $term = $wpdb->get_row($wpdb->prepare(
        "SELECT t.term_id,t.name,t.slug,tr.language_code,tr.trid
         FROM {$wpdb->terms} t
         JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
             AND tt.taxonomy='tour-types'
         JOIN {$wpdb->prefix}icl_translations tr
             ON tr.element_id=tt.term_taxonomy_id
             AND tr.element_type='tax_tour-types'
         WHERE t.term_id=%d", $id
    ), ARRAY_A);
    if (!$term || $term['name'] !== $name
      || $term['language_code'] !== $language
      || !($term['trid'] ?? null)) {
        throw new RuntimeException('TERM_LABEL_PARITY_FAILED_'.$id);
    }
    $labels[$language] = ['id'=>$id,'name'=>$name,'slug_preserved'=>$term['slug']];
}
$out = [
    'contract'=>'mad4b.wpml-taxonomy-parity.native-acceptance.v1',
    'pass'=>true,'site'=>home_url('/'),
    'language_mismatches'=>0,'source_provider_state'=>$a['state'],
    'labels'=>$labels,'time_utc'=>gmdate('c')
];
echo wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
