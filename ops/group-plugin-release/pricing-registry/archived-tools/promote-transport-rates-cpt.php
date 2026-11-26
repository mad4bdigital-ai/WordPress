<?php
/**
 * Promote existing Transport Rates CPT as the JetEngine editing canonical home.
 *
 * Never creates a second CPT, and never removes or publishes a price.
 * Staging WP-CLI only. Apply: define MAD4B_TRANSPORT_CPT_APPLY=true.
 * Rollback: define MAD4B_TRANSPORT_CPT_ROLLBACK=true.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) exit(2);
if (rtrim(home_url('/'), '/') !== 'https://tours.dev.mad4b.com') WP_CLI::error('staging_only');
global $wpdb;
$postTypes = $wpdb->prefix . 'jet_post_types';
$cpt = $wpdb->get_row($wpdb->prepare(
    "SELECT id,slug,status,meta_fields FROM `{$postTypes}` WHERE slug=%s AND status=%s",
    'transport-rates', 'publish'), ARRAY_A);
$legacy = $wpdb->get_row($wpdb->prepare(
    "SELECT id,meta_fields FROM `{$postTypes}` WHERE slug=%s AND status=%s",
    'transport_rates', 'content-type'), ARRAY_A);
if (!$cpt || !$legacy || get_post_type_object('transport-rates') === null) {
    WP_CLI::error('native_transport_rate_sources_missing');
}
$fields = maybe_unserialize($cpt['meta_fields']);
$legacyFields = maybe_unserialize($legacy['meta_fields']);
if (!is_array($fields) || !is_array($legacyFields)) WP_CLI::error('schema_invalid');
$names = array(
 'trans_vehicle_category_key','trans_service_kind','trans_route_pattern',
 'trans_schedule_model','trans_inventory_model','trans_service_arrangement',
 'trans_price_role','trans_cost_amount','trans_supplier_discount_pct',
 'trans_source_row_key','trans_review_status'
);
$originalCpt = $cpt['meta_fields'];
$backupKey = 'mad4b_transport_cpt_promotion_backup_v1_20261119';
$backup = get_option($backupKey,false);
$keysCopy = array_merge($names, array(
 '_booking_experience_transport_market_rates_v1',
 '_booking_experience_transport_extras_v1'
));
$settings = get_option('icl_sitepress_settings', array());
if (!is_array($settings)) WP_CLI::error('wpml_settings_invalid');
$wpmlPostTypes = $settings['custom_posts_sync_option'] ?? array();
$wpmlCopy = $settings['translation-management']['custom_fields_translation'] ?? array();
if (!is_array($wpmlPostTypes) || !is_array($wpmlCopy)) WP_CLI::error('wpml_translation_contract_invalid');
if (defined('MAD4B_TRANSPORT_CPT_ROLLBACK') && MAD4B_TRANSPORT_CPT_ROLLBACK) {
    if (!is_array($backup) || empty($backup['before']) || empty($backup['after_hash']) ||
        hash('sha256',(string)$cpt['meta_fields']) !== (string)$backup['after_hash']) {
        WP_CLI::error('rollback_conflict');
    }
    // Fail closed on intervening translation configuration edits.
    foreach ($backup['after_fields'] as $key=>$value)
        if (($wpmlCopy[$key] ?? null) !== $value) WP_CLI::error('wpml_rollback_conflict_'.$key);
    if (($wpmlPostTypes['transport-rates']??null) !== $backup['after_post_type'])
        WP_CLI::error('wpml_rollback_post_type_conflict');
    $saved = $wpdb->update($postTypes,array('meta_fields'=>$backup['before']),
        array('id'=>(int)$cpt['id']),array('%s'),array('%d'));
    if ($saved !== 1) WP_CLI::error('rollback_cpt_failed');
    if (array_key_exists('before_post_type',$backup)) {
        $wpmlPostTypes['transport-rates']=$backup['before_post_type'];
    }
    foreach ($backup['before_fields'] as $name=>$prior) {
        if ($prior === null) unset($wpmlCopy[$name]);
        else $wpmlCopy[$name] = $prior;
    }
    $settings['custom_posts_sync_option'] = $wpmlPostTypes;
    $settings['translation-management']['custom_fields_translation'] = $wpmlCopy;
    update_option('icl_sitepress_settings',$settings);
    WP_CLI::log(wp_json_encode(array('status'=>'rolled_back','field_count'=>count(maybe_unserialize($backup['before'])))));
    return;
}
$byName = array();
foreach ($legacyFields as $field) if (isset($field['name'])) $byName[$field['name']] = $field;
$oldNames = array_column($fields,'name');
$added = array();
foreach ($names as $index=>$name) {
    if (in_array($name,$oldNames,true)) continue;
    if (!isset($byName[$name])) WP_CLI::error('legacy_field_missing_'.$name);
    $field = $byName[$name];
    $field['id'] = 99701+$index;
    $field['isNested'] = false;
    $fields[] = $field;
    $added[] = $name;
}
if ($added && count($added)!==count($names)) WP_CLI::error('partial_cpt_schema_requires_manual_reconcile');
$legacyRows = $wpdb->get_results("SELECT _ID FROM {$wpdb->prefix}jet_cct_transport_rates ORDER BY _ID ASC LIMIT 500",ARRAY_A);
$linked = array();
foreach ($legacyRows as $item) {
    $cctId=(int)$item['_ID'];
    $found=get_posts(array('post_type'=>'transport-rates','post_status'=>'any','posts_per_page'=>20,
      'fields'=>'ids','meta_key'=>'source_cct_id','meta_value'=>(string)$cctId,'suppress_filters'=>true));
    $linked[$cctId]=$found;
    if (count($found)>1) WP_CLI::error('duplicate_cct_migration_'.$cctId);
}
$delta = array();
foreach ($keysCopy as $name) if (($wpmlCopy[$name]??null)!==1) $delta[$name]=array('old'=>$wpmlCopy[$name]??null,'new'=>1);
if (($wpmlPostTypes['transport-rates']??null)!=2) $delta['post_type']=array('old'=>$wpmlPostTypes['transport-rates']??null,'new'=>2);
$report=array('status'=>'dry_run','canonical'=>'transport-rates','cpt_id'=>(int)$cpt['id'],
 'add_fields'=>$added,'cpt_fields_before'=>count(maybe_unserialize($originalCpt)),
 'cpt_fields_after'=>count($fields),
 'cct_legacy_records'=>count($legacyRows),'legacy_post_links'=>$linked,
 'wpml_changes'=>$delta,'pricing_activated'=>false,'records_created'=>0);
if (!(defined('MAD4B_TRANSPORT_CPT_APPLY') && MAD4B_TRANSPORT_CPT_APPLY)) {
    WP_CLI::log(wp_json_encode($report));
    return;
}
if ($backup!==false) WP_CLI::error('promotion_backup_already_exists');
$operator=get_user_by('id',1);
if (!$operator||!user_can($operator,'manage_options')) WP_CLI::error('staging_administrator_required');
wp_set_current_user((int)$operator->ID);
$afterRaw=serialize($fields);
$previous=array();
$updated=array();
foreach ($keysCopy as $name) {
    $previous[$name]=$wpmlCopy[$name]??null;
    $wpmlCopy[$name]=1; $updated[$name]=1;
}
$prevType=$wpmlPostTypes['transport-rates']??null;
$wpmlPostTypes['transport-rates']=2;
$record=array('before'=>$originalCpt,'after_hash'=>hash('sha256',$afterRaw),
 'before_post_type'=>$prevType,'after_post_type'=>2,
 'before_fields'=>$previous,'after_fields'=>$updated,'at'=>gmdate('c'));
if (!add_option($backupKey,$record,'',false)) WP_CLI::error('backup_failed');
$dbChange=$wpdb->update($postTypes,array('meta_fields'=>$afterRaw),array('id'=>(int)$cpt['id']),array('%s'),array('%d'));
if ($dbChange !== 1) WP_CLI::error('cpt_schema_write_failed_backup_preserved');
$settings['custom_posts_sync_option']=$wpmlPostTypes;
$settings['translation-management']['custom_fields_translation']=$wpmlCopy;
update_option('icl_sitepress_settings',$settings);
$persisted=get_option('icl_sitepress_settings',array());
if (($persisted['custom_posts_sync_option']['transport-rates']??null)!=2)
    WP_CLI::error('wpml_post_type_persistence_failed');
foreach ($keysCopy as $name) {
    if (($persisted['translation-management']['custom_fields_translation'][$name]??null)!==1)
        WP_CLI::error('wpml_field_persistence_failed_'.$name);
}
WP_CLI::log(wp_json_encode(array('status'=>'applied','cpt_fields'=>count($fields),
 'added'=>count($added),'wpml_fallback_mode'=>2,'linked_legacy_records'=>count($linked),
 'rate_posts_created'=>0,'price_mutations'=>false,'checkout_changed'=>false)));
