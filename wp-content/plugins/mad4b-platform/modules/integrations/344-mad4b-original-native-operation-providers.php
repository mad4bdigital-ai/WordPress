<?php
/**
 * Plugin Name: MAD4B Original Native Operation Provider Registry
 * Description: Extensible operation names with source-verified providers; discovery never grants execution.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Original_Native_Operation_Providers_V1 {
 const CONTRACT='mad4b.original-native-operation-providers.v1';
 const MAX_PROVIDERS=128;
 private static $providers=[];
 private static $registered=false;
 private static function canonical($value):bool {
  return is_string($value) && strlen($value)<=120
    && preg_match('/^[a-z][a-z0-9._-]{2,119}$/D',$value)===1;
 }
 private static function trusted_callback($cb):bool {
  if(!is_array($cb) || count($cb)!==2
      || !is_string($cb[0]) || !is_string($cb[1])
      || !is_callable($cb))return false;
  try {
   $m=new ReflectionMethod($cb[0],$cb[1]);
   if(!$m->isPublic() || !$m->isStatic())return false;
   $file=$m->getFileName();
   if(!is_string($file) || $file==='' || is_link($file))return false;
   $resolved=realpath($file);
   // Only root-owned release code can provide privileged readback.
   // The central platform is now a native plugin, not only a MU plugin.
   // Resolve its canonical modules root from this source file; never trust
   // caller-supplied paths or arbitrary third-party plugin callbacks.
   // MAD4B modules now live in separate first-party plugins. Do not trust
   // arbitrary WP_PLUGIN_DIR children or any user-selected provider path.
   // Only known, root-owned release roots may register Native callbacks.
   $roots=[
     realpath(dirname(__DIR__)),
     realpath(WP_PLUGIN_DIR.'/mad4b-company-portfolio-manager'),
     realpath(WP_PLUGIN_DIR.'/mad4b-lifecycle-orchestrator'),
     realpath(WPMU_PLUGIN_DIR),
   ];
   $trusted_root=null;
   foreach($roots as $root){
     if(is_string($resolved) && is_string($root)
       && str_starts_with($resolved,$root.'/')){$trusted_root=$root;break;}
   }
   if($trusted_root===null || fileowner($resolved)!==0
     || (fileperms($resolved)&0022)!==0)return false;
   // The callback and every directory beneath its known release root must
   // be owned by root and not writable by group/others. Any migrated
   // plugin not satisfying this stays discovery-only, never a provider.
   for($dir=dirname($resolved);;$dir=dirname($dir)){
     if(is_link($dir) || fileowner($dir)!==0
       || (fileperms($dir)&0022)!==0)return false;
     if($dir===$trusted_root)break;
     if($dir==='/' || $dir==='.')return false;
   }
   return true;
  } catch(Throwable $e){return false;}
 }
 public static function register(array $profile):bool {
  if(count(self::$providers)>=self::MAX_PROVIDERS)return false;
  $required=['operation','provider','authority_family','target_scope','command_format','evidence_contract','readback'];
  if(array_keys($profile)!==$required
      || !self::canonical($profile['operation']??null)
      || !self::canonical($profile['provider']??null)
      || !in_array($profile['authority_family']??'',
          ['original_305','original_new_site_bootstrap','certified_operation'],true)
      || !in_array($profile['target_scope']??'',['host','wordpress','new_site'],true)
      || !is_string($profile['command_format']??null)
      || !preg_match('/^[a-z][a-z0-9._*-]{0,79}$/D',(string)$profile['command_format'])
      || !self::canonical($profile['evidence_contract']??null)
      || !self::trusted_callback($profile['readback']??null)
      || isset(self::$providers[$profile['operation']]))
    return false;
  // Never let a new operation borrow the compatibility-only readiness
  // callback for original 305 host shell / enrolled WP-CLI.
  if($profile['authority_family']==='original_305'
      && $profile['readback']===[__CLASS__,'legacy_ready']
      && !in_array($profile['operation'],
          ['maintenance.shell','company.remote.wpcli.full'],true))return false;
  // The provider registration must come from a trusted root-owned
  // implementation. WordPress options and caller JSON cannot add operations.
  self::$providers[$profile['operation']]=$profile;
  return true;
 }
 public static function legacy_ready():array {
  return ['provider_ready'=>true,'contract'=>'mad4b.original-305-exact-enrolled-grant.v1'];
 }
 public static function legacy_needs_verified_provider():array {
  return ['provider_ready'=>false,'contract'=>'mad4b.original-305-exact-enrolled-grant.v1',
    'reason'=>'ORIGINAL_NATIVE_PROVIDER_NOT_BOUND'];
 }
 private static function ensure_registered():void {
  if(self::$registered)return;
  self::$registered=true;
  self::register([
    'operation'=>'maintenance.shell','provider'=>'original_305_maintenance',
    'authority_family'=>'original_305','target_scope'=>'host',
    'command_format'=>'legacy.unspecified.v1','evidence_contract'=>'original_305_claim',
    'readback'=>[__CLASS__,'legacy_ready']]);
  self::register([
    'operation'=>'company.remote.wpcli.full','provider'=>'original_305_wpcli',
    'authority_family'=>'original_305','target_scope'=>'wordpress',
    'command_format'=>'wpcli.argv.json.v1','evidence_contract'=>'original_305_claim',
    'readback'=>[__CLASS__,'legacy_ready']]);
  if(class_exists('MAD4B_Original_New_Site_Native_Admission_V1',false))
   self::register([
    'operation'=>'new-site.wordpress.bootstrap',
    'provider'=>'original_new_site_native_admission',
    'authority_family'=>'original_new_site_bootstrap','target_scope'=>'new_site',
    'command_format'=>'bootstrap.plan.v1',
    'evidence_contract'=>'original_new_site_bootstrap_execution_admission',
    'readback'=>['MAD4B_Original_New_Site_Native_Admission_V1','status']]);
  // Additional future providers may register via this exact PHP API.
  // No user-supplied operation name can authorize execution.
  do_action('mad4b_original_native_operation_providers_register',__CLASS__);
  // Preserve every historically registered Original 305 maintenance name,
  // including future additions, without treating enrollment as execution.
  // Real root-owned providers registered above take precedence. Any legacy
  // operation lacking a specialized verifier stays discovery-only.
  if(class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)) {
   $historic=MAD4B_Central_Dual_Lane_Registry_V1::HOST_MAINTENANCE;
   if(is_array($historic))foreach($historic as $name){
    if(!is_string($name) || !self::canonical($name)
       || isset(self::$providers[$name]))continue;
    self::register([
      'operation'=>$name,
      'provider'=>'original_305_legacy_'.str_replace('.','_',$name),
      'authority_family'=>'original_305','target_scope'=>'host',
      'command_format'=>'maintenance.shell.text.v1',
      'evidence_contract'=>'original_305_claim',
      'readback'=>[__CLASS__,'legacy_needs_verified_provider']]);
   }
  }
 }
 public static function profile($operation):?array {
  self::ensure_registered();
  if(!self::canonical($operation))return null;
  return self::$providers[$operation]??null;
 }
 public static function original_305_operation_registered($operation):bool {
  $p=self::profile($operation);
  return is_array($p) && $p['authority_family']==='original_305';
 }
 public static function legacy_admitted($operation,$scope,$format):bool {
  $p=self::profile($operation);
  if(!$p || $p['authority_family']!=='original_305'
    || $p['target_scope']!==$scope
    || ($p['command_format']!==$format && !(
      $operation==='maintenance.shell'
      && $p['provider']==='original_305_maintenance'
      && $p['command_format']==='legacy.unspecified.v1')))
    return false;
  try {
   $r=call_user_func($p['readback']);
   if(!is_array($r) || ($r['provider_ready']??null)!==true)return false;
   if(in_array($operation,['maintenance.shell','company.remote.wpcli.full'],true))
     return ($r['contract']??null)==='mad4b.original-305-exact-enrolled-grant.v1';
   // Future operations are dynamically pluggable, but must prove their own
   // registered operation identity, provider and specific source contract.
   return ($r['contract']??null)===$p['evidence_contract']
      && ($r['operation']??null)===$operation
      && ($r['provider']??null)===$p['provider']
      && ($r['authority_family']??null)==='original_305';
  }catch(Throwable $e){return false;}
 }
 public static function discover($operation=''):array {
  self::ensure_registered();
  $found=[];
  foreach(self::$providers as $name=>$p){
   if($operation!=='' && $name!==$operation)continue;
   $ready=false;$state='PROVIDER_READBACK_UNAVAILABLE';
   try{
    $row=call_user_func($p['readback']);
    if(is_array($row)){
      $state=(string)($row['state']??'READY_ONLY_FOR_ORIGINAL_AUTHORITY_CHECK');
      $ready=($p['authority_family']==='original_305')
        ? ($row['provider_ready']??null)===true
        : ($row['state']??'')==='EXACT_NEW_SITE_NATIVE_BOOTSTRAP_ADMITTED';
    }
   }catch(Throwable $e){}
   $found[]=[
     'operation'=>$name,'provider'=>$p['provider'],
     'authority_family'=>$p['authority_family'],
     'target_scope'=>$p['target_scope'],
     'command_format'=>$p['command_format'],
     'evidence_contract'=>$p['evidence_contract'],
     'provider_readback_state'=>$state,
     'provider_capability_ready'=>$ready,
     // Original 305 requires an exact active Job/Claim/transport readback
     // independently; provider metadata alone never admits execution.
     'native_authority_admitted'=>$p['authority_family']==='original_new_site_bootstrap'
       && $ready,
     'independent_job_grant_claim_required'=>true,
     'registration_verified'=>true];
  }
  return ['contract'=>self::CONTRACT,'state'=>'DISCOVERY_ONLY',
   'provider_count'=>count($found),'operations'=>$found,
   'unknown_operation_rejected'=>$operation!=='' && count($found)===0,
   'authority_issued'=>false,'execution_performed'=>false];
 }
 public static function cli($args,$assoc):void {
  if(($args[0]??'')!=='status' || count($args)!==1
   || array_diff(array_keys($assoc),['operation']))
   WP_CLI::error('NATIVE_OPERATION_PROVIDER_STATUS_ONLY');
  WP_CLI::line(wp_json_encode(self::discover((string)($assoc['operation']??'')),
      JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b native-operation-provider',[__CLASS__,'cli']);
 }
}
MAD4B_Original_Native_Operation_Providers_V1::boot();
