<?php
/**
 * Original 312 plugin.install capability heartbeat, not job authorization.
 * Uses the already enrolled Broker Ed25519 public key. No new key or grant.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Native_Plugin_Install_Handler_V1 {
 /** Only signed capability *readiness* is cached, never a Job or Grant. */
 public const MAX_AGE_SECONDS=600;
 const CONTRACT='mad4b.native-312-plugin-install-capability.v1';
 const OPTION='mad4b_company_native_plugin_install_handler_activation_v1';
 const PROVIDER='mad4b_company_plugin_install_exact_handler';
 const FIELDS=['contract','operation','transport_operation','provider',
  'host_id','company','environment','site_uuid','origin','archive_sha256',
  'command_sha256','handler_ready','target_wpcli_verified',
  'readback_supported','rollback_supported','heartbeat_epoch','nonce'];
 private static function denied(string $why):array {
  return ['contract'=>self::CONTRACT,'operation'=>'plugin.install',
   'transport_operation'=>'company.remote.wpcli.full','provider'=>self::PROVIDER,
   'handler_ready'=>false,'target_wpcli_verified'=>false,
   'readback_supported'=>false,'rollback_supported'=>false,
   'heartbeat_epoch'=>0,'reason'=>$why];
 }
 private static function verify(array $p):array {
  if(array_keys($p)!==array_merge(self::FIELDS,['signature']))
   return self::denied('SIGNED_HEARTBEAT_SCHEMA_INVALID');
  foreach(self::FIELDS as $k)
   if(!is_string($p[$k])&&!is_int($p[$k]))
    return self::denied('SIGNED_HEARTBEAT_TYPE_INVALID');
  $now=time();
  if(($p['contract']??'')!==self::CONTRACT
   ||$p['operation']!=='plugin.install'
   ||$p['transport_operation']!=='company.remote.wpcli.full'
   ||$p['provider']!==self::PROVIDER
   ||$p['host_id']!=='srv2030908'
   ||$p['company']!=='wayfatours'
   ||$p['environment']!=='production'
   ||$p['site_uuid']!=='a3a7cc36-db7e-4a14-8456-b41857def05c'
   ||$p['origin']!=='https://wayfatours.com'
   ||!preg_match('/^[a-f0-9]{64}$/D',$p['archive_sha256'])
   ||!preg_match('/^[a-f0-9]{64}$/D',$p['command_sha256'])
   ||!preg_match('/^[a-f0-9]{32}$/D',$p['nonce'])
   ||!is_int($p['heartbeat_epoch'])
   ||$p['heartbeat_epoch']>$now+10 ||$p['heartbeat_epoch']<$now-self::MAX_AGE_SECONDS)
    return self::denied('SIGNED_HEARTBEAT_SCOPE_OR_AGE_INVALID');
  foreach(['handler_ready','target_wpcli_verified','readback_supported','rollback_supported'] as $field)
   if($p[$field]!=='1')return self::denied('CAPABILITY_CHECK_NOT_READY');
  if(!class_exists('MAD4B_Central_VPS_Broker_V1',false))
   return self::denied('ORIGINAL_BROKER_MISSING');
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode((string)$p['signature'],true);
  $signed=implode(chr(10),array_map(static fn($k)=>(string)$p[$k],self::FIELDS));
  if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
   ||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
   ||!sodium_crypto_sign_verify_detached($sig,$signed,$pub))
    return self::denied('ORIGINAL_BROKER_HEARTBEAT_SIGNATURE_INVALID');
  return ['contract'=>self::CONTRACT,'operation'=>'plugin.install',
   'transport_operation'=>'company.remote.wpcli.full','provider'=>self::PROVIDER,
   'handler_ready'=>true,'target_wpcli_verified'=>true,
   'readback_supported'=>true,'rollback_supported'=>true,
   'heartbeat_epoch'=>$p['heartbeat_epoch'],'state'=>'CAPABILITY_AVAILABLE_JOB_STILL_REQUIRED'];
 }
 public static function capability_status():array {
  if(wp_get_environment_type()!=='staging'
   ||untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
   return self::denied('CENTRAL_STAGING_ONLY');
  $p=get_option(self::OPTION,false);
  return is_array($p)?self::verify($p):self::denied('SIGNED_WORKER_HEARTBEAT_MISSING');
 }
 /**
  * Existing original Broker Ed25519 signature is the REST authenticator.
  * This receives a capability heartbeat ONLY, never an owner-approved Job.
  */
 private static function parse_rest_payload(WP_REST_Request $request):?array {
  if(wp_get_environment_type()!=='staging'
    ||untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
   return null;
  $body=$request->get_json_params();
  if(!is_array($body)||array_keys($body)!==['payload']
    ||!is_string($body['payload'])||strlen($body['payload'])>6400)
   return null;
  $raw=base64_decode($body['payload'],true);
  if(!is_string($raw)||strlen($raw)<50||strlen($raw)>4500)return null;
  $p=json_decode($raw,true);
  return is_array($p)?$p:null;
 }
 public static function rest_permitted(WP_REST_Request $request):bool {
  $p=self::parse_rest_payload($request);
  return is_array($p)&&(self::verify($p)['handler_ready']??false)===true;
 }
 private static function ingest_verified(array $p):array {
  $verified=self::verify($p);
  if(($verified['handler_ready']??false)!==true)
   return ['accepted'=>false,'reason'=>'SIGNED_CAPABILITY_REJECTED'];
  $previous=get_option(self::OPTION,false);
  if(is_array($previous)) {
   $prior=(int)($previous['heartbeat_epoch']??0);
   if($prior>(int)$p['heartbeat_epoch'])
    return ['accepted'=>false,'reason'=>'SIGNED_CAPABILITY_REPLAY_OLDER'];
   if(($previous['nonce']??null)===$p['nonce']
     &&($previous['signature']??null)===$p['signature']) {
    $existing=self::capability_status();
    if(($existing['handler_ready']??false)===true)
     return ['accepted'=>true,'heartbeat_epoch'=>$p['heartbeat_epoch'],
       'operation'=>'plugin.install','new_grants'=>0,'job_verified'=>false,
       'production_mutated'=>false,'idempotent'=>true];
   }
  }
  if(!update_option(self::OPTION,$p,false))
   return ['accepted'=>false,'reason'=>'CAPABILITY_STORAGE_FAILED'];
  $read=self::capability_status();
  if(($read['handler_ready']??false)!==true
    ||($read['heartbeat_epoch']??0)!==$p['heartbeat_epoch'])
   return ['accepted'=>false,'reason'=>'CAPABILITY_READBACK_FAILED'];
  return ['accepted'=>true,'heartbeat_epoch'=>$p['heartbeat_epoch'],
    'operation'=>'plugin.install','new_grants'=>0,'job_verified'=>false,
    'production_mutated'=>false];
 }
 public static function rest_ingest(WP_REST_Request $request) {
  $p=self::parse_rest_payload($request);
  if(!is_array($p))return new WP_Error('capability_payload_invalid','Invalid signed capability', ['status'=>403]);
  $result=self::ingest_verified($p);
  if(($result['accepted']??false)!==true)
   return new WP_Error('capability_denied','Signed capability not accepted',['status'=>403]);
  return rest_ensure_response($result);
 }
 public static function routes():void {
  register_rest_route('mad4b/v1','/native-plugin-install-capability',[
   'methods'=>'POST',
   'permission_callback'=>[__CLASS__,'rest_permitted'],
   'callback'=>[__CLASS__,'rest_ingest']
  ]);
 }
 public static function cli($args,$assoc):void {
  if(!defined('WP_CLI')||!WP_CLI||!class_exists('WP_CLI')
   ||get_current_user_id()<1||!current_user_can('manage_options'))
    WP_CLI::error('ENROLLED_CENTRAL_WPCLI_PRINCIPAL_REQUIRED');
  $action=(string)($args[0]??'');
  if($action==='status') {
   WP_CLI::line(wp_json_encode(self::capability_status(),JSON_UNESCAPED_SLASHES));return;
  }
  if($action!=='ingest'||array_keys($assoc)!==['payload'])
   WP_CLI::error('EXACT_CAPABILITY_PAYLOAD_REQUIRED');
  $raw=base64_decode((string)$assoc['payload'],true);
  if(!is_string($raw)||strlen($raw)>4500)WP_CLI::error('CAPABILITY_PAYLOAD_INVALID');
  $p=json_decode($raw,true);
  if(!is_array($p))WP_CLI::error('CAPABILITY_JSON_INVALID');
  $result=self::ingest_verified($p);
  if(($result['accepted']??false)!==true)
   WP_CLI::error('CAPABILITY_DENIED_'.($result['reason']??'INVALID'));
  WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  add_action('rest_api_init',[__CLASS__,'routes'],98);
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b native-plugin-install-handler',[__CLASS__,'cli']);
 }
}
MAD4B_Company_Native_Plugin_Install_Handler_V1::boot();
