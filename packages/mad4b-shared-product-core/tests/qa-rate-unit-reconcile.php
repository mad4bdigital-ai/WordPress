<?php
/**
 * One-time, bounded Local Lab QA fixture repair. No production/site-wide
 * inheritance. Missing commercial units remain unavailable until approved.
 */
if (PHP_SAPI !== 'cli') { exit(2); }
$site = getenv('MAD4B_QA_SITE_ROOT') ?: '';
if (!$site || !is_file(rtrim($site, '/') . '/wp-load.php')) {
    fwrite(STDERR, "WP_ROOT_REQUIRED\n"); exit(2);
}
require_once rtrim($site, '/') . '/wp-load.php';
if (wp_get_environment_type() !== 'local'
    || !defined('SHARED_PRODUCT_LAB_ONLY') || SHARED_PRODUCT_LAB_ONLY !== true) {
    fwrite(STDERR, "LAB_ONLY\n"); exit(2);
}
if (!function_exists('shared_product_unit_glossary')) {
    fwrite(STDERR, "SOURCE_RESOLVER_MISSING\n"); exit(2);
}
$glossary = shared_product_unit_glossary('tour-rates', 'unit_type');
if (is_wp_error($glossary)) {
    fwrite(STDERR, "JETENGINE_GLOSSARY_MISSING\n"); exit(2);
}
$allowed = array_keys($glossary['values']);
$ids = get_posts([
    'post_type' => 'tour-rates', 'post_status' => ['publish', 'draft'],
    'fields' => 'ids', 'posts_per_page' => -1, 'suppress_filters' => true,
]);
$candidates = [];
$unresolved = [];
foreach ($ids as $rawId) {
    $id = (int)$rawId;
    if (get_post_meta($id, 'unit_type', true) !== '') continue;
    $marker = (string)get_post_meta($id, '_booking_experience_qa_pricing_fixture', true);
    $tourId = (int)get_post_meta($id, 'related_tour_id', true);
    $parentUnit = $tourId ? (string)get_post_meta($tourId, 'tour_price_unit', true) : '';
    $isQa = str_starts_with($marker, 'cart-lab-20261026:');
    if (!$isQa || get_post_type($tourId) !== 'tours-and-activities'
        || !in_array($parentUnit, $allowed, true)
        || get_post_meta($id, 'unit_type', false) !== []) {
        $unresolved[] = ['rate_id' => $id, 'parent_tour_id' => $tourId,
            'source_available' => in_array($parentUnit, $allowed, true),
            'qa_fixture' => $marker !== ''];
        continue;
    }
    $candidates[] = ['rate_id' => $id, 'tour_id' => $tourId,
        'source' => 'tour_post_meta:tour_price_unit', 'unit_type' => $parentUnit,
        'qa_marker' => $marker, 'original_rate_unit_meta_rows' => 0];
}
usort($candidates, static fn($a, $b) => $a['rate_id'] <=> $b['rate_id']);
usort($unresolved, static fn($a, $b) => $a['rate_id'] <=> $b['rate_id']);
$hash = hash('sha256', json_encode($candidates, JSON_UNESCAPED_SLASHES));
$apply = getenv('MAD4B_QA_UNIT_APPLY') === '1';
$report = [
    'contract' => 'mad4b.commerce.qa-rate-unit.v1', 'environment' => 'local',
    'candidates' => $candidates, 'unresolved' => $unresolved,
    'candidate_sha256' => $hash, 'applied' => false,
    'commercial_rate_auto_inheritance' => false, 'quotes_created' => false,
];
if (!$apply) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
$expected = getenv('MAD4B_QA_UNIT_EXPECTED_SHA256');
$expectedCount = (int)getenv('MAD4B_QA_UNIT_EXPECTED_COUNT');
if (!is_string($expected) || !hash_equals($hash, $expected)
    || $expectedCount !== count($candidates)
    || $expectedCount < 1 || $expectedCount > 30) {
    fwrite(STDERR, "SNAPSHOT_OR_COUNT_MISMATCH\n"); exit(2);
}
global $wpdb;
if ($wpdb->query('START TRANSACTION') === false) {
    fwrite(STDERR, "TRANSACTION_UNAVAILABLE\n"); exit(1);
}
try {
    foreach ($candidates as $row) {
        $id = $row['rate_id'];
        if (get_post_meta($id, 'unit_type', false) !== []
            || get_post_meta($id, 'unit_type', true) !== ''
            || (int)get_post_meta($id, 'related_tour_id', true) !== $row['tour_id']
            || (string)get_post_meta($row['tour_id'], 'tour_price_unit', true) !== $row['unit_type']
            || (string)get_post_meta($id, '_booking_experience_qa_pricing_fixture', true) !== $row['qa_marker']
            || !str_starts_with($row['qa_marker'], 'cart-lab-20261026:')) {
            throw new RuntimeException('SOURCE_CHANGED:' . $id);
        }
        if (!add_post_meta($id, 'unit_type', $row['unit_type'], true)) {
            throw new RuntimeException('WRITE_FAILED:' . $id);
        }
    }
    foreach ($candidates as $row) {
        if (get_post_meta($row['rate_id'], 'unit_type', true) !== $row['unit_type']) {
            throw new RuntimeException('READBACK_FAILED:' . $row['rate_id']);
        }
    }
    if ($wpdb->query('COMMIT') === false) {
        throw new RuntimeException('COMMIT_FAILED');
    }
} catch (Throwable $e) {
    $wpdb->query('ROLLBACK');
    fwrite(STDERR, "ROLLED_BACK:" . $e->getMessage() . "\n");
    exit(1);
}
$report['applied'] = true;
$report['changed_count'] = count($candidates);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
