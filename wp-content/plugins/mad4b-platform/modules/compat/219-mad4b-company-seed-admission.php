<?php
/**
 * Plugin Name: MAD4B Company Live Demo Seed Admission
 * Description: Fail-closed seed completeness admission; read-only, never issues an execution grant.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Seed_Admission_V1 {
 const CONTRACT='mad4b.company-seed-admission.v1';
 const RECEIPT_OPTION='mad4b_company_live_seed_original_receipt_v1';
 public static function status():array {
  $result=['contract'=>self::CONTRACT,'source_origin'=>untrailingslashit(home_url('/')),
   'provisioning_mode'=>'live_demo_sealed_bundle_restore','default_empty_wordpress_install_allowed'=>false,
   'seed_complete'=>false,'site_profile_required_before_registration'=>true,
   'original_native_authority_created'=>false,'publication_authorized'=>false,
   'state'=>'SEALED_BUNDLE_AND_ORIGINAL_ACCEPTANCE_REQUIRED'];
  $profile=class_exists('MAD4B_SCP_Site_Profile',false)
   ?MAD4B_SCP_Site_Profile::status():[];
  if(untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com'
    ||!is_array($profile)||empty($profile['origin_match'])||empty($profile['environment_match']))
   return $result+['reason'=>'NOT_ORIGINAL_DEMO_SOURCE'];
  $inventoryFile=WPMU_PLUGIN_DIR.'/company-seed-inventory-summary.json';
  if(is_readable($inventoryFile) && !is_link($inventoryFile)) {
   $raw=file_get_contents($inventoryFile,false,null,0,16384);
   $inventory=is_string($raw)?json_decode($raw,true):null;
   if(is_array($inventory) &&
      ($inventory['contract']??'')==='mad4b.company-live-demo-seed-inventory-summary.v1' &&
      ($inventory['source_origin']??'')==='https://tours.dev.mad4b.com' &&
      ($inventory['actual_sealed_seed_created']??true)===false)
    $result['inventory']=$inventory; // Information only; never authorization.
  }
  $travel=class_exists('MAD4B_Permanent_Travel_Service_Seed_V1',false)
    ?MAD4B_Permanent_Travel_Service_Seed_V1::status():[];
  $result['permanent_travel_services']=$travel;
  if(($travel['provider_schema_verified']??false)!==true)
   return $result+['reason'=>'MANDATORY_ACCOMMODATION_AND_TRANSPORT_SOURCE_MISSING'];
  $record=get_option(self::RECEIPT_OPTION,[]);
  // This projection is not a new grant issuer. Only an independently certified
  // original provider release can mark the live seed complete.
  if(!is_array($record)||($record['contract']??'')!=='mad4b.company-seed-original-receipt.v1')
   return $result+['reason'=>'NO_ORIGINAL_SEALED_SEED_RECEIPT'];
  $digest=(string)($record['manifest_sha256']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$digest)||
     ($record['source_origin']??'')!=='https://tours.dev.mad4b.com'||
     ($record['bundle_coverage_verified']??false)!==true||
     ($record['domain_coverage_sha256']??'')!==($travel['domain_coverage_sha256']??'')||
     ($record['bundle_catalog_sha256']??'')!==($travel['bundle_catalog_sha256']??'')||
     ($record['package_and_standalone_service_engines_verified']??false)!==true||
     ($record['original_provider_certified']??false)!==true||
     !class_exists('MAD4B_Company_Seed_Original_Release_Provider_V1',false)||
     !method_exists('MAD4B_Company_Seed_Original_Release_Provider_V1','verify_receipt')||
     MAD4B_Company_Seed_Original_Release_Provider_V1::verify_receipt($record)!==true)
    return $result+['reason'=>'ORIGINAL_RELEASE_PROOF_UNAVAILABLE_OR_INVALID'];
  $result['seed_complete']=true;
  $result['manifest_sha256']=$digest;
  $result['state']='ORIGINAL_SEEDED_RELEASE_ACCEPTED';
  $result['reason']='VERIFIED_ORIGINAL_RELEASE_PROVIDER';
  return $result;
 }
 public static function eligible():bool {
  return self::status()['seed_complete']===true;
 }
}
