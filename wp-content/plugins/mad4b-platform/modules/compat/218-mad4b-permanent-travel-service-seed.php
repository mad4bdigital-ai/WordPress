<?php
/**
 * Plugin Name: MAD4B Permanent Accommodation and Transport Seed Contract
 * Description: Live-source evidence projection for required package and standalone travel service providers; never authorizes mutation or public checkout.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Permanent_Travel_Service_Seed_V1 {
 const CONTRACT='mad4b.travel-services-permanent-acceptance.v1';
 const FILE='company-travel-services-coverage.json';
 public static function status():array {
  $result=['contract'=>self::CONTRACT,'source_origin'=>untrailingslashit(home_url('/')),
   'required_domains'=>['accommodation','transport'],
   'required_service_surfaces'=>['tour_package_component','standalone_service'],
   'provider_schema_verified'=>false,'commercial_rates_authorized'=>false,
   'service_activation_changed'=>false,'source_code_proof_is_not_target_readback'=>true,
   'state'=>'SOURCE_DOMAIN_EVIDENCE_PENDING'];
  $profile=class_exists('MAD4B_SCP_Site_Profile',false)
   ?MAD4B_SCP_Site_Profile::status():[];
  if(untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com'
    ||!is_array($profile)||empty($profile['origin_match'])||empty($profile['environment_match']))
   return $result;
  $path=WPMU_PLUGIN_DIR.'/'.self::FILE;
  if(!is_file($path)||is_link($path)||!is_readable($path))return $result;
  $raw=file_get_contents($path,false,null,0,12000);
  $a=is_string($raw)?json_decode($raw,true):null;
  if(!is_array($a)||($a['contract']??'')!==self::CONTRACT
   ||($a['source_origin']??'')!=='https://tours.dev.mad4b.com'
   ||($a['schema_complete']??false)!==true
   ||($a['required_for_new_and_existing_companies']??false)!==true
   ||($a['mandatory_domains']??null)!==$result['required_domains']
   ||($a['supported_modes']??null)!==$result['required_service_surfaces']
   ||!preg_match('/^[a-f0-9]{64}$/D',(string)($a['bundle_catalog_sha256']??''))
   ||!preg_match('/^[a-f0-9]{64}$/D',(string)($a['domain_coverage_sha256']??'')))
   return $result+['reason'=>'MISSING_OR_INCONSISTENT_REQUIRED_DOMAINS'];
  $result['provider_schema_verified']=true;
  $result['domain_coverage_sha256']=$a['domain_coverage_sha256'];
  $result['bundle_catalog_sha256']=$a['bundle_catalog_sha256'];
  $result['state']='MANDATORY_SOURCE_DOMAINS_OBSERVED_RELEASE_READBACK_PENDING';
  return $result;
 }
}
