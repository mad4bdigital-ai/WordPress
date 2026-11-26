<?php
/**
 * Reconcile Wayfatours native Transport Rates CPT definitions from Tours.dev
 * golden-source JetEngine fields, preserving its existing 26 original fields.
 * Does NOT copy missing legacy CCT records (no CCT exists on Wayfatours).
 */
defined('ABSPATH') && defined('WP_CLI') && WP_CLI || exit(2);
if(rtrim(home_url('/'),'/')!=='https://wayfatours.com' ||
   !defined('MAD4B_WAYFA_RATE_CPT_RECONCILE_APPROVED') ||
   MAD4B_WAYFA_RATE_CPT_RECONCILE_APPROVED!==true)
   WP_CLI::error('wayfa_production_approval_missing');
$fixture='/tmp/mad4b-transport-cpt-dimension-fields.json';
$source=json_decode((string)file_get_contents($fixture),true);
if(($source['contract']??'')!=='mad4b.transport-cpt-dimension-fields.v1' ||
   count($source['fields']??[])!==11)
   WP_CLI::error('source_field_fixture_invalid');
$names=['trans_vehicle_category_key','trans_service_kind','trans_route_pattern',
 'trans_schedule_model','trans_inventory_model','trans_service_arrangement',
 'trans_price_role','trans_cost_amount','trans_supplier_discount_pct',
 'trans_source_row_key','trans_review_status'];
$sourceNames=array_column($source['fields'],'name');
if($sourceNames!==$names)WP_CLI::error('source_schema_names_changed');
global $wpdb;
$table=$wpdb->prefix.'jet_post_types';
$row=$wpdb->get_row($wpdb->prepare(
 "SELECT id,status,meta_fields FROM {$table} WHERE slug=%s",'transport-rates'),ARRAY_A);
if(!$row||$row['status']!=='publish'||(int)$row['id']!==358 ||
   !get_post_type_object('transport-rates')) WP_CLI::error('rate_cpt_contract_changed');
$beforeRaw=$row['meta_fields'];
$original=(array)maybe_unserialize($beforeRaw);
$originalNames=array_column($original,'name');
$settings=get_option('icl_sitepress_settings',[]);
if(!is_array($settings))WP_CLI::error('wpml_settings_invalid');
$sync=$settings['custom_posts_sync_option']??[];
$fields=$settings['translation-management']['custom_fields_translation']??[];
$copy=array_merge($names,[
 '_booking_experience_transport_market_rates_v1',
 '_booking_experience_transport_extras_v1']);
$backupKey='mad4b_wayfa_transport_cpt_reconcile_backup_20261119_v1';
$backup=get_option($backupKey,false);
if(defined('MAD4B_WAYFA_RATE_CPT_ROLLBACK') && MAD4B_WAYFA_RATE_CPT_ROLLBACK) {
 if(!is_array($backup) || hash('sha256',$beforeRaw)!==$backup['after_hash'])
    WP_CLI::error('rollback_schema_conflict');
 foreach($backup['after_copy'] as $k=>$v)
    if(($fields[$k]??null)!==$v)WP_CLI::error('rollback_wpml_conflict_'.$k);
 if(($sync['transport-rates']??null)!==2)
    WP_CLI::error('rollback_wpml_mode_conflict');
 $changed=$wpdb->update($table,['meta_fields'=>$backup['before_raw']],
   ['id'=>(int)$row['id']],['%s'],['%d']);
 if($changed!==1)WP_CLI::error('rollback_field_update_failed');
 foreach($backup['before_copy'] as $k=>$v){
     if($v===null)unset($fields[$k]);else $fields[$k]=$v;
 }
 $sync['transport-rates']=$backup['before_mode'];
 $settings['custom_posts_sync_option']=$sync;
 $settings['translation-management']['custom_fields_translation']=$fields;
 update_option('icl_sitepress_settings',$settings);
 WP_CLI::log(wp_json_encode(['status'=>'rolled_back','field_count'=>count($original)]));
 return;
}
if(count($original)!==26||array_intersect($originalNames,$names))
    WP_CLI::error('unexpected_before_schema_or_partial_sync');
if(($sync['transport-rates']??null)!=1)
    WP_CLI::error('unexpected_wpml_before_mode');
$all=array_merge($original,$source['fields']);
if(count(array_unique(array_column($all,'name')))!==37)
    WP_CLI::error('cpt_field_name_collision');
$oldIds=array_column($original,'id');
foreach($source['fields'] as $field){
 if(in_array($field['id']??null,$oldIds,true))
    WP_CLI::error('jetengine_field_id_collision');
}
$modeBefore=$sync['transport-rates'];
$copyBefore=[];$copyAfter=[];
foreach($copy as $name){
 $copyBefore[$name]=$fields[$name]??null;
 $copyAfter[$name]=1;
}
$afterRaw=serialize($all);
$report=['status'=>'dry_run','site'=>home_url('/'),
 'cpt_fields_from'=>count($original),'cpt_fields_to'=>count($all),
 'wpml_mode_before'=>$modeBefore,'wpml_mode_after'=>2,
 'new_copy_fields'=>count($copy),'commercial_rate_changes'=>0,'rows_created'=>0];
if(!(defined('MAD4B_WAYFA_RATE_CPT_APPLY')&&MAD4B_WAYFA_RATE_CPT_APPLY)){
 WP_CLI::log(wp_json_encode($report));return;
}
if($backup!==false)WP_CLI::error('prior_migration_backup_present');
$admin=get_user_by('id',1);
if(!$admin||!user_can($admin,'manage_options'))WP_CLI::error('admin_rights_unavailable');
wp_set_current_user($admin->ID);
$b=['at'=>gmdate('c'),'before_raw'=>$beforeRaw,
 'after_hash'=>hash('sha256',$afterRaw),'before_mode'=>$modeBefore,
 'before_copy'=>$copyBefore,'after_copy'=>$copyAfter];
if(!add_option($backupKey,$b,'',false))WP_CLI::error('backup_failed');
$ok=$wpdb->update($table,['meta_fields'=>$afterRaw],['id'=>(int)$row['id']],
 ['%s'],['%d']);
if($ok!==1)WP_CLI::error('cpt_schema_write_failed');
$sync['transport-rates']=2;
foreach($copyAfter as $name=>$value)$fields[$name]=$value;
$settings['custom_posts_sync_option']=$sync;
$settings['translation-management']['custom_fields_translation']=$fields;
update_option('icl_sitepress_settings',$settings);
$read=get_option('icl_sitepress_settings',[]);
$check=$wpdb->get_var($wpdb->prepare(
 "SELECT meta_fields FROM {$table} WHERE id=%d",(int)$row['id']));
if(hash('sha256',$check)!==$b['after_hash'] ||
   ($read['custom_posts_sync_option']['transport-rates']??null)!==2)
    WP_CLI::error('post_write_verification_failed');
foreach($copyAfter as $name=>$v)
 if(($read['translation-management']['custom_fields_translation'][$name]??null)!==1)
    WP_CLI::error('wpml_field_copy_verification_failed_'.$name);
WP_CLI::log(wp_json_encode(['status'=>'applied','fields'=>37,'wpml_mode'=>2,
 'copy_fields'=>count($copyAfter),'original_field_count'=>26,
 'source_company'=>'Tours.dev','rate_post_mutations'=>0,
 'checkout_authorized'=>false,'rollback'=>$backupKey]));
