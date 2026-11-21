<?php
/**
 * Read-only, identity-aware WordPress CLI probe of Package Pricing Lab discovery.
 * Invoke with --user=<real-WordPress-user>, never fabricate admin cookies.
 * Optional: MAD4B_EXPECTED_PACKAGE_IDS=57562,60437,... for a known cohort.
 */
if (!defined('WP_CLI') || !WP_CLI) {
    return;
}
if (!class_exists('MAD4B_Package_Pricing_Lab_Readiness_V1')) {
    WP_CLI::error('PACKAGE_LAB_SOURCE_UNAVAILABLE');
}
$uid = get_current_user_id();
if (!$uid || !current_user_can('commerce_manage_pricing') ||
    !(current_user_can('manage_woocommerce') ||
      (current_user_can('mad4b_manage_company_operations') &&
       current_user_can('mad4b_work_company_tasks')))) {
    WP_CLI::error('PACKAGE_CATALOG_NOT_CHECKED: authenticated, pricing-authorized WP user required; anonymous CLI result must not be reported as zero inventory.');
}
$cls = 'MAD4B_Package_Pricing_Lab_Readiness_V1';
$packages = $cls::packages();
$tiers = $cls::tierIds();
$all = get_posts([
    'post_type' => 'tours-and-activities', 'post_status' => ['publish','draft','pending'],
    'posts_per_page' => 160, 'fields' => 'ids', 'suppress_filters' => true,
    'meta_key' => '_mad4b_package_accommodation_source_plan_v1',
    'meta_compare' => 'EXISTS', 'orderby' => 'ID', 'order' => 'ASC',
]);
$ids = array_map('intval', array_column($packages, 'id'));
$metaCandidates = array_map('intval', $all);
$missing = [];
foreach (explode(',', (string)getenv('MAD4B_EXPECTED_PACKAGE_IDS')) as $value) {
    $value=trim($value);
    if ($value!=='' && ctype_digit($value) && !in_array((int)$value, $ids, true)) {
        $missing[]=(int)$value;
    }
}
$probe = [
    'status'=>'authenticated_readonly',
    'site'=>home_url(),
    'wp_user_id'=>$uid,
    'source_meta_candidates'=>count($metaCandidates),
    'editable_packages'=>count($packages),
    'package_levels'=>count($tiers),
    'package_ids'=>$ids,
    'missing_expected_package_ids'=>$missing,
    'reason_if_zero'=>count($packages)?null:
        'No eligible editable Packages with valid saved stage plans for this authorized user',
    'write_performed'=>false,
];
WP_CLI::log('MAD4B_PACKAGE_DISCOVERY_CONTEXT '.wp_json_encode($probe));
if ($missing) {
    WP_CLI::error('PACKAGE_COHORT_MISMATCH');
}
if (!$tiers) {
    WP_CLI::error('PACKAGE_LEVELS_MISSING');
}
WP_CLI::success('PACKAGE_DISCOVERY_AUTHENTICATED_READONLY_PASS');
