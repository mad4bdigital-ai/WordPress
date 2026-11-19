<?php
/**
 * Additive JetEngine CCT transport pricing dimensions.
 * Run only with authenticated WP-CLI on Tours.dev staging.
 * Default dry-run; define MAD4B_TRANSPORT_CCT_APPLY or _ROLLBACK to change schema.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) exit(2);
if (rtrim(home_url('/'), '/') !== 'https://tours.dev.mad4b.com') WP_CLI::error('staging_only');
global $wpdb;
// JetEngine Base_DB explicitly requires manage_options for native ALTER TABLE.
// WP-CLI's default user is 0, so set a validated staging administrator only.
$operator = get_user_by('id', 1);
if (!$operator || !user_can($operator, 'manage_options')) WP_CLI::error('native_admin_unavailable');
wp_set_current_user((int) $operator->ID);
if (!current_user_can('manage_options')) WP_CLI::error('native_admin_required');
$recordTable = $wpdb->prefix . 'jet_post_types';
$row = $wpdb->get_row($wpdb->prepare(
    "SELECT id,slug,meta_fields,args,status FROM `{$recordTable}` WHERE slug=%s AND status=%s",
    'transport_rates','content-type'), ARRAY_A);
if (!$row) WP_CLI::error('transport_cct_definition_missing');
$cctTable = $wpdb->prefix . 'jet_cct_transport_rates';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cctTable)) !== $cctTable) WP_CLI::error('transport_cct_table_missing');
$beforeRaw = (string) $row['meta_fields'];
$before = maybe_unserialize($beforeRaw);
if (!is_array($before) || count($before) < 20) WP_CLI::error('invalid_original_cct_schema');
$backupKey = 'mad4b_transport_cct_expansion_backup_v1_20261119';
$backup = get_option($backupKey, false);
$data = Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->data;
$fieldsSpec = array(
 'trans_vehicle_category_key' => array('Vehicle Category', 'select', 'limousine|luxury_7_seats|hiace|coaster|bus|sedan|suv|mpv|minibus|4x4_jeep|speedboat|other'),
 'trans_service_kind' => array('Transport Service Kind', 'select', 'airport_transfer|station_transfer|intercity_transfer|point_to_point|half_day_disposal|full_day_disposal|sightseeing_disposal|multi_day_transport|shared_shuttle|rental|charter|overnight_extra|package_bundle|other'),
 'trans_route_pattern' => array('Route Pattern', 'select', 'point_to_point|fixed_route|multi_stop|open_area|circular'),
 'trans_schedule_model' => array('Schedule Model', 'select', 'fixed_schedule|on_demand|time_slots|all_day'),
 'trans_inventory_model' => array('Inventory Model', 'select', 'seat|vehicle|cabin|ticket|none'),
 'trans_service_arrangement' => array('Service Arrangement', 'select', 'private|shared|chauffeured|self_drive'),
 'trans_price_role' => array('Price Role', 'select', 'unknown|cost|selling'),
 'trans_cost_amount' => array('Confirmed Transport Cost', 'number', ''),
 'trans_supplier_discount_pct' => array('Source Discount %', 'number', ''),
 'trans_source_row_key' => array('Source Row Reference', 'text', ''),
 'trans_review_status' => array('Pricing Review Status', 'select', 'pending_review|validated|rejected'),
);
$existingNames = array_column($before,'name');
$newFields = $before;
$add = array();
$i = 0;
foreach ($fieldsSpec as $name => $spec) {
    if (in_array($name,$existingNames,true)) continue;
    ++$i;
    $field = array(
        'title'=>$spec[0], 'name'=>$name, 'object_type'=>'field', 'width'=>'33.33333%',
        'options'=>array(), 'repeater-fields'=>array(), 'type'=>$spec[1],
        'id'=>98600+$i, 'isNested'=>false, 'options_source'=>$spec[1]==='select'?'manual_bulk':'manual',
    );
    if ($spec[1] === 'select') $field['bulk_options'] = str_replace('|',"\n",$spec[2]);
    if ($spec[1] === 'number') $field['step_value'] = '0.01';
    $newFields[] = $field;
    $add[] = $name;
}
if (defined('MAD4B_TRANSPORT_CCT_ROLLBACK') && MAD4B_TRANSPORT_CCT_ROLLBACK) {
    if (!is_array($backup) || empty($backup['before']) || empty($backup['after_hash'])
        || hash('sha256', $beforeRaw) !== $backup['after_hash']) WP_CLI::error('rollback_version_conflict');
    $old = maybe_unserialize($backup['before']);
    $data->before_item_update(array('id'=>(int)$row['id']));
    $dbUpdated = $wpdb->update($recordTable,array('meta_fields'=>$backup['before']),array('id'=>(int)$row['id']),array('%s'),array('%d'));
    if ($dbUpdated !== 1) WP_CLI::error('rollback_schema_definition_failed');
    $data->after_item_update(array('slug'=>'transport_rates','meta_fields'=>$old,'args'=>maybe_unserialize($row['args'])));
    echo wp_json_encode(array('status'=>'rolled_back','fields_restored'=>count($old))); return;
}
if (!$add) { echo wp_json_encode(array('status'=>'already_expanded','field_count'=>count($before))); return; }
if (count($add) !== count($fieldsSpec)) WP_CLI::error('partial_existing_extension_requires_manual_reconcile');
$nativeSchema = $data->get_sql_columns_from_fields($newFields);
foreach ($add as $name) if (!isset($nativeSchema[$name])) WP_CLI::error('native_schema_field_missing_'.$name);
if (!(defined('MAD4B_TRANSPORT_CCT_APPLY') && MAD4B_TRANSPORT_CCT_APPLY)) {
    echo wp_json_encode(array('status'=>'dry_run','add_fields'=>$add,
       'native_sql_types'=>array_intersect_key($nativeSchema,array_fill_keys($add,true)))); return;
}
$afterRaw = serialize($newFields);
$backupPayload = array('before'=>$beforeRaw,'after_hash'=>hash('sha256',$afterRaw),
  'changed_at'=>gmdate('c'),'added'=>$add);
if ($backup === false) {
    if (!add_option($backupKey,$backupPayload,'',false)) WP_CLI::error('backup_failed');
} elseif (($backup['before'] ?? '') !== $beforeRaw || ($backup['after_hash'] ?? '') !== $backupPayload['after_hash']) {
    WP_CLI::error('prior_backup_does_not_match_current_preflight');
}
$data->before_item_update(array('id'=>(int)$row['id']));
$updated = $wpdb->update($recordTable,array('meta_fields'=>$afterRaw),array('id'=>(int)$row['id']),array('%s'),array('%d'));
if ($updated !== 1) WP_CLI::error('cct_metadata_update_failed_backup_preserved');
$data->after_item_update(array('slug'=>'transport_rates','meta_fields'=>$newFields,'args'=>maybe_unserialize($row['args'])));
$columns = array_column($wpdb->get_results("SHOW COLUMNS FROM `{$cctTable}`",ARRAY_A),'Field');
$missing = array_values(array_diff($add,$columns));
if ($missing) WP_CLI::error('native_cct_column_creation_incomplete:'.implode(',',$missing));
echo wp_json_encode(array('status'=>'applied','added_count'=>count($add),'columns_verified'=>count($add),
    'existing_rows'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$cctTable}`")));
