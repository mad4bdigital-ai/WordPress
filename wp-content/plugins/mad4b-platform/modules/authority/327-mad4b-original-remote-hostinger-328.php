<?php
/**
 * Independent Original 327/328 remote Hostinger proof + activation.
 * May update only an already signed Original 305 remote host access record.
 * Never creates Grants, issues Jobs, or grants a generic WordPress user role.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Original_Remote_Hostinger_328_V1',false)) {
final class MAD4B_Original_Remote_Hostinger_328_V1 {
 const CONTRACT='mad4b.original-328-remote-hostinger-provider.v1';
 const RECEIPT_PREFIX='mad4b_original_remote_hostinger_328_receipt_v1_';
 const NONCE='mad4b_original_remote_hostinger_328_nonce_v1_';
 const FIELDS=['contract','company','environment','site_uuid','canonical_origin',
  'native_profile_uuid','transport_id','grant_id','target_host_id',
  'target_ssh_principal','target_wordpress_root_sha256','target_database',
  'target_wp_actor_id','host_key_fingerprint','host_policy_sha256',
  'registry_sha256','issued','expires','nonce'];
 private static function deny($reason):array{return ['state'=>'DENIED','reason'=>$reason,
  'verified'=>false,'full_wpcli_active'=>false,'grants_issued'=>0,'313_executor_certified'=>false];}
 private static function hash($v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
 private static function id($v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{32}$/D',$v)===1;}
 // The central issuer is authenticated independently of the TARGET site.
 private static function exactCentral():bool{return defined('WP_CLI')&&WP_CLI
  &&class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
  &&MAD4B_Central_Dual_Lane_Registry_V1::central();}
 private static function registeredEnvironment($company,$environment):bool {
  return is_string($company)&&preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
   &&is_string($environment)&&preg_match('/^[a-z][a-z0-9_-]{1,39}$/D',$environment)
   &&class_exists('MAD4B_Company_Environment_Registry_V2',false)
   &&is_array(MAD4B_Company_Environment_Registry_V2::target($company,$environment));
 }
 private static function site($co,$env):?array {
  $site=MAD4B_Company_Environment_Registry_V2::target($co,$env);
  return is_array($site)?$site:null;
 }
 private static function records($co,$env):?array {
  $n='MAD4B_Company_Native_WPCLI_Enrollment_V1';
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  $slot=get_option($n::SLOT.$co.'_'.$env,false);
  if(!is_array($slot)||!self::id($slot['transport_id']??null)||!self::id($slot['grant_id']??null))return null;
  $t=get_option($c::TRANSPORT_PREFIX.$slot['transport_id'],false);
  $g=get_option($c::PREFIX.$slot['grant_id'],false);
  if(!is_array($t)||!is_array($g))return null;
  return ['slot'=>$slot,'transport'=>$t,'grant'=>$g];
 }
 public static function verify(array $p):array {
  if(array_keys($p)!==array_merge(self::FIELDS,['signature']))
   return self::deny('ORIGINAL_REMOTE_328_SIGNED_PAYLOAD_SHAPE_INVALID');
  foreach(self::FIELDS as $f)if(!is_string($p[$f])&&!is_int($p[$f]))
   return self::deny('ORIGINAL_REMOTE_328_PAYLOAD_TYPE_INVALID');
  $co=$p['company'];$env=$p['environment'];$now=time();
  if(!self::exactCentral()
   ||$p['contract']!==self::CONTRACT
   ||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$co)||$co==='tours-central'
   ||!self::registeredEnvironment($co,$env)
   ||!wp_is_uuid($p['site_uuid'])||!wp_is_uuid($p['native_profile_uuid'])
   ||!self::id($p['transport_id'])||!self::id($p['grant_id'])
   ||!preg_match('/^hosting-[123]$/D',$p['target_host_id'])
   ||!preg_match('/^u[0-9]{9}$/D',$p['target_ssh_principal'])
   ||!preg_match('/^[A-Za-z0-9_]{4,90}$/D',$p['target_database'])
   ||!is_int($p['target_wp_actor_id'])||$p['target_wp_actor_id']<1
   ||!preg_match('/^SHA256:[A-Za-z0-9+\/]{43}=?$/D',$p['host_key_fingerprint'])
   ||!self::hash($p['target_wordpress_root_sha256'])
   ||!self::hash($p['host_policy_sha256'])||!self::hash($p['registry_sha256'])
   ||!self::id($p['nonce'])
   ||!is_int($p['issued'])||!is_int($p['expires'])
   ||$p['issued']<$now-60||$p['issued']>$now+10
   ||$p['expires']<$now||$p['expires']>$p['issued']+60)
   return self::deny('ORIGINAL_REMOTE_328_EXACT_SCOPE_OR_TIME_INVALID');
  if(!class_exists('MAD4B_Central_VPS_Broker_V1',false)
   ||!class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
   ||!class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false)
   ||!class_exists('MAD4B_Company_Environment_Registry_V2',false)
   ||!class_exists('MAD4B_Company_Execution_Resolver_V1',false)
   ||!class_exists('MAD4B_Original_Remote_Hostinger_305_V1',false))
   return self::deny('ORIGINAL_REMOTE_328_CORE_NOT_LOADED');
  $site=self::site($co,$env);
  $profile=MAD4B_Company_Execution_Resolver_V1::discover()[$co.':'.$env]??null;
  $enrolled=MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($co,$env);
  $records=self::records($co,$env);
  if(!is_array($site)||!is_array($profile)||!is_array($records)
   ||($site['company_id']??null)!==$co
   ||($site['environment']??null)!==$env
   ||($site['site_uuid']??null)!==$p['site_uuid']
   ||($site['canonical_origin']??null)!==$p['canonical_origin']
   ||($profile['profile_uuid']??null)!==$p['native_profile_uuid']
   ||($enrolled['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
   ||($enrolled['remote_hostinger']??false)!==true)
   return self::deny('ORIGINAL_REMOTE_328_305_SITE_PROFILE_INVALID');
  $slot=$records['slot'];$t=$records['transport'];$g=$records['grant'];
  if(($slot['grant_id']??null)!==$p['grant_id']
   ||($slot['transport_id']??null)!==$p['transport_id']
   ||($t['principal_type']??null)!=='signed_hostinger_ssh_wpcli'
   ||($g['principal_type']??null)!=='signed_hostinger_ssh_wpcli'
   ||($t['host_id']??null)!==$p['target_host_id']
   ||($g['target_host_id']??null)!==$p['target_host_id']
   ||($t['host_principal']??null)!==$p['target_ssh_principal']
   ||($g['target_ssh_principal']??null)!==$p['target_ssh_principal']
   ||($t['remote_native_profile_uuid']??null)!==$p['native_profile_uuid']
   ||($t['remote_wordpress_root_sha256']??null)!==$p['target_wordpress_root_sha256']
   ||($t['remote_target_database']??null)!==$p['target_database']
   ||($t['remote_wp_actor_id']??null)!==$p['target_wp_actor_id']
   ||($t['host_key_fingerprint']??null)!==$p['host_key_fingerprint']
   ||($t['remote_host_policy_sha256']??null)!==$p['host_policy_sha256']
   ||($t['remote_registry_sha256']??null)!==$p['registry_sha256']
   ||!in_array(($t['state']??null),['PENDING_INDEPENDENT_SSH_VERIFICATION','REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED'],true)
   ||($g['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED')
   return self::deny('ORIGINAL_REMOTE_328_REMOTE_305_PAIR_CHANGED');
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode((string)$p['signature'],true);
  if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
   ||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
   return self::deny('ORIGINAL_REMOTE_328_BROKER_SIGNER_MISSING');
  $message=implode("\n",array_map(static fn($key)=>(string)$p[$key],self::FIELDS));
  if(!sodium_crypto_sign_verify_detached($sig,$message,$pub))
   return self::deny('ORIGINAL_REMOTE_328_INVALID_BROKER_SIGNATURE');
  return ['state'=>'ORIGINAL_REMOTE_328_SIGNED_HOST_PROOF_VERIFIED','verified'=>true,
   'company'=>$co,'environment'=>$env,'site_uuid'=>$p['site_uuid'],
   'grant_id'=>$p['grant_id'],'transport_id'=>$p['transport_id'],
   'signed_sha256'=>hash('sha256',$message),
   'native_executor_certified'=>false];
 }
 public static function active(array $t,array $g):bool {
  $co=$t['company']??null;$env=$t['environment']??null;
  if(!is_string($co)||!is_string($env)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$co)
   ||!preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$env)
   ||($t['state']??null)!=='REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED'
   ||($t['full_wpcli_active']??false)!==true
   ||($t['host_authority_verified']??false)!==true
   ||($t['native_access_validation']??null)!=='signed_remote_hostinger_exact_wpcli'
   ||!self::hash($t['native_access_receipt_sha256']??null)
   ||!self::hash($t['native_access_wpcli_readback_sha256']??null)
   ||($t['capabilities']??null)!==['wpcli.full']
   ||($g['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
   ||($g['grant_mode']??null)!=='persistent'
   ||($g['operation']??null)!=='company.remote.wpcli.full'
   ||($g['target_host_id']??null)!==($t['host_id']??null)
   ||($g['target_ssh_principal']??null)!==($t['host_principal']??null)
   ||($t['principal_type']??null)!=='signed_hostinger_ssh_wpcli'
   ||($g['principal_type']??null)!=='signed_hostinger_ssh_wpcli')return false;
  $status=self::status($co,$env);
  return ($status['verified']??false)===true;
 }
 public static function status(string $co,string $env):array {
  if(!class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false)
   ||!class_exists('MAD4B_Central_SSH_Command_Intake_V1',false))
    return self::deny('ORIGINAL_REMOTE_328_CORE_UNAVAILABLE');
  $receipt=get_option(self::RECEIPT_PREFIX.$co.'_'.$env,false);
  $records=self::records($co,$env);
  $enrolled=MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($co,$env);
  if(!is_array($receipt)||!is_array($records)
   ||($receipt['contract']??null)!==self::CONTRACT
   ||($receipt['state']??null)!=='REMOTE_HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING'
   ||($receipt['company']??null)!==$co||($receipt['environment']??null)!==$env
   ||!is_array($receipt['signed_payload']??null)
   ||($enrolled['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
   ||($enrolled['remote_hostinger']??false)!==true)
   return self::deny('ORIGINAL_REMOTE_328_CERTIFICATE_OR_305_NOT_ENROLLED');
  $t=$records['transport'];$g=$records['grant'];$slot=$records['slot'];
  $v=$receipt['signed_payload'];
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode((string)($v['signature']??''),true);
  if(array_keys($v)!==array_merge(self::FIELDS,['signature'])
   ||!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
   ||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
    return self::deny('ORIGINAL_REMOTE_328_PERSISTED_SIGNATURE_INVALID');
  $message=implode("\n",array_map(static fn($key)=>(string)$v[$key],self::FIELDS));
  if(!sodium_crypto_sign_verify_detached($sig,$message,$pub))
   return self::deny('ORIGINAL_REMOTE_328_PERSISTED_SIGNATURE_REVOKED');
  if(($receipt['signed_sha256']??null)!==hash('sha256',$message)
   ||($receipt['site_uuid']??null)!==($slot['site_uuid']??null)
   ||($receipt['grant_id']??null)!==($slot['grant_id']??null)
   ||($receipt['transport_id']??null)!==($slot['transport_id']??null)
   ||($receipt['host_id']??null)!==($t['host_id']??null)
   ||($receipt['host_principal']??null)!==($t['host_principal']??null)
   ||($receipt['target_database']??null)!==($t['remote_target_database']??null)
   ||($receipt['target_wp_actor_id']??null)!==($t['remote_wp_actor_id']??null)
   ||($receipt['host_key_fingerprint']??null)!==($t['host_key_fingerprint']??null)
   ||($receipt['host_policy_sha256']??null)!==($t['remote_host_policy_sha256']??null)
   ||($receipt['registry_sha256']??null)!==($t['remote_registry_sha256']??null)
   ||($g['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
   ||($t['state']??null)!=='REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED'
   ||($t['native_access_validation']??null)!=='signed_remote_hostinger_exact_wpcli'
   ||($t['native_access_receipt_sha256']??null)!==$receipt['signed_sha256'])
   return self::deny('ORIGINAL_REMOTE_328_HOST_PROOF_BINDING_CHANGED');
  return ['contract'=>self::CONTRACT,'state'=>'REMOTE_HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING',
   'verified'=>true,'company'=>$co,'environment'=>$env,
   'native_executor_certified'=>false,
   'binding_mode'=>'signed_original_305_remote_hostinger'];
 }
 public static function activate(array $p):array {
  $verified=self::verify($p);
  if(($verified['verified']??false)!==true)return $verified;
  $co=$p['company'];$env=$p['environment'];
  $records=self::records($co,$env);
  if(!is_array($records))return self::deny('ORIGINAL_REMOTE_328_PAIR_MISSING');
  $t=$records['transport'];$g=$records['grant'];$slot=$records['slot'];
  $active=$t['state']==='REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED';
  if($active){
   $status=self::status($co,$env);
   if(($status['verified']??false)!==true)
    return self::deny('ORIGINAL_REMOTE_328_PREVIOUS_PROOF_INCOMPATIBLE');
  }
  $nonce=self::NONCE.$p['nonce'];
  if(!add_option($nonce,['site_uuid'=>$p['site_uuid'],'epoch'=>time()],'',false))
   return self::deny('ORIGINAL_REMOTE_328_NONCE_REPLAY');
  $receipt=['contract'=>self::CONTRACT,
   'state'=>'REMOTE_HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING',
   'company'=>$co,'environment'=>$env,'site_uuid'=>$p['site_uuid'],
   'grant_id'=>$p['grant_id'],'transport_id'=>$p['transport_id'],
   'host_id'=>$p['target_host_id'],'host_principal'=>$p['target_ssh_principal'],
   'target_database'=>$p['target_database'],'target_wp_actor_id'=>$p['target_wp_actor_id'],
   'host_key_fingerprint'=>$p['host_key_fingerprint'],
   'host_policy_sha256'=>$p['host_policy_sha256'],
   'registry_sha256'=>$p['registry_sha256'],
   'signed_sha256'=>$verified['signed_sha256'],'signed_payload'=>$p,
   'proof_epoch'=>time(),'native_executor_certified'=>false];
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  $receiptKey=self::RECEIPT_PREFIX.$co.'_'.$env;
  $oldReceipt=get_option($receiptKey,false);
  if($oldReceipt===false){
   if(!add_option($receiptKey,$receipt,'',false))
    return self::deny('ORIGINAL_REMOTE_328_PROOF_INSERT_FAILED');
  }elseif(!update_option($receiptKey,$receipt,false))
    return self::deny('ORIGINAL_REMOTE_328_PROOF_ROTATION_FAILED');
  $t['state']='REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED';
  $t['full_wpcli_active']=true;$t['host_authority_verified']=true;
  $t['native_access_validation']='signed_remote_hostinger_exact_wpcli';
  $t['native_access_verified_epoch']=time();
  $t['native_access_receipt_sha256']=$verified['signed_sha256'];
  $t['native_access_wpcli_readback_sha256']=hash('sha256',$p['canonical_origin']."\n".$p['target_database']."\n".$p['target_wp_actor_id']);
  $key=$c::TRANSPORT_PREFIX.$p['transport_id'];
  if(!update_option($key,$t,false)){
   if($oldReceipt===false)delete_option($receiptKey);
   else update_option($receiptKey,$oldReceipt,false);
   return self::deny('ORIGINAL_REMOTE_328_TRANSPORT_ACTIVATION_FAILED');
  }
  $after=self::status($co,$env);
  if(($after['verified']??false)!==true)
   return self::deny('ORIGINAL_REMOTE_328_POSTACTIVATION_STATUS_UNCERTAIN');
  return ['state'=>'REMOTE_HOSTINGER_FULL_WPCLI_ACCESS_ACTIVE',
   'company'=>$co,'environment'=>$env,'full_wpcli_active'=>true,
   'host_authority_verified'=>true,'original_305_grant_reused'=>true,
   'new_grants'=>0,'original_312_ready'=>false,'original_313_certified'=>false];
 }
 /** V2 Host Principal proof verification using ORIGINAL 305's Broker verifier.
  * This is NOT v1 activation: no transport, WordPress actor, 328 receipt,
  * or 312/313 execution certificate is created or modified here.
  */
 public static function verifyHostPrincipalV2(array $proof):array {
  if(!self::exactCentral() ||
     !class_exists('MAD4B_Original_Remote_Hostinger_305_V1',false))
   return self::deny('ORIGINAL_328_HOST_V2_ORIGINAL_305_VERIFIER_MISSING');
  $v=MAD4B_Original_Remote_Hostinger_305_V1::verifyHostPrincipalV2($proof);
  if(($v['verified']??false)!==true ||
     ($v['principal_type']??null)!=='signed_host_principal_v2' ||
     ($v['grant_created']??true)!==false ||
     ($v['host_write_authorized']??true)!==false)
   return self::deny('ORIGINAL_328_HOST_V2_EXACT_SIGNED_305_NOT_VERIFIED');
  return ['contract'=>'mad4b.company-native-host-principal.328.v2',
   'state'=>'HOST_V2_SIGNED_PRINCIPAL_VERIFIED__328_ACTIVATION_NOT_CERTIFIED',
   'verified'=>true,'company'=>$v['company'],'environment'=>$v['environment'],
   'site_uuid'=>$v['site_uuid'],'command_sha256'=>$v['command_sha256'],
   'signature_sha256'=>$v['signature_sha256'],
   'principal_type'=>'signed_host_principal_v2',
   'actor_user_id_required'=>false,'original_305_write_grant_present'=>false,
   'original_328_certified'=>false,'original_312_313_certified'=>false,
   'write_authorized'=>false,'grant_created'=>false,'execution_performed'=>false];
 }
 public static function cli($args,$assoc):void {
  // Default Host Principal is shared with ORIGINAL 305; 328 cannot activate
  // on the strength of this plan alone. Legacy signed v1 remains unchanged.
  if(in_array(($args[0]??''),['','plan','principal'],true)
    &&array_keys($assoc)===['company','environment']){
   $co=(string)$assoc['company'];$env=(string)$assoc['environment'];
   $plan=class_exists('MAD4B_Original_Remote_Hostinger_305_V1',false)
     ?MAD4B_Original_Remote_Hostinger_305_V1::defaultHostPrincipal($co,$env)
     :['state'=>'DENIED','reason'=>'ORIGINAL_305_HOST_PRINCIPAL_PROVIDER_MISSING'];
   $plan['original_328_certified']=false;
   WP_CLI::line(wp_json_encode($plan,JSON_UNESCAPED_SLASHES));return;
  }
  if(($args[0]??'')==='verify-host-v2' && array_keys($assoc)===['proof']){
   $raw=base64_decode((string)$assoc['proof'],true);
   if(!is_string($raw)||strlen($raw)>7200)
    WP_CLI::error('ORIGINAL_328_HOST_V2_SIGNED_PROOF_REQUIRED');
   $doc=json_decode($raw,true);
   $r=is_array($doc)?self::verifyHostPrincipalV2($doc)
      :self::deny('ORIGINAL_328_HOST_V2_INVALID_JSON');
   WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES));
   if(($r['verified']??false)!==true)WP_CLI::halt(2);
   return;
  }
  $action=$args[0]??'';
  if($action==='status' && array_keys($assoc)===['company','environment']) {
   WP_CLI::line(wp_json_encode(self::status((string)$assoc['company'],(string)$assoc['environment']),JSON_UNESCAPED_SLASHES));return;
  }
  if($action!=='activate'||array_keys($assoc)!==['proof'])
   WP_CLI::error('ORIGINAL_REMOTE_328_SIGNED_ACTIVATION_REQUIRED');
  $payload=base64_decode((string)$assoc['proof'],true);
  if(!is_string($payload)||strlen($payload)>8500)
   WP_CLI::error('ORIGINAL_REMOTE_328_SIGNED_PAYLOAD_INVALID');
  $doc=json_decode($payload,true);
  $r=is_array($doc)?self::activate($doc):self::deny('ORIGINAL_REMOTE_328_JSON_INVALID');
  if(($r['state']??null)!=='REMOTE_HOSTINGER_FULL_WPCLI_ACCESS_ACTIVE')
   WP_CLI::error($r['reason']??'ORIGINAL_REMOTE_328_ACTIVATION_DENIED');
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b company-remote-hostinger-328',[__CLASS__,'cli']);
 }
}
MAD4B_Original_Remote_Hostinger_328_V1::boot();
}
