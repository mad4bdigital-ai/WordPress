<?php
/** Plugin Name: MAD4B Signed Native WPCLI Access Activation; Description: Explicit root-host access activation for an existing original 305 company pair. No new grants. Version: 0.1.0 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Native_Access_Activation_V1 {
 const CONTRACT='mad4b.native-wpcli-access-activation.v1';
 const NONCE='mad4b_native_wpcli_access_nonce_v1_';
 const FIELDS=['contract','action','company','environment','site_uuid','canonical_origin','profile_uuid','transport_id','grant_id','target_container','target_database','target_wp_actor_id','host_id','host_policy_sha256','host_key_fingerprint','readback_sha256','issued','expires','nonce'];
 private static function denied($why):array{return ['state'=>'DENIED','reason'=>$why,'full_wpcli_active'=>false,'new_grants'=>0];}
 public static function activate(array $v):array {
  if(array_keys($v)!==array_merge(self::FIELDS,['signature']))return self::denied('PROOF_FIELDS_INVALID');
  $now=time();$co=(string)$v['company'];$env=(string)$v['environment'];
  if(!defined('WP_CLI')||!WP_CLI||!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   ||!MAD4B_Central_Dual_Lane_Registry_V1::central())return self::denied('CENTRAL_WPCLI_REQUIRED');
  if($v['contract']!==self::CONTRACT||!in_array($v['action'],['activate','rotate'],true)
   ||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$co)||$co==='tours-central'
   ||!preg_match('/^[a-z][a-z0-9_-]{1,31}$/D',$env)
   ||!wp_is_uuid((string)$v['site_uuid'])||!wp_is_uuid((string)$v['profile_uuid'])
   ||!preg_match('/^[a-f0-9]{32}$/D',(string)$v['transport_id'])
   ||!preg_match('/^[a-f0-9]{32}$/D',(string)$v['grant_id'])
   ||!preg_match('/^(?:[a-z0-9][a-z0-9_-]{3,90}-php|[a-f0-9]{64})$/D',(string)$v['target_container'])
   ||!preg_match('/^[a-z0-9_]{4,90}$/D',(string)$v['target_database'])
   ||!is_int($v['target_wp_actor_id'])||$v['target_wp_actor_id']<1
   ||!preg_match('/^[a-f0-9]{64}$/D',(string)$v['host_policy_sha256'])
   ||!preg_match('/^SHA256:[A-Za-z0-9+\/]{43}=?$/D',(string)$v['host_key_fingerprint'])
   ||!preg_match('/^[a-f0-9]{64}$/D',(string)$v['readback_sha256'])
   ||!preg_match('/^[a-f0-9]{32}$/D',(string)$v['nonce'])
   ||!is_int($v['issued'])||!is_int($v['expires'])
   ||$v['issued']<$now-45||$v['issued']>$now+5
   ||$v['expires']<$now||$v['expires']>$v['issued']+90)
    return self::denied('EXACT_SCOPE_OR_TIME_INVALID');
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode((string)$v['signature'],true);
  if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)return self::denied('PINNED_SIGNER_UNAVAILABLE');
  $message=implode("\n",array_map(static fn($k)=>(string)$v[$k],self::FIELDS));
  if(!sodium_crypto_sign_verify_detached($sig,$message,$pub))return self::denied('INVALID_HOST_ROOT_SIGNATURE');
  $site=MAD4B_Company_Environment_Registry_V2::target($co,$env);
  if(!is_array($site)||($site['company_id']??null)!==$co||($site['environment']??null)!==$env
    ||($site['site_uuid']??null)!==$v['site_uuid']||($site['canonical_origin']??null)!==$v['canonical_origin'])return self::denied('CENTRAL_SITE_SCOPE_DRIFT');
  $resolver=MAD4B_Company_Execution_Resolver_V1::discover()[$co.':'.$env]??[];
  if(($resolver['profile_uuid']??null)!==$v['profile_uuid'])return self::denied('SITE_PROFILE_IDENTITY_DRIFT');
  $r=MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($co,$env);
  if(($r['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'||($r['site_uuid']??null)!==$v['site_uuid'])return self::denied('ORIGINAL_305_NOT_AUTHORIZED');
  $slot=get_option(MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.$co.'_'.$env,false);
  if(!is_array($slot)||($slot['transport_id']??null)!==$v['transport_id']||($slot['grant_id']??null)!==$v['grant_id'])return self::denied('PERSISTENT_PAIR_CHANGED');
  $host=MAD4B_Native_VPS_Principal_V1::existing();
  if(!is_array($host)||($host['policy_sha256']??null)!==$v['host_policy_sha256']
   ||($host['host_key_fingerprint']??null)!==$v['host_key_fingerprint']
   ||$v['host_id']!==MAD4B_Native_VPS_Principal_V1::HOST)return self::denied('ORIGINAL_HOST_POLICY_DRIFT');
  $prefix=MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX;
  $name=$prefix.$v['transport_id'];$t=get_option($name,false);
  if(!is_array($t)||($t['company']??null)!==$co||($t['environment']??null)!==$env
   ||($t['site_uuid']??null)!==$v['site_uuid']||($t['full_wpcli_grant_id']??null)!==$v['grant_id']
   ||($t['full_wpcli_pending_native_enrollment']??false)!==true||($t['capabilities']??null)!==['wpcli.full'])return self::denied('ORIGINAL_TRANSPORT_CHANGED');
  // An active Original 305 grant may survive Coolify container replacement,
  // but its independent host-access certificate MUST be rotated by an exact
  // original broker-signed receipt. No automatic grants or unsigned edits.
  $active=($t['full_wpcli_active']??false)===true;
  $rotate=$v['action']==='rotate';
  if ($active) {
   if(($t['state']??null)!=='NATIVE_ROOT_WPCLI_ACCESS_VERIFIED'
     ||($t['host_authority_verified']??false)!==true
     ||($t['native_access_validation']??null)!=='signed_root_host_exact_tenant_wpcli'
     ||!preg_match('/^[a-f0-9]{64}$/D',(string)($t['native_access_receipt_sha256']??''))
     ||!preg_match('/^[a-f0-9]{64}$/D',(string)($t['native_access_wpcli_readback_sha256']??'')))
      return self::denied('PREVIOUS_ORIGINAL_ACCESS_CERTIFICATE_INVALID');
   if ($rotate) {
    if(($t['native_access_target_container']??null)===$v['target_container']
      ||($t['native_access_target_database']??null)!==$v['target_database'])
      return self::denied('EXACT_CONTAINER_CHANGE_AND_SAME_DATABASE_REQUIRED');
    if(!class_exists('MAD4B_Company_Native_Host_Proof_V1',false))
      return self::denied('PREVIOUS_ORIGINAL_HOST_PROOF_REQUIRED');
    $oldProof=get_option(MAD4B_Company_Native_Host_Proof_V1::RECEIPT.$co.'_'.$env,false);
    if(!is_array($oldProof)
      ||($oldProof['state']??null)!=='HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING'
      ||($oldProof['company']??null)!==$co||($oldProof['environment']??null)!==$env
      ||($oldProof['site_uuid']??null)!==$v['site_uuid']
      ||($oldProof['native_profile_uuid']??null)!==$v['profile_uuid']
      ||($oldProof['grant_id']??null)!==$v['grant_id']
      ||($oldProof['transport_id']??null)!==$v['transport_id']
      ||($oldProof['target_container']??null)!==($t['native_access_target_container']??null)
      ||($oldProof['target_database']??null)!==$v['target_database']
      ||($oldProof['target_wp_actor_id']??null)!==$v['target_wp_actor_id']
      ||($oldProof['host_policy_sha256']??null)!==$v['host_policy_sha256']
      ||!preg_match('/^[a-f0-9]{64}$/D',(string)($oldProof['signed_sha256']??'')))
      return self::denied('SIGNED_PREVIOUS_SITE_ACTOR_OR_HOST_PROOF_MISMATCH');
    $oldLog=(array)($t['native_access_rotation_history']??[]);
    $oldLog[]=['container'=>$t['native_access_target_container'],
      'receipt_sha256'=>$t['native_access_receipt_sha256'],
      'verified_epoch'=>$t['native_access_verified_epoch']??null,
      'superseded_at'=>$now,'replacement_container'=>$v['target_container']];
    $t['native_access_rotation_history']=array_slice($oldLog,-12);
   } elseif(($t['native_access_target_container']??null)!==$v['target_container']
       ||($t['native_access_target_database']??null)!==$v['target_database'])
       return self::denied('SIGNED_CONTAINER_ROTATION_REQUIRED');
  } elseif($rotate) return self::denied('ROTATION_REQUIRES_ACTIVE_ORIGINAL_305_ACCESS');
  $n=self::NONCE.$v['nonce'];
  if(!add_option($n,['site_uuid'=>$v['site_uuid'],'proof_sha256'=>hash('sha256',$message),'issued'=>$v['issued']],'',false))
   return self::denied('ACCESS_RECEIPT_REPLAY');
  if (!$active || $rotate) {
   $t['state']='NATIVE_ROOT_WPCLI_ACCESS_VERIFIED';$t['host_authority_verified']=true;
   $t['full_wpcli_active']=true;$t['native_access_verified_epoch']=$now;
   $t['native_access_receipt_sha256']=hash('sha256',$message);
   $t['native_access_target_container']=$v['target_container'];
   $t['native_access_target_database']=$v['target_database'];
   $t['native_access_target_wp_actor_id']=$v['target_wp_actor_id'];
   $t['native_access_wpcli_readback_sha256']=$v['readback_sha256'];
   $t['native_access_validation']='signed_root_host_exact_tenant_wpcli';
   if(!update_option($name,$t,false))return self::denied('ORIGINAL_TRANSPORT_WRITE_FAILED');
   $verify=get_option($name,false);
   if(!is_array($verify)||($verify['native_access_receipt_sha256']??null)!==hash('sha256',$message)
     ||($verify['native_access_target_container']??null)!==$v['target_container']
     ||($verify['full_wpcli_active']??false)!==true)
      return self::denied('SIGNED_ROTATION_POSTWRITE_READBACK_UNCERTAIN');
  }
  $back=MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($co,$env);
  return ['state'=>($back['full_wpcli_active']??false)===true?'FULL_WPCLI_ACCESS_ACTIVE':'READBACK_FAILED',
   'company'=>$co,'environment'=>$env,'site_uuid'=>$v['site_uuid'],
   'full_wpcli_active'=>$back['full_wpcli_active']??false,'host_authority_verified'=>$back['host_authority_verified']??false,
   'new_grants'=>0,'313_executor_certified'=>false,'signed_host_access_verified'=>true];
 }
 public static function cli($args,$assoc):void {
  if(!in_array(($args[0]??''),['activate','rotate'],true)||count($assoc)!==1||!isset($assoc['proof']))WP_CLI::error('SIGNED_ACCESS_RECEIPT_REQUIRED');
  $raw=base64_decode((string)$assoc['proof'],true);
  if(!is_string($raw)||strlen($raw)>6000)WP_CLI::error('SIGNED_RECEIPT_INVALID');
  $proof=json_decode($raw,true);if(!is_array($proof)||($proof['action']??null)!==($args[0]??null))WP_CLI::error('SIGNED_ACTION_MISMATCH');
  $result=self::activate($proof);
  if(($result['state']??'')!=='FULL_WPCLI_ACCESS_ACTIVE')WP_CLI::error($result['reason']??'ACTIVATION_DENIED');
  WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))WP_CLI::add_command('mad4b company-native-access',[__CLASS__,'cli']);}
}
MAD4B_Company_Native_Access_Activation_V1::boot();
