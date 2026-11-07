<?php
/**
 * Company Plugin Sync: source-aware central planner.
 * No target writes, native claims, or synthetic execution receipts.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Plugin_Live_Reconcile {
 const CONTRACT='mad4b.company-plugin-live-reconcile.v1';
 const HOOK='mad4b_company_plugin_live_source_reconcile_v1';
 const STORE='mad4b_company_plugin_sync_plan_v1';

 private static function deny(string $why):WP_Error {
  return new WP_Error('mad4b_plugin_sync_'.strtolower($why),$why,['status'=>409]);
 }
 private static function central():bool {
  return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
   && MAD4B_Company_Portfolio_Manager_V1::staging()
   && class_exists('MAD4B_Company_Environment_Registry_V2',false);
 }
 private static function source():array|WP_Error {
  if(!self::central())return self::deny('CENTRAL_SOURCE_NOT_VERIFIED');
  $active=get_option('active_plugins',[]);
  if(!is_array($active))return self::deny('SOURCE_ACTIVE_LIST_INVALID');
  $out=[];$roots=realpath(WP_PLUGIN_DIR);
  if(!is_string($roots))return self::deny('SOURCE_PLUGIN_ROOT_MISSING');
  foreach($active as $entry){
   if(!is_string($entry)||!preg_match('~^(mad4b-[a-z0-9_-]{3,64})/([a-z0-9_-]+[.]php)$~D',$entry,$m))continue;
   $slug=$m[1];
   $dir=WP_PLUGIN_DIR.'/'.$slug;
   $manifest=$dir.'/mad4b-portability.json';
   $main=$dir.'/'.$m[2];
   if(!is_file($manifest))continue;
   if(is_link($manifest)||is_link($dir)||is_link($main)
    ||realpath($dir)!==$roots.'/'.$slug || !is_file($main))
    return self::deny('SOURCE_PORTABILITY_PATH_UNSAFE');
   $raw=file_get_contents($manifest,false,null,0,32769);
   if(!is_string($raw)||strlen($raw)>32768)return self::deny('SOURCE_PROFILE_TOO_LARGE');
   $p=json_decode($raw,true);
   if(!is_array($p)||($p['contract']??null)!=='mad4b.plugin-portability-profile.v1'
    ||($p['plugin']??null)!==$slug
    ||($p['scope']??null)!=='tenant-portable'
    ||($p['activation_policy']??null)!=='required_if_source_active'
    ||($p['environment_policy']??null)!=='any_registered_wordpress_environment'
    ||($p['tenant_installation_requires_original_authorized_site_write']??null)!==true
    ||($p['sensitive_central_authority_not_included']??null)!==true
    ||($p['financial_or_customer_data_automatic_write']??null)!==false)
    continue;
   $hashes=[];$count=0;
   try {
    $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
    foreach($walk as $file){
     if($file->isLink())return self::deny('SOURCE_PLUGIN_SYMLINK');
     if(!$file->isFile())continue;
     $rel=substr($file->getPathname(),strlen($dir)+1);
     if(preg_match('~(^|/)(tests|\.git)(/|$)~',$rel)
       ||str_contains(basename($rel),'.before-'))continue;
     if(++$count>1500)return self::deny('SOURCE_PLUGIN_FILE_LIMIT');
     $digest=hash_file('sha256',$file->getPathname());
     if(!is_string($digest))return self::deny('SOURCE_FILE_UNREADABLE');
     $hashes[$rel]=$digest;
    }
   }catch(Throwable $e){return self::deny('SOURCE_FILE_SCAN_FAILED');}
   ksort($hashes,SORT_STRING);
   if(!$count)return self::deny('SOURCE_PLUGIN_EMPTY');
   $out[$slug]=[
    'slug'=>$slug,'entrypoint'=>$entry,'source_active'=>true,
    'profile_sha256'=>hash('sha256',$raw),
    'runtime_files_sha256'=>hash('sha256',wp_json_encode($hashes,JSON_UNESCAPED_SLASHES)),
    'runtime_file_count'=>$count,
    'required_if_source_active'=>true,
    'target_installed'=>null,'target_active'=>null,
    'target_readback_required'=>true];
  }
  ksort($out,SORT_STRING);
  $presentation=class_exists('MAD4B_Company_Presentation_Option_Ownership_V1',false)
   ?MAD4B_Company_Presentation_Option_Ownership_V1::digest():null;
  return ['source_origin'=>untrailingslashit(home_url('/')),
   'source_active_plugin_count'=>count($active),
   'portable_plugins'=>array_values($out),
   'presentation_ownership_contract'=>'mad4b.company-presentation-option-ownership.v1',
   'presentation_ownership_sha256'=>$presentation];
 }
 public static function ensure_current(string $code,string $uuid) {
  if(!self::central())return self::deny('CENTRAL_SOURCE_NOT_VERIFIED');
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code)||!wp_is_uuid($uuid))
   return self::deny('INVALID_EXACT_COMPANY_SCOPE');
  $registered=MAD4B_Company_Environment_Registry_V2::discover();
  $company=$registered[$code]??null;
  if(!is_array($company))return self::deny('COMPANY_NOT_ENROLLED');
  $matched=[];
  foreach(($company['environments']??[]) as $env=>$target){
   if(is_array($target)&&($target['site_uuid']??null)===$uuid
     && ($target['canonical_origin']??'')!=='')$matched[$env]=$target;
  }
  if(count($matched)!==1)return self::deny('SITE_ENVIRONMENT_NOT_UNIQUE');
  $env=array_key_first($matched);$target=$matched[$env];
  $snapshot=self::source();
  if(is_wp_error($snapshot))return $snapshot;
  $binding=['company'=>$code,'environment'=>$env,
   'site_uuid'=>$uuid,'canonical_origin'=>$target['canonical_origin']];
  $digest=hash('sha256',wp_json_encode(['scope'=>$binding,'source'=>$snapshot],
   JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $all=get_option(self::STORE,[]);
  if(!is_array($all))return self::deny('PLAN_STORE_CORRUPTED');
  $key=$code.':'.$env;
  $old=$all[$key]??($all[$code]??null);
  if(is_array($old)&&($old['plan_hash']??null)===$digest
    && ($old['site_uuid']??null)===$uuid
    && ($old['environment']??null)===$env
    && ($old['canonical_origin']??null)===$target['canonical_origin'])
   return $old;
  $plan=array_merge($binding,$snapshot,[
    'contract'=>'mad4b.company-plugin-sync-plan.v1','plan_hash'=>$digest,
    'generated_at'=>gmdate('c'),'source_plugin_count'=>$snapshot['source_active_plugin_count'],
    'copy_missing'=>0,'activation_candidates'=>0,
    'source_portable_candidates'=>count($snapshot['portable_plugins']),
    'target_inventory_verified'=>false,'target_readback_required'=>true,
    'presentation_owner_target_readback_required'=>true,
    'presentation_aliases_must_resolve_to_runtime_owners'=>true,
    'source_demo_identity_must_not_overwrite_tenant'=>true,
    'isolated_test_pass'=>false,'production_mutation_authorized'=>false,
    'original_305_job_submitted'=>false,'original_313_certified'=>false,
    'next_stage'=>'NATIVE_TARGET_READBACK_AND_EXACT_OPERATION_ADMISSION'
  ]);
  $all[$key]=$plan;
  // The historical company-only REST endpoint projects the registered
  // legacy site UUID, while environment-scoped plans remain distinct.
  $legacy=get_option('mad4b_tourism_company_site_registry_v1',[]);
  if(is_array($legacy) && ($legacy[$code]['company_site_uuid']??null)===$uuid)
   $all[$code]=$plan;
  update_option(self::STORE,$all,false);
  $check=get_option(self::STORE,[]);
  if(!is_array($check) || ($check[$key]['plan_hash']??null)!==$digest)
   return self::deny('PLAN_PERSISTENCE_NOT_VERIFIED');
  return $plan;
 }
 public static function refresh_all():array {
  if(!self::central())return ['state'=>'CENTRAL_ONLY'];
  $registry=MAD4B_Company_Environment_Registry_V2::discover();
  $results=[];
  foreach($registry as $code=>$company){
   if($code==='tours-central')continue;
   foreach(($company['environments']??[]) as $env=>$target){
    $uuid=(string)($target['site_uuid']??'');
    if(!wp_is_uuid($uuid)){
     $results[$code.':'.$env]='IDENTITY_PENDING_SITE_UUID_REQUIRED';
     continue;
    }
    $result=self::ensure_current((string)$code,$uuid);
    $results[$code.':'.$env]=is_wp_error($result)
     ?$result->get_error_code():'SOURCE_PLAN_CURRENT';
   }
  }
  return ['contract'=>self::CONTRACT,'state'=>'RECONCILED_SOURCE_PLANS',
   'targets'=>$results,'tenant_mutated'=>false,'original_305_issued'=>false];
 }
 public static function native_status(string $code,string $uuid) {
  $plan=self::ensure_current($code,$uuid);
  if(is_wp_error($plan))return $plan;
  $env=$plan['environment'];
  $pre=class_exists('MAD4B_Company_Native_Write_Executor_V1',false)
   ?MAD4B_Company_Native_Write_Executor_V1::preflight_status($code,$env,'plugin.install'):[];
  $proof=class_exists('MAD4B_Company_Native_Write_Executor_V1',false)
   ?MAD4B_Company_Native_Write_Executor_V1::certified_status($code,$env,'plugin.install'):[];
  $provider_ready=($pre['operation_provider_ready']??false)===true;
  $transport_ready=($pre['transport_provider_ready']??false)===true;
  // An intent-level 313 cannot complete this particular plan unless
  // its executed command is cryptographically bound to this plan hash.
  $intent_signed=($proof['state']??null)==='ORIGINAL_EXACT_WRITE_AND_ROLLBACK_CERTIFIED';
  $store=get_option('mad4b_company_plugin_target_inventory_readback_v1',[]);
  $last=is_array($store)?($store[$code.':'.$env]??null):null;
  $observed=is_array($last)
   && ($last['contract']??null)==='mad4b.company-plugin-target-readback.v1'
   && ($last['site_uuid']??null)===$uuid
   && ($last['canonical_origin']??null)===$plan['canonical_origin']
   && hash_equals((string)$plan['plan_hash'],(string)($last['plan_hash']??''))
   && ($last['readback_kind']??null)==='LIVE_COOLIFY_WPCLI_OBSERVATION'
   && ($last['production_mutated']??true)===false
   && ($last['native_313_certified']??true)===false;
  $observed_epoch=$observed?strtotime((string)($last['observed_at']??'')):false;
  $observation_age=$observed_epoch!==false?max(0,time()-$observed_epoch):null;
  $jobs=get_option(MAD4B_Company_Plugin_Sync_Operation::JOB_MAP,[]);
  $journal=is_array($jobs)?($jobs[$code][$plan['plan_hash']]??null):null;
  return ['contract'=>self::CONTRACT,'company'=>$code,
   'environment'=>$env,'site_uuid'=>$uuid,'canonical_origin'=>$plan['canonical_origin'],
   'plan_hash'=>$plan['plan_hash'],'source_portable_candidates'=>$plan['source_portable_candidates'],
   'target_inventory_verified'=>$observed,'target_inventory_readback_kind'=>$observed
     ?'LIVE_COOLIFY_WPCLI_OBSERVATION':null,
   'target_inventory_observed_at'=>$observed?($last['observed_at']??null):null,
   'target_inventory_observation_age_seconds'=>$observation_age,
   'target_inventory_evidence'=>'LIVE_OBSERVATION_NOT_EXECUTION_RECEIPT',
   'target_install_missing_count'=>$observed?(int)$last['install_missing_count']:null,
   'target_activation_needed_count'=>$observed?(int)$last['activation_needed_count']:null,
   'target_readback_is_signed_313'=>false,
   'transport_provider_ready'=>$transport_ready,
   'operation_provider_ready'=>$provider_ready,
   'original_305_submission_ready'=>$provider_ready&&$transport_ready
     && ($pre['scope_preflight_passed']??false)===true
     && ($pre['target_wordpress_actor_capability_verified']??false)===true,
   'native_preflight_state'=>$pre['state']??'UNAVAILABLE',
   'native_intent_signed_proof_present'=>$intent_signed,
   'plan_specific_313_certified'=>false,
   'plan_313_binding_required'=>true,
   'original_313_state'=>$proof['state']??'UNAVAILABLE',
   'journal_operation_id'=>$journal,'tenant_write_performed_by_planner'=>false,
   'next_stage'=>!$provider_ready
    ?(string)($pre['next_stage']??'INSTALL_TRUSTED_EXACT_WRITE_HANDLER_WITH_312_AND_313')
    :'ORIGINAL_305_312_JOB_AND_INDEPENDENT_313_READBACK'];
 }
 /**
  * Read-only identity projection for Central source and company target.
  * Connector display names never select a company or WordPress site.
  * This method intentionally does not call ensure_current() or update_option().
  */
 public static function identity_status(string $code,string $env) {
  if(!self::central() || !class_exists('MAD4B_SCP_Site_Profile',false))
   return self::deny('SOURCE_SITE_PROFILE_UNAVAILABLE');
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code) ||
     !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$env))
   return self::deny('INVALID_EXACT_TARGET');
  $profile=MAD4B_SCP_Site_Profile::status();
  $source_origin=untrailingslashit(home_url('/'));
  if(!is_array($profile) ||
     ($profile['contract']??'')!=='mad4b.site-profile.v2' ||
     ($profile['configured']??false)!==true ||
     ($profile['origin_match']??false)!==true ||
     ($profile['environment_match']??false)!==true ||
     ($profile['deployment_binding_match']??false)!==true ||
     !wp_is_uuid((string)($profile['site_uuid']??'')) ||
     ($profile['canonical_origin']??'')!==$source_origin ||
     ($profile['environment']??'')!==wp_get_environment_type())
   return self::deny('SOURCE_IDENTITY_NOT_VERIFIED');
  $target=MAD4B_Company_Environment_Registry_V2::target($code,$env);
  $target_origin=is_array($target)?(string)($target['canonical_origin']??''):'';
  if(!is_array($target) ||
     ($target['company_id']??'')!==$code ||
     ($target['environment']??'')!==$env ||
     !wp_is_uuid((string)($target['site_uuid']??'')) ||
     wp_parse_url($target_origin,PHP_URL_SCHEME)!=='https' ||
     !is_string(wp_parse_url($target_origin,PHP_URL_HOST)) ||
     $target_origin!==untrailingslashit($target_origin))
   return self::deny('TARGET_IDENTITY_NOT_VERIFIED');
  $same=((string)$profile['site_uuid']===(string)$target['site_uuid'] &&
          $source_origin===$target_origin &&
          (string)$profile['environment']===$env);
  return [
   'contract'=>'mad4b.company-source-target-identity.v1',
   'source_site'=>[
    'role'=>'central_source','site_uuid'=>(string)$profile['site_uuid'],
    'canonical_origin'=>$source_origin,
    'environment'=>(string)$profile['environment']],
   'target_site'=>[
    'role'=>'company_target','company_id'=>$code,'environment'=>$env,
    'site_uuid'=>(string)$target['site_uuid'],
    'canonical_origin'=>$target_origin,
    'connection_state'=>(string)($target['connection_state']??'UNKNOWN'),
    'identity_evidence_source'=>is_array($target['identity_evidence']??null)
      ?(string)($target['identity_evidence']['source']??'UNKNOWN'):'UNKNOWN',
    'write_authorized'=>($target['write_authorized']??false)===true],
   'same_site'=>$same,
   'identity_basis'=>'verified_source_site_profile_and_declared_target_registry',
   'target_registry_declaration_is_not_a_write_grant'=>true,
   'connector_display_name_is_identity'=>false,
   'connector_display_name_used_for_routing'=>false,
   'site_rebound'=>false,'read_only'=>true,
   'operation_executed'=>false,'grant_issued'=>false,
  ];
 }
 public static function identity_rest(WP_REST_Request $request) {
  $result=self::identity_status((string)$request['company'],(string)$request['environment']);
  if(is_wp_error($result))return $result;
  $response=new WP_REST_Response($result);
  $response->header('Cache-Control','private, no-store');
  return $response;
 }
 public static function status_rest(WP_REST_Request $request) {
  $code=(string)$request['company'];
  $env=(string)$request['environment'];
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code)
    ||!preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$env))
   return self::deny('INVALID_REST_TARGET');
  $t=MAD4B_Company_Environment_Registry_V2::target($code,$env);
  if(!is_array($t)||!wp_is_uuid((string)($t['site_uuid']??'')))
   return self::deny('TARGET_SITE_IDENTITY_PENDING');
  $result=self::native_status($code,(string)$t['site_uuid']);
  if(is_wp_error($result))return $result;
  $response=new WP_REST_Response($result);
  $response->header('Cache-Control','private, no-store');
  return $response;
 }
 public static function routes():void {
  if(!self::central())return;
  register_rest_route('mad4b/v1',
   '/company-provisioning/companies/(?P<company>[a-z][a-z0-9-]{2,47})/environments/(?P<environment>[a-z][a-z0-9_-]{1,63})/source-target-identity',[
    'methods'=>'GET',
    'permission_callback'=>['MAD4B_Company_Plugin_Sync_Operation','guard'],
    'callback'=>[__CLASS__,'identity_rest']]);
  register_rest_route('mad4b/v1',
   '/company-provisioning/companies/(?P<company>[a-z][a-z0-9-]{2,47})/environments/(?P<environment>[a-z][a-z0-9_-]{1,63})/plugin-sync-status',[
    'methods'=>'GET',
    'permission_callback'=>['MAD4B_Company_Plugin_Sync_Operation','guard'],
    'callback'=>[__CLASS__,'status_rest']]);
 }
 public static function schedules(array $s):array {
  $s['mad4b_source_reconcile_15m']=['interval'=>900,'display'=>'MAD4B source plugin reconcile'];
  return $s;
 }
 public static function schedule():void {
  if(!self::central())return;
  if(!wp_next_scheduled(self::HOOK))
   wp_schedule_event(time()+180,'mad4b_source_reconcile_15m',self::HOOK);
 }
 public static function cli($args,$assoc):void {
  if(($args[0]??'')==='identity'&&count($args)===1
   &&isset($assoc['company'],$assoc['environment'])&&count($assoc)===2){
   $result=self::identity_status((string)$assoc['company'],(string)$assoc['environment']);
   if(is_wp_error($result))WP_CLI::error($result->get_error_code());
   WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));return;
  }
  if(($args[0]??'')==='all'&&count($args)===1&&!$assoc){
   WP_CLI::line(wp_json_encode(self::refresh_all(),JSON_UNESCAPED_SLASHES));return;
  }
  if(($args[0]??'')==='status'&&count($args)===1
   &&isset($assoc['company'],$assoc['site_uuid'])
   &&count($assoc)===2){
   $result=self::ensure_current((string)$assoc['company'],(string)$assoc['site_uuid']);
   if(is_wp_error($result))WP_CLI::error($result->get_error_code());
   $native=self::native_status($result['company'],$result['site_uuid']);
   if(is_wp_error($native))WP_CLI::error($native->get_error_code());
   WP_CLI::line(wp_json_encode($native,JSON_UNESCAPED_SLASHES));return;
  }
  WP_CLI::error('USE: all OR identity --company=CODE --environment=ENV OR status --company=CODE --site_uuid=UUID');
 }
 public static function boot():void {
  add_filter('cron_schedules',[__CLASS__,'schedules']);
  add_action('init',[__CLASS__,'schedule'],95);
  add_action('rest_api_init',[__CLASS__,'routes'],91);
  add_action(self::HOOK,[__CLASS__,'refresh_all']);
  add_action('activated_plugin',[__CLASS__,'refresh_all'],95);
  add_action('deactivated_plugin',[__CLASS__,'refresh_all'],95);
  add_action('upgrader_process_complete',[__CLASS__,'refresh_all'],95);
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b company-plugin-live',[__CLASS__,'cli']);
 }
}
MAD4B_Company_Plugin_Live_Reconcile::boot();
