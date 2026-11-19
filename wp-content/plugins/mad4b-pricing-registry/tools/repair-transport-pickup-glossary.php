<?php
/**
 * Run with WP-CLI against the selected company.
 * Dry-run: wp eval-file tools/repair-transport-pickup-glossary.php
 * Apply: wp eval 'define("MAD4B_TRANSPORT_APPLY",true);require "FULL_PATH";'
 * Rollback: wp eval 'define("MAD4B_TRANSPORT_ROLLBACK",true);require "FULL_PATH";'
 * Never changes other CPT definitions or published rates.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) exit(2);
global $wpdb;
$table = $wpdb->prefix . 'jet_post_types';
$row = $wpdb->get_row($wpdb->prepare("SELECT id,meta_fields FROM `{$table}` WHERE slug=%s AND status=%s", 'transportations', 'publish'), ARRAY_A);
if (!$row) { WP_CLI::error('transportations_schema_missing'); return; }
$backupKey = 'mad4b_transport_pickup_glossary_backup_20261119_v1';
$backup = get_option($backupKey, false);
$current = (string) $row['meta_fields'];
if (defined('MAD4B_TRANSPORT_ROLLBACK') && MAD4B_TRANSPORT_ROLLBACK) {
    if (!is_array($backup) || !isset($backup['before'], $backup['after_hash'])
        || hash('sha256', $current) !== $backup['after_hash']) WP_CLI::error('rollback_version_conflict');
    $ok = $wpdb->update($table, array('meta_fields' => $backup['before']),
        array('id' => (int) $row['id']), array('%s'), array('%d'));
    if ($ok !== 1) WP_CLI::error('rollback_failed');
    echo wp_json_encode(array('status'=>'rolled_back','schema_id'=>(int)$row['id']));
    return;
}
$fields = maybe_unserialize($current);
if (!is_array($fields)) WP_CLI::error('schema_serialization_invalid');
$changes = array();
foreach ($fields as &$field) {
    if (!in_array($field['name'] ?? '', array('trans_pickup_type', 'trans_dropoff_type'), true)) continue;
    if (($field['type'] ?? '') !== 'select' || ($field['options_source'] ?? '') !== 'glossary') WP_CLI::error('unexpected_field_contract');
    $old = $field['glossary_id'] ?? '';
    if ((string) $old !== '340') {
        if ($old !== '' && $old !== null) WP_CLI::error('unexpected_existing_glossary');
        $changes[] = array('field'=>$field['name'],'old'=>$old,'new'=>'340');
        $field['glossary_id'] = '340';
    }
}
unset($field);
if (count($changes) === 0) { echo wp_json_encode(array('status'=>'already_aligned')); return; }
if (count($changes) !== 2) WP_CLI::error('partial_schema_preflight_failed');
if (!(defined('MAD4B_TRANSPORT_APPLY') && MAD4B_TRANSPORT_APPLY)) {
    echo wp_json_encode(array('status'=>'dry_run','changes'=>$changes));
    return;
}
if ($backup !== false) WP_CLI::error('previous_backup_exists');
$after = serialize($fields);
$record = array('before'=>$current,'after_hash'=>hash('sha256',$after),
    'changed'=>gmdate('c'),'changes'=>$changes);
if (!add_option($backupKey,$record,'',false)) WP_CLI::error('backup_write_failed');
$done = $wpdb->update($table,array('meta_fields'=>$after),array('id'=>(int)$row['id']),array('%s'),array('%d'));
if ($done !== 1) WP_CLI::error('schema_update_failed_backup_preserved');
echo wp_json_encode(array('status'=>'applied','schema_id'=>(int)$row['id'],'changes'=>$changes));
