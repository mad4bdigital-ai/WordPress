<?php
/**
 * Correct two inverted Cairo vehicle-label assignments in *saved source meta*.
 * Original PDF amounts are not altered; proof is the immutable document SHA.
 * Backup covers each post ID and exact old/new meta, with guarded rollback.
 */
defined('ABSPATH')&&defined('WP_CLI')&&WP_CLI||exit(2);
$c='MAD4B_Transport_Self_Pricing_Controls_V1';
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 WP_CLI::error('site_guard_failed');
$apply=defined('MAD4B_CAIRO_COLUMNS_APPLY')&&MAD4B_CAIRO_COLUMNS_APPLY;
$rollback=defined('MAD4B_CAIRO_COLUMNS_ROLLBACK')&&MAD4B_CAIRO_COLUMNS_ROLLBACK;
if($apply&&$rollback)WP_CLI::error('conflicting_mode');
$src=$c::source();
if(!$src||($src['source_sha256']??'')!==$c::SHA)WP_CLI::error('pdf_fixture_failed');
$expected=[];$previous=[];
foreach($src['rows'] as $row){
 if($row['city']!=='Cairo')continue;
 $key=$row['source_key'];$new=$row['vehicle_rates_unverified'];
 $old=$new;
 $old['hiace']=$new['luxury_7_seats'];
 $old['luxury_7_seats']=$new['hiace'];
 $expected[$key]=$new;$previous[$key]=$old;
}
if(count($expected)!==13)WP_CLI::error('Cairo_count_changed');
$ids=get_posts([
 'post_type'=>'transportations','post_status'=>['draft','publish'],
 'posts_per_page'=>600,'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_sha256','meta_value'=>$c::SHA
]);
$byKey=[];$back=[];
foreach($ids as $id){
 $key=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',true);
 if(!isset($expected[$key]))continue;
 $lang=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_editorial_language',true);
 if($lang==='')WP_CLI::error('source_language_missing_'.$id);
 $byKey[$key][$lang]=(int)$id;
 $actual=get_post_meta($id,'_mad4b_transfer_pdf_2026_raw_vehicles',true);
 if($actual!==$previous[$key]&&$actual!==$expected[$key])
  WP_CLI::error('source_data_conflict_'.$id);
 $back[$id]=['source_key'=>$key,'lang'=>$lang,
  'before'=>$actual,'after'=>$expected[$key]];
}
$langs=$site==='https://wayfatours.com'?['en','es']:
 ['en','es','ar','fr','de','it'];
foreach($expected as $key=>$v){
 if(count($byKey[$key]??[])!==count($langs)||
    array_diff(array_keys($byKey[$key]),$langs))
  WP_CLI::error('translation_count_or_identity_mismatch_'.$key);
}
$backupKey='mad4b_cairo_pdf_vehicle_label_backup_20261120_v1';
$backed=get_option($backupKey,false);
if($rollback){
 if(!is_array($backed)||($backed['site']??'')!==$site)
  WP_CLI::error('rollback_backup_missing');
 foreach($backed['entries'] as $id=>$row){
  $current=get_post_meta((int)$id,'_mad4b_transfer_pdf_2026_raw_vehicles',true);
  if($current!==$row['after'])WP_CLI::error('rollback_conflict_'.$id);
 }
 foreach($backed['entries'] as $id=>$row)
  update_post_meta((int)$id,'_mad4b_transfer_pdf_2026_raw_vehicles',$row['before']);
 echo wp_json_encode(['status'=>'rolled_back','site'=>$site,
  'posts'=>count($backed['entries'])])."\n";return;
}
$pending=count(array_filter($back,static fn($r)=>$r['before']!==$r['after']));
if(!$apply){
 echo wp_json_encode(['status'=>'dry_run','site'=>$site,
  'Cairo_rows'=>count($expected),'translated_posts'=>count($back),
  'posts_needing_correction'=>$pending,'currency'=>'EGP',
  'supplier_cost_verified_by_owner'=>true])."\n";return;
}
if($backed===false){
 if(!add_option($backupKey,['site'=>$site,'created_at'=>gmdate('c'),
    'source_sha256'=>$c::SHA,'entries'=>$back],'',false))
  WP_CLI::error('backup_write_failed');
}elseif(!is_array($backed)||($backed['site']??'')!==$site)
 WP_CLI::error('backup_site_conflict');
$changed=0;
foreach($back as $id=>$row){
 if($row['before']===$row['after'])continue;
 update_post_meta((int)$id,'_mad4b_transfer_pdf_2026_raw_vehicles',$row['after']);
 if(get_post_meta((int)$id,'_mad4b_transfer_pdf_2026_raw_vehicles',true)!==$row['after'])
  WP_CLI::error('readback_failed_'.$id);
 $changed++;
}
echo wp_json_encode(['status'=>'corrected','site'=>$site,
 'rows'=>count($expected),'translated_posts'=>count($back),
 'corrected_posts'=>$changed,
 'backup'=>$backupKey,'original_pdf_modified'=>false,
 'commercial_rates_modified'=>false])."\n";
