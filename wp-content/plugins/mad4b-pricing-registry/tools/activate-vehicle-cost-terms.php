<?php
/** Guarded activation of term-owned EGP vehicle profiles; rollback by option deletion. */
defined('ABSPATH')&&defined('WP_CLI')&&WP_CLI||exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 WP_CLI::error('site_mismatch');
$apply=defined('MAD4B_VEHICLE_COST_ACTIVATE')&&MAD4B_VEHICLE_COST_ACTIVATE;
$c='MAD4B_Transport_Vehicle_Term_Profiles_V1';
$m='MAD4B_Transport_Distance_Time_V1';
$backup=get_option('mad4b_vehicle_term_migration_backup_20261120_v1',[]);
if(!is_array($backup)||($backup['site']??'')!==$site||
   count($backup['created_term_ids']??[])!==6)
 WP_CLI::error('migration_backup_missing');
$legacy=get_option($m::OPTION,[]);
if(!is_array($legacy)||($legacy['source_sha256']??'')!==$m::SHA||
   ($legacy['currency']??'')!=='EGP'||($legacy['role']??'')!=='self_cost')
 WP_CLI::error('original_company_cost_invalid');
$taxonomy=get_taxonomy($c::TAXONOMY);
if(!$taxonomy||$taxonomy->public||$taxonomy->publicly_queryable||
  $taxonomy->show_in_rest)
 WP_CLI::error('taxonomy_not_admin_private');
$results=[];
foreach($c::KEY_TO_SLUG as $key=>$slug){
 $value=$c::profile($key);
 if(is_wp_error($value))WP_CLI::error($value->get_error_code());
 if((int)$value['term_id']!==(int)$backup['created_term_ids'][$key]||
    $value['profile']!==$legacy['profiles'][$key])
  WP_CLI::error('term_cost_mismatch_'.$key);
 $results[$key]=$value['term_id'];
}
$flag='mad4b_transport_vehicle_term_canonical_v1';
if(!$apply){
 echo wp_json_encode(['checkpoint'=>'MATCHED_READY','site'=>$site,
 'terms'=>$results,'source_owner'=>'company_option',
 'terms_equal_legacy'=>true,'checkout_authorized'=>false])."\n";return;
}
if(!get_option($flag,false)&&!add_option($flag,true,'',false))
 WP_CLI::error('canonical_flag_write_failed');
// Clear only the option-not-found cache for a persisted newly created flag.
wp_cache_delete('notoptions','options');
wp_cache_delete($flag,'options');
$read=$m::current();
if(($read['vehicle_profile_owner']??'')!=='jetengine_taxonomy_term_meta'){
 delete_option($flag);
 WP_CLI::error('canonical_readback_not_active');
}
foreach($legacy['profiles'] as $key=>$profile)
 if($read['profiles'][$key]!==$profile){
  delete_option($flag);
  WP_CLI::error('canonical_profile_mismatch_'.$key);
 }
update_option('mad4b_vehicle_term_activation_receipt_v1',[
 'site'=>$site,'at'=>gmdate('c'),
 'legacy_revision'=>$legacy['revision'],'term_ids'=>$results,
 'policy_unchanged'=>true,'checkout_authorized'=>false],false);
echo wp_json_encode(['checkpoint'=>'CANONICAL_TERM_META','site'=>$site,
 'owner'=>$read['vehicle_profile_owner'],'term_ids'=>$results,
 'currency'=>'EGP','commercial_rates_changed'=>false])."\n";
