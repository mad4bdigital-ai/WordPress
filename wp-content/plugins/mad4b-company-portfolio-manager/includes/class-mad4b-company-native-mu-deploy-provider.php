<?php
defined('ABSPATH') || exit;
/**
 * Discoverable Original 305 MU-file deployment capability.
 * No fallback to plugin.install and no inferred authorization from transport.
 * An exact root-worker capability must be published through the original
 * Broker before this operation can be submitted to 305.
 */
final class MAD4B_Company_Native_MU_Deploy_Provider_V1 {
 const CONTRACT='mad4b.company-native-mu-deploy-provider.v1';
 const EVIDENCE='mad4b.company-mu-deploy-exact-handler.v1';
 const PROVIDER='mad4b_company_mu_deploy_exact_handler';
 public static function readback():array {
  $out=['contract'=>self::EVIDENCE,'operation'=>'mu-plugin.deploy',
    'provider'=>self::PROVIDER,'authority_family'=>'original_305',
    'provider_ready'=>false,'state'=>'NATIVE_MU_DEPLOY_HANDLER_NOT_READY',
    'native_305_issued'=>false,'signed_312_verified'=>false,
    'original_313_certified'=>false,'target_mutated'=>false];
  if(wp_get_environment_type()!=='staging'
     ||untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
   return $out+['reason'=>'CENTRAL_STAGING_ONLY'];
  $handler='MAD4B_Company_Native_MU_Deploy_Handler_V1';
  if(!class_exists($handler,false)||!is_callable([$handler,'capability_status']))
   return $out+['reason'=>'TRUSTED_MU_HANDLER_NOT_INSTALLED'];
  try {
   $reflection=new ReflectionMethod($handler,'capability_status');
   $file=$reflection->getFileName();
   $root=realpath(WP_PLUGIN_DIR.'/mad4b-company-portfolio-manager');
   $real=is_string($file)?realpath($file):false;
   if(!$reflection->isPublic()||!$reflection->isStatic()
      ||!is_string($root)||!is_string($real)
      ||!str_starts_with($real,$root.'/')||is_link($file)
      ||fileowner($real)!==0||(fileperms($real)&0022)!==0)
    return $out+['reason'=>'UNTRUSTED_NATIVE_MU_HANDLER'];
   $cap=$handler::capability_status();
  }catch(Throwable $exception){
   return $out+['reason'=>'NATIVE_MU_HANDLER_CHECK_FAILED'];
  }
  if(!is_array($cap)
     ||($cap['contract']??null)!=='mad4b.native-312-mu-plugin-deploy-capability.v1'
     ||($cap['operation']??null)!=='mu-plugin.deploy'
     ||($cap['provider']??null)!==self::PROVIDER
     ||($cap['transport_operation']??null)!=='company.remote.wpcli.full'
     ||($cap['handler_ready']??null)!==true
     ||($cap['target_wpcli_verified']??null)!==true
     ||($cap['readback_supported']??null)!==true
     ||($cap['rollback_supported']??null)!==true
     ||!is_int($cap['heartbeat_epoch']??null)
     ||$cap['heartbeat_epoch']>time()+10
     ||$cap['heartbeat_epoch']<time()-180)
   return $out+['reason'=>'SIGNED_NATIVE_MU_CAPABILITY_STALE_OR_INVALID'];
  $out['provider_ready']=true;
  $out['state']='MU_DEPLOY_CAPABLE_ORIGINAL_305_JOB_REQUIRED';
  return $out;
 }
 public static function register(string $registry):void {
  if($registry!=='MAD4B_Original_Native_Operation_Providers_V1'
     ||!class_exists($registry,false))return;
  $registry::register([
   'operation'=>'mu-plugin.deploy',
   'provider'=>self::PROVIDER,
   'authority_family'=>'original_305',
   'target_scope'=>'wordpress',
   'command_format'=>'wpcli.argv.json.v1',
   'evidence_contract'=>self::EVIDENCE,
   'readback'=>[__CLASS__,'readback']
  ]);
 }
 public static function boot():void {
  add_action('mad4b_original_native_operation_providers_register',[__CLASS__,'register'],12,1);
  // Some sites initialize the original dynamic Registry before this module
  // loads; registering immediately is safe and avoids a silent missed hook.
  if(class_exists('MAD4B_Original_Native_Operation_Providers_V1',false))
   self::register('MAD4B_Original_Native_Operation_Providers_V1');
 }
}
MAD4B_Company_Native_MU_Deploy_Provider_V1::boot();
