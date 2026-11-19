<?php
/**
 * Explicit adoption receipt for the pre-existing MAD4B Master Admin role caps.
 * Usage:
 *   wp --user=ADMIN_ID eval-file adopt-existing.php             (read-only status)
 *   MAD4B_ROLE_POLICY_ADOPT=1 MAD4B_ROLE_POLICY_EXPECTED_MASTER_SHA256=... \
 *   MAD4B_ROLE_POLICY_EXPECTED_ROLES_SHA256=... \
 *   wp --user=ADMIN_ID eval-file adopt-existing.php             (single option write)
 *
 * This script does NOT add, remove or change a role capability. All future
 * role edits are handled by User Role Editor and separately approved migrations.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "WORDPRESS_CLI_REQUIRED\n");
    exit(71);
}

const MAD4B_COMPANY_ROLE_SEED_VERSION = '20261119.1';
const MAD4B_COMPANY_ROLE_SEED_OPTION = 'mad4b_company_workspaces_role_seed_v20261119_1';

global $wpdb;
$role = get_role('master_admin');
$roles = get_option($wpdb->prefix . 'user_roles');
if (!$role || !is_array($roles) || !isset($roles['master_admin'])) {
    fwrite(STDERR, "MASTER_ROLE_OR_ROLE_STORE_NOT_FOUND\n");
    exit(72);
}
$roleSha = hash('sha256', serialize($role->capabilities));
$storeSha = hash('sha256', serialize($roles));
$caps = [
    'mad4b_manage_company_pricing' => $role->has_cap('mad4b_manage_company_pricing'),
    'mad4b_propose_company_content' => $role->has_cap('mad4b_propose_company_content'),
    'ure_edit_roles' => $role->has_cap('ure_edit_roles'),
];
$old = get_option(MAD4B_COMPANY_ROLE_SEED_OPTION, null);
$status = [
    'contract' => 'mad4b.company-workspaces-role-adoption.v1',
    'site' => home_url(),
    'version' => MAD4B_COMPANY_ROLE_SEED_VERSION,
    'current_master_cap_sha256' => $roleSha,
    'current_roles_store_sha256' => $storeSha,
    'master_caps' => $caps,
    'adopted' => is_array($old),
    'adopted_master_cap_sha256' => is_array($old) ? ($old['master_cap_sha256'] ?? null) : null,
    'manual_ure_changes_preserved' => true,
    'role_capabilities_modified' => false,
];

if (getenv('MAD4B_ROLE_POLICY_ADOPT') !== '1') {
    echo wp_json_encode(['state' => 'READ_ONLY_STATUS'] + $status) . "\n";
    return;
}
if (!current_user_can('manage_options') || !current_user_can('ure_manage_options')
    || in_array('master_admin', (array)wp_get_current_user()->roles, true)) {
    fwrite(STDERR, "AUTHORIZED_ROLE_EDITOR_REQUIRED\n");
    exit(73);
}
if ($old !== null) {
    fwrite(STDERR, "VERSION_ALREADY_ADOPTED_NO_OVERWRITE\n");
    exit(74);
}
if (!$caps['mad4b_manage_company_pricing'] || !$caps['mad4b_propose_company_content']
    || $caps['ure_edit_roles']) {
    fwrite(STDERR, "REVIEW_MANUAL_ROLE_CHANGES_BEFORE_ADOPTING\n");
    exit(75);
}
$expectMaster = getenv('MAD4B_ROLE_POLICY_EXPECTED_MASTER_SHA256');
$expectStore = getenv('MAD4B_ROLE_POLICY_EXPECTED_ROLES_SHA256');
if (!is_string($expectMaster) || !hash_equals($roleSha, $expectMaster)
    || !is_string($expectStore) || !hash_equals($storeSha, $expectStore)) {
    fwrite(STDERR, "ROLE_PREIMAGE_CHANGED_CAS_REFUSED\n");
    exit(76);
}
// Capture both the role and full role-store preimages. The adoption is
// informational and must NEVER be used to restore caps after a URE edit.
$receipt = [
    'version' => MAD4B_COMPANY_ROLE_SEED_VERSION,
    'master_cap_sha256' => $roleSha,
    'roles_store_sha256' => $storeSha,
    'adopted_by_user_id' => get_current_user_id(),
    'adopted_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'site' => home_url(),
    'capability_write_performed' => false,
];
if (!add_option(MAD4B_COMPANY_ROLE_SEED_OPTION, $receipt, '', false)) {
    fwrite(STDERR, "ADOPTION_RECEIPT_ALREADY_EXISTS_OR_WRITE_DENIED\n");
    exit(77);
}
$read = get_option(MAD4B_COMPANY_ROLE_SEED_OPTION, null);
$roleAfter = get_role('master_admin');
$rolesAfter = get_option($wpdb->prefix . 'user_roles');
if ($read !== $receipt
    || hash('sha256', serialize($roleAfter->capabilities)) !== $roleSha
    || hash('sha256', serialize($rolesAfter)) !== $storeSha) {
    fwrite(STDERR, "ADOPTION_READBACK_OR_ROLE_STATE_MISMATCH\n");
    exit(78);
}
echo wp_json_encode([
    'state' => 'EXISTING_ROLE_POLICY_ADOPTED_WITHOUT_CAP_WRITES',
    'option' => MAD4B_COMPANY_ROLE_SEED_OPTION,
    'adopted_by' => get_current_user_id(),
] + $status) . "\n";
