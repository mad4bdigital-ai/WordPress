<?php
/** Reverts only profile authority to the untouched company option snapshot. */
defined('ABSPATH')&&defined('WP_CLI')&&WP_CLI||exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 WP_CLI::error('site_guard');
$apply=defined('MAD4B_VEHICLE_TERM_ROLLBACK')&&MAD4B_VEHICLE_TERM_ROLLBACK;
$backup=get_option('mad4b_vehicle_term_migration_backup_20261120_v1',[]);
$option=get_option(MAD4B_Transport_Distance_Time_V1::OPTION,[]);
if(!is_array($backup)||($backup['site']??'')!==$site||
  count($backup['created_term_ids']??[])!==6)
 WP_CLI::error('migration_backup_not_verified');
if(($option['source_sha256']??'')!==MAD4B_Transport_Distance_Time_V1::SHA ||
   ($option['currency']??'')!=='EGP')
 WP_CLI::error('company_option_changed');
$flag='mad4b_transport_vehicle_term_canonical_v1';
if(!$apply){
 echo wp_json_encode(['checkpoint'=>'SAFE_ROLLBACK_PREFLIGHT',
 'site'=>$site,'canonical'=>MAD4B_Transport_Vehicle_Term_Profiles_V1::active(),
 'term_records_kept'=>true,'current_rates_untouched'=>true])."\n";return;
}
wp_cache_delete('notoptions','options');
wp_cache_delete($flag,'options');
if(!delete_option($flag))WP_CLI::error('authority_option_not_removed');
wp_cache_delete('notoptions','options');
wp_cache_delete($flag,'options');
$policy=MAD4B_Transport_Distance_Time_V1::current();
if($policy['vehicle_profile_owner']!=='legacy_company_option')
 WP_CLI::error('legacy_authority_not_restored');
echo wp_json_encode(['checkpoint'=>'LEGACY_AUTHORITY_RESTORED',
 'site'=>$site,'term_meta_preserved'=>true,
 'transport_relations_preserved'=>true,'checkout_unchanged'=>true])."\n";
