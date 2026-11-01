<?php
/**
 * WordPress CLI-only, guarded staging migration.
 *
 * Fluent Forms stores user-authored CSS in wp_fluentform_form_meta rather
 * than repository files. Reconcile only the legacy extreme stepper layers
 * for forms 6,7,8,11. Requires an authorized filesystem-backed operator.
 * Keeps a byte-exact CSS backup before writing any form.
 */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI required');
$profile = get_option('mad4b_scp_brand_context_profile_v1', []);
if (site_url() !== 'https://tours.dev.mad4b.com'
 || wp_get_environment_type() !== 'staging'
 || ($profile['site_uuid'] ?? '') !== 'e5e64bca-ed62-48d2-b2b3-dff693cd702a') {
    WP_CLI::error('Wrong Staging site; no changes made.');
}
global $wpdb;
$table = $wpdb->prefix . 'fluentform_form_meta';
$backup_dir = WP_CONTENT_DIR . '/mu-plugins/.qa';
if (!is_dir($backup_dir) || !is_writable($backup_dir)) {
    WP_CLI::error('Protected local QA backup directory is not writable by the operator.');
}
foreach ([6,7,8,11] as $id) {
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,value FROM {$table} WHERE form_id=%d AND meta_key=%s", $id, '_custom_form_css'
    ));
    if (count($rows) !== 1) WP_CLI::error('CSS row ambiguity on form ' . $id);
    $original = (string)$rows[0]->value;
    $new = preg_replace_callback(
        '/(z-index\s*:\s*)(?:999999|999)(\s*!important\s*;|\s*;)/i',
        static fn($m) => $m[1] . '3' . $m[2],
        $original, -1, $hits
    );
    if ($hits === 0) {
        // Idempotence with strict postconditions.
        if (preg_match_all('/z-index\s*:\s*3\b/i', $original) < 2) {
            WP_CLI::error('Unexpected CSS state on form ' . $id);
        }
        WP_CLI::log('Form ' . $id . ': already reconciled');
        continue;
    }
    if ($hits !== 2) WP_CLI::error('Unexpected high z-index count on form ' . $id);
    if (in_array($id, [7,8], true)) {
        $new = preg_replace_callback(
            '/(z-index\s*:\s*)10(\s*!important\s*;)/i',
            static fn($m) => $m[1] . '1' . $m[2],
            $new, -1, $wrapper
        );
        if ($wrapper !== 1) WP_CLI::error('Unexpected wrapper count on form ' . $id);
    }
    if (!is_string($new) || strpos($new, '999999') !== false) WP_CLI::error('Unsafe transformed CSS');
    $backup = $backup_dir . '/fluentform-' . $id . '-css-before-index-20261102.css';
    if (file_exists($backup)) {
        if (!is_file($backup) || filesize($backup) < 2000) WP_CLI::error('Existing CSS backup invalid.');
    } elseif (file_put_contents($backup, $original, LOCK_EX) !== strlen($original)) {
        WP_CLI::error('Cannot back up form ' . $id);
    }
    if ($wpdb->update($table, ['value' => $new], ['id' => $rows[0]->id], ['%s'], ['%d']) !== 1) {
        WP_CLI::error('Failed CSS update on form ' . $id);
    }
    $verify = $wpdb->get_var($wpdb->prepare("SELECT value FROM {$table} WHERE id=%d", $rows[0]->id));
    if ($verify !== $new) WP_CLI::error('CSS readback mismatch form ' . $id);
    WP_CLI::log('Form ' . $id . ': corrected only exaggerated stacking declarations');
}
WP_CLI::success('Fluent Forms stacking reconciled on Staging, preserving all other custom CSS.');
