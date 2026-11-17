<?php
defined('ABSPATH') || exit;
/**
 * Original 305 lifecycle plan for one MU file.
 * This endpoint only reads evidence; no plan or capability issues a grant.
 */
final class MAD4B_Company_MU_Deploy_Lifecycle_V1 {
 const CONTRACT='mad4b.company-mu-deploy-lifecycle.v1';
 public static function status(string $company,string $environment,string $file,string $sha):array {
  $out=['contract'=>self::CONTRACT,'company'=>$company,'environment'=>$environment,
   'artifact'=>$file,'source_sha256'=>$sha,'site_uuid'=>null,
   'canonical_origin'=>null,'state'=>'NOT_READY','blockers'=>[],
   'provider'=>'mu-plugin.deploy','provider_ready'=>false,
   'transport_ready'=>false,'source_verified'=>false,
   'target_inventory_verified'=>false,'job_305_issued'=>false,
   'broker_306_claimed'=>false,'executor_312_verified'=>false,
   'independent_313_verified'=>false,'production_mutated'=>false,
   'next_action'=>'inspect_source_and_site_binding'];
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
    ||!in_array($environment,['staging','production'],true)
    ||!preg_match('/^[a-z0-9][a-z0-9-]{2,120}\.php$/D',$file)
    ||!preg_match('/^[a-f0-9]{64}$/D',$sha))
   return self::failure($out,'INVALID_EXACT_ARTIFACT_OR_SCOPE');
  if(!class_exists('MAD4B_Company_Environment_Registry_V2',false))
   return self::failure($out,'COMPANY_REGISTRY_UNAVAILABLE');
  $site=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
  if(!is_array($site)||!wp_is_uuid((string)($site['site_uuid']??'')))
   return self::failure($out,'EXACT_SITE_BINDING_NOT_FOUND');
  $out['site_uuid']=$site['site_uuid'];
  $out['canonical_origin']=$site['canonical_origin']??null;
  if(wp_get_environment_type()!=='staging'
     ||untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
   return self::failure($out,'CANONICAL_SOURCE_STAGING_REQUIRED');
  $source=WP_CONTENT_DIR.'/mu-plugins/'.$file;
  if(is_link($source)||!is_file($source)||!is_readable($source)
    ||!hash_equals($sha,(string)hash_file('sha256',$source)))
   return self::failure($out,'SOURCE_ARTIFACT_HASH_MISMATCH');
  $out['source_verified']=true;
  $registry='MAD4B_Original_Native_Operation_Providers_V1';
  if(!class_exists($registry,false))
   return self::failure($out,'ORIGINAL_PROVIDER_REGISTRY_NOT_LOADED');
  $original=$registry::profile('company.remote.wpcli.full');
  $out['transport_ready']=is_array($original)
   && $registry::legacy_admitted('company.remote.wpcli.full',
        'wordpress','wpcli.argv.json.v1');
  $profile=$registry::profile('mu-plugin.deploy');
  $out['provider_ready']=is_array($profile)
   && $registry::legacy_admitted('mu-plugin.deploy',
        'wordpress','wpcli.argv.json.v1');
  if(!$out['transport_ready'])
   return self::failure($out,'ORIGINAL_305_TRANSPORT_NOT_READY');
  if(!$out['provider_ready'])
   return self::failure($out,'EXACT_NATIVE_312_MU_DEPLOY_PROVIDER_NOT_READY');
  $out['state']='READY_FOR_EXACT_ORIGINAL_305_JOB';
  $out['next_action']='issue_original_signed_305_mu_plugin_job';
  return $out;
 }
 private static function failure(array $out,string $code):array {
  $out['blockers']=[$code];
  $out['state']='BLOCKED_FOR_THIS_OPERATION_ONLY';
  $out['next_action']='inspect_'.$code;
  return $out;
 }
 public static function cli($args,$assoc):void {
  if(($args[0]??'')!=='status' || count($args)!==1
     ||array_diff(array_keys($assoc),['company','environment','file','sha256'])
     ||!current_user_can('manage_options'))
   WP_CLI::error('OPERATOR_AND_EXACT_MU_STATUS_ARGUMENTS_REQUIRED');
  $result=self::status((string)($assoc['company']??''),
    (string)($assoc['environment']??''),(string)($assoc['file']??''),
    (string)($assoc['sha256']??''));
  WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b mu-plugin-lifecycle',[__CLASS__,'cli']);
 }
}
MAD4B_Company_MU_Deploy_Lifecycle_V1::boot();
