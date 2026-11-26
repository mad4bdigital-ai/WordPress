<?php
/**
 * Adds a nullable Tour Rate-level markup override to native JetEngine UI.
 * Blanks inherit; even 0 is a deliberate 0% override. This field never
 * changes WooCommerce/Fluent Form prices without a separately approved Rate.
 */
defined('ABSPATH')&&defined('WP_CLI')&&WP_CLI||exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 WP_CLI::error('site_guard');
$apply=defined('MAD4B_RATE_MARKUP_APPLY')&&MAD4B_RATE_MARKUP_APPLY;
$rollback=defined('MAD4B_RATE_MARKUP_ROLLBACK')&&MAD4B_RATE_MARKUP_ROLLBACK;
if($apply&&$rollback)WP_CLI::error('conflicting_mode');
global $wpdb;
$table=$wpdb->prefix.'jet_post_types';
$row=$wpdb->get_row($wpdb->prepare(
 "SELECT id,status,meta_fields FROM {$table} WHERE slug=%s",'tour-rates'),ARRAY_A);
if(!$row||$row['status']!=='publish'||(int)$row['id']!==328)
 WP_CLI::error('cpt_identity_changed');
$raw=(string)$row['meta_fields'];$fields=(array)maybe_unserialize($raw);
$expectedField='transport_markup_override_pct';
$present=array_values(array_filter($fields,
 static fn($f)=>($f['name']??'')===$expectedField));
if(count($present)>1)WP_CLI::error('duplicate_rate_override_field');
if(!in_array(count($fields),[52,53],true)||
   ($fields[50]['name']??'')!=='_mad4b_transport_stages_v1'||
   (count($fields)===53&&count($present)!==1)||
   (count($fields)===52&&count($present)!==0))
 WP_CLI::error('tour_rate_schema_drift');
if(count($present)===1&&($fields[52]['name']??'')!==$expectedField)
 WP_CLI::error('existing_rate_field_at_unexpected_position');
$add=['name'=>$expectedField,
 'title'=>'Transportation markup override (%)',
 'description'=>'Optional: blank inherits company/route/service/vehicle markup. 0 means explicit zero markup. Applies to draft transportation cost simulation only, never approves a sale price.',
 'type'=>'number','object_type'=>'field',
 'default_val'=>'','min_value'=>0,'max_value'=>200,
 'step_value'=>0.25,'id'=>930145];
if(!$present)$fields[]=$add;
$next=serialize($fields);
$backupKey='mad4b_tour_rate_markup_schema_backup_20261120_v1';
$backup=get_option($backupKey,false);
if($rollback){
 if(!is_array($backup)||($backup['site']??'')!==$site||
   hash('sha256',$raw)!==($backup['after_hash']??''))
  WP_CLI::error('rollback_changed_schema');
 if($wpdb->update($table,['meta_fields'=>$backup['before']],
    ['id'=>328],['%s'],['%d'])!==1)
  WP_CLI::error('rollback_db_failed');
 echo wp_json_encode(['state'=>'rolled_back','site'=>$site])."\n";return;
}
if(!$apply){
 echo wp_json_encode(['state'=>'dry_run','site'=>$site,
  'before'=>count($fields)-(int)!$present,
  'after'=>count($fields),'field'=>$expectedField,
  'sale_activation'=>false])."\n";return;
}
if(!$present){
 if($backup===false){
  if(!add_option($backupKey,['site'=>$site,'at'=>gmdate('c'),
    'before'=>$raw,'before_hash'=>hash('sha256',$raw),
    'after_hash'=>hash('sha256',$next)],'',false))
   WP_CLI::error('backup_create_failed');
 }elseif(($backup['after_hash']??'')!==hash('sha256',$next))
  WP_CLI::error('backup_schema_conflict');
 if($wpdb->update($table,['meta_fields'=>$next],['id'=>328],
    ['%s'],['%d'])!==1)
  WP_CLI::error('schema_write_failed');
}
$read=(string)$wpdb->get_var($wpdb->prepare(
 "SELECT meta_fields FROM {$table} WHERE id=%d",328));
if(hash('sha256',$read)!==hash('sha256',$next))
 WP_CLI::error('schema_readback_failed');
echo wp_json_encode(['state'=>'native_override_ready','site'=>$site,
 'fields'=>count($fields),'override_field'=>$expectedField,
 'changed_existing_rates'=>false,'commercial_price_approved'=>false,
 'backup'=>$backupKey])."\n";
