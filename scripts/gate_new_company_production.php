<?php
/**
 * MAD4B: mandatory admission for clean production WordPress company installs.
 *
 * Run on the TARGET site after creating its isolated WordPress database + Kit,
 * BEFORE enabling DNS ingress or external integrations:
 *
 * MAD4B_EXPECTED_COMPANY=wayfatours \
 * MAD4B_EXPECTED_HOST=wayfatours.mad4b.com \
 * MAD4B_EXPECTED_DB=wayfatours_production \
 * wp eval-file scripts/gate_new_company_production.php
 *
 * This is a private-production RUNTIME gate. External/public route promotion
 * also requires authoritative DNS ownership, valid HTTPS and source approval.
 */
if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) {
    return;
}
global $wpdb;
$required = [
    'company' => getenv('MAD4B_EXPECTED_COMPANY'),
    'host' => getenv('MAD4B_EXPECTED_HOST'),
    'db' => getenv('MAD4B_EXPECTED_DB'),
];
foreach ($required as $key=>$value) {
    if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9.-]{2,120}$/', $value)) {
        WP_CLI::error('MISSING_EXACT_EXPECTED_' . strtoupper($key));
    }
}
$identity = get_option('mad4b_company_site_identity_v1', false);
$actual = [
    'host' => wp_parse_url(home_url(), PHP_URL_HOST),
    'db' => DB_NAME,
    'env' => wp_get_environment_type(),
    'name' => get_option('blogname'),
    'identity' => is_array($identity) ? $identity : [],
];
$issues = [];
if ($actual['env'] !== 'production') $issues[] = 'not_production';
if ($actual['host'] !== $required['host']) $issues[] = 'wrong_hostname';
if ($actual['db'] !== $required['db']) $issues[] = 'wrong_database';
if (!is_array($identity)) {
    $issues[] = 'missing_unique_company_identity';
} else {
    if (($identity['company_code'] ?? '') !== $required['company']) $issues[] = 'wrong_company_code';
    if (($identity['host'] ?? '') !== $required['host']) $issues[] = 'identity_host_mismatch';
    if (($identity['environment'] ?? '') !== 'production') $issues[] = 'identity_not_production';
    if (!wp_is_uuid($identity['site_uuid'] ?? '')) $issues[] = 'missing_valid_site_uuid';
    if (($identity['provisioning_state'] ?? '') !== 'internal_only_dns_tls_pending') {
        $issues[] = 'unverified_public_provisioning_state';
    }
}
if (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT) $issues[] = 'file_editor_not_disabled';
if (defined('WP_DEBUG') && WP_DEBUG) $issues[] = 'debug_enabled_in_production';
if (get_option('blogname') !== ($identity['company_display_name'] ?? null)) $issues[] = 'display_identity_mismatch';
if (!function_exists('mad4b_kit_integrity_admission') ||
    is_wp_error(mad4b_kit_integrity_admission())) $issues[] = 'elementor_company_kit_invalid';
if (!function_exists('jet_engine') ||
    !isset(jet_engine()->options_pages->registered_pages['mad4b-company-policy-library'])) {
    $issues[] = 'company_jetengine_policy_schema_missing';
}
$forbiddenTypes = ['tours-and-activities','tour_orders','tour_bookings','shop_order','product','properties','transportations'];
$in = implode(',',array_fill(0,count($forbiddenTypes),'%s'));
$inherited = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type IN ($in)", $forbiddenTypes
));
if ((int)$inherited !== 0) $issues[] = 'unexpected_tenant_commerce_records';
$attachments = (int)$wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='attachment'");
if ($attachments !== 0) $issues[] = 'unreviewed_cross_tenant_media';
$policies = get_option('mad4b-company-policy-library', []);
if (!is_array($policies) || count($policies) !== 0) $issues[] = 'unapproved_company_policy_values';
$active = (array)get_option('active_plugins',[]);
foreach($active as $plugin) {
    if (!in_array($plugin,['elementor/elementor.php','jet-engine/jet-engine.php'],true)) {
        $issues[] = 'unreviewed_active_plugin';
        break;
    }
}
if ((int)get_option('blog_public',1)!==0) $issues[] = 'public_indexing_not_disabled';
if (!is_file(WPMU_PLUGIN_DIR.'/199-wayfatours-unconfigured-email.php')) {
    $issues[] = 'unverified_outbound_email_not_disabled';
}
if ((int)$wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->users}") !== 1) {
    $issues[] = 'unexpected_company_users';
}
$summary = [
    'contract' => 'mad4b.new-company-production-admission.v1',
    'state' => !$issues ? 'PRODUCTION_PRIVATE_ADMITTED_DNS_TLS_PENDING' : 'GOVERNANCE_DENIED',
    'company' => $required['company'],
    'host' => $actual['host'],
    'db' => $actual['db'],
    'site_uuid_valid' => !$issues || wp_is_uuid($identity['site_uuid'] ?? ''),
    'kit_valid' => !in_array('elementor_company_kit_invalid',$issues,true),
    'inherited_commerce_records' => (int)$inherited,
    'media_records' => $attachments,
    'active_plugins' => $active,
    'issues' => $issues,
    'public_http_https_approved' => false,
    'production_data_modified' => false,
];
echo wp_json_encode($summary, JSON_UNESCAPED_UNICODE).PHP_EOL;
if ($issues) WP_CLI::error('NEW_TENANT_ADMISSION_DENIED');
