<?php
/**
 * Plugin Name: MAD4B Original Company Native Host Proof
 * Description: Verify signed independent company host/site proof against enrolled original 305 grant.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Native_Host_Proof_V1 {
 const CONTRACT='mad4b.company-native-host-proof.v1';
 const RECEIPT='mad4b_company_native_host_proof_receipt_v1_';
 const NONCE='mad4b_company_native_host_proof_nonce_v1_';
 const FIELDS=['contract','action','company','environment','site_uuid',
  'native_profile_uuid','canonical_origin','target_database','target_container',
  'target_wp_actor_id','transport_id','grant_id','host_id','host_policy_sha256',
  'host_key_fingerprint','issued','expires','nonce'];
 private static function denied(string $reason):array {
  return ['contract'=>self::CONTRACT,'state'=>'DENIED','reason'=>$reason,
   'verified'=>false,'production_mutation'=>false,'native_job_executed'=>false];
 }
 public static function verify(array $p):array {
  if (array_keys($p)!==array_merge(self::FIELDS,['signature']))
    return self::denied('EXACT_SIGNED_PAYLOAD_REQUIRED');
  foreach(self::FIELDS as $key)
    if (!is_string($p[$key])&&!is_int($p[$key]))
      return self::denied('SIGNED_FIELD_TYPE_INVALID');
  $now=time();
  if (($p['contract']??'')!==self::CONTRACT || ($p['action']??'')!=='verify'
   || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',(string)$p['company'])
   || $p['company']==='tours-central'
   || !in_array($p['environment'],['staging','production'],true)
   || !wp_is_uuid($p['site_uuid']) || !wp_is_uuid($p['native_profile_uuid'])
   || !preg_match('/^[a-f0-9]{32}$/D',$p['transport_id'])
   || !preg_match('/^[a-f0-9]{32}$/D',$p['grant_id'])
   || !is_int($p['target_wp_actor_id'])||$p['target_wp_actor_id']<1
   || !is_int($p['issued'])||!is_int($p['expires'])
   || $p['issued']<$now-60 || $p['issued']>$now+10
   || $p['expires']<$now || $p['expires']>$p['issued']+60
   || !preg_match('/^[a-f0-9]{32}$/D',$p['nonce'])
   || !preg_match('/^[a-f0-9]{64}$/D',$p['host_policy_sha256'])
   || !preg_match('/^SHA256:[A-Za-z0-9+\/]{43}=?$/D',$p['host_key_fingerprint'])
   || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{3,90}$/D',$p['target_database'])
   || !preg_match('/^[a-z0-9][a-z0-9_-]{3,90}$/D',$p['target_container']))
     return self::denied('PROOF_SCOPE_OR_TIME_INVALID');
  if (!defined('DB_NAME')||DB_NAME!=='etg_commerce_staging'
   || wp_get_environment_type()!=='staging'
   || untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
     return self::denied('EXACT_CENTRAL_STAGING_REQUIRED');
  if (!class_exists('MAD4B_Native_VPS_Principal_V1',false)
   || !class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false)
   || !class_exists('MAD4B_Company_Execution_Resolver_V1',false)
   || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
   || !class_exists('MAD4B_Central_SSH_Command_Intake_V1',false))
     return self::denied('ORIGINAL_CORE_UNAVAILABLE');
  $site=MAD4B_Company_Execution_Resolver_V1::discover()[$p['company'].':'.$p['environment']]??null;
  $enrolled=MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($p['company'],$p['environment']);
  if (!is_array($site)
   || ($site['site_uuid']??null)!==$p['site_uuid']
   || ($site['profile_uuid']??null)!==$p['native_profile_uuid']
   || ($site['canonical_origin']??null)!==$p['canonical_origin']
   || ($enrolled['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
   || ($enrolled['site_uuid']??null)!==$p['site_uuid'])
    return self::denied('ORIGINAL_COMPANY_SITE_OR_PROFILE_DRIFT');
  $slot=get_option('mad4b_company_native_wpcli_slot_v1_'.$p['company'].'_'.$p['environment'],false);
  if(!is_array($slot)||($slot['transport_id']??null)!==$p['transport_id']||($slot['grant_id']??null)!==$p['grant_id']||($slot['site_uuid']??null)!==$p['site_uuid'])return self::denied('ORIGINAL_305_PAIR_SLOT_MISMATCH');
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  $t=get_option($c::TRANSPORT_PREFIX.$p['transport_id'],false);
  $g=get_option($c::PREFIX.$p['grant_id'],false);
  if (!is_array($t)||!is_array($g)
   || ($t['state']??null)!=='PENDING_INDEPENDENT_SSH_VERIFICATION'
   || ($g['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
   || ($g['operation']??null)!=='company.remote.wpcli.full'
   || ($g['grant_mode']??null)!=='persistent'
   || !array_key_exists('expires_epoch',$g) || $g['expires_epoch']!==null
   || ($g['transport_id']??null)!==$p['transport_id']
   || ($t['full_wpcli_grant_id']??null)!==$p['grant_id']
   || ($t['capabilities']??null)!==['wpcli.full']
   || ($t['company']??null)!==$p['company']
   || ($g['company']??null)!==$p['company']
   || ($t['site_uuid']??null)!==$p['site_uuid']
   || ($g['site_uuid']??null)!==$p['site_uuid']
   || ($t['canonical_origin']??null)!==$p['canonical_origin']
   || ($g['canonical_origin']??null)!==$p['canonical_origin']
   || ($t['environment']??null)!==$p['environment']
   || ($g['environment']??null)!==$p['environment']
   || ($t['host_id']??null)!==$p['host_id']
   || ($g['target_host_id']??null)!==$p['host_id']
   || ($g['target_ssh_principal']??null)!=='root'
   || ($t['host_principal']??null)!=='root'
   || ($t['credential_ref']??null)!=='vault:registry/local-srv2030908'
   || ($t['principal_type']??null)!=='signed_vps_wpcli'
   || ($g['principal_type']??null)!=='signed_vps_wpcli'
   || ($g['central_principal_id']??null)!==($t['central_principal_id']??null)
   || ($g['assignment_revision']??null)!==($t['assignment_revision']??null))
     return self::denied('ORIGINAL_305_PERSISTENT_PAIR_NOT_BOUND');
  $native=MAD4B_Native_VPS_Principal_V1::existing();
  $nativeScope=MAD4B_Native_VPS_Principal_V1::scope();
  if (!is_array($native)||!MAD4B_Native_VPS_Principal_V1::ledger($native,$nativeScope)
    || $p['host_id']!==MAD4B_Native_VPS_Principal_V1::HOST
    || ($native['policy_sha256']??null)!==$p['host_policy_sha256']
    || ($native['host_key_fingerprint']??null)!==$p['host_key_fingerprint']
    || ($t['host_key_fingerprint']??null)!==$p['host_key_fingerprint'])
    return self::denied('ORIGINAL_NATIVE_HOST_PRINCIPAL_CHANGED');
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode($p['signature'],true);
  if (!function_exists('sodium_crypto_sign_verify_detached')||!is_string($pub)
   || strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||!is_string($sig)
   || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
    return self::denied('PINNED_NATIVE_SIGNER_UNAVAILABLE');
  $signed=implode("\n",array_map(static fn($key)=>(string)$p[$key],self::FIELDS));
  if(!sodium_crypto_sign_verify_detached($sig,$signed,$pub))
    return self::denied('ORIGINAL_NATIVE_HOST_SIGNATURE_INVALID');
  return ['contract'=>self::CONTRACT,'state'=>'SIGNED_ORIGINAL_HOST_SITE_PROOF_VERIFIED',
    'verified'=>true,'company'=>$p['company'],'environment'=>$p['environment'],
    'site_uuid'=>$p['site_uuid'],'native_profile_uuid'=>$p['native_profile_uuid'],
    'transport_id'=>$p['transport_id'],'grant_id'=>$p['grant_id'],
    'target_container'=>$p['target_container'],'target_database'=>$p['target_database'],
    'target_wp_actor_id'=>$p['target_wp_actor_id'],
    'host_policy_sha256'=>$p['host_policy_sha256'],
    'signed_sha256'=>hash('sha256',$signed),
    'production_mutation'=>false,'native_job_executed'=>false];
 }
 public static function attest(array $p):array {
  $v=self::verify($p);
  if(($v['verified']??false)!==true)return $v;
  $key=self::RECEIPT.$p['company'].'_'.$p['environment'];
  $nonce=self::NONCE.$p['nonce'];
  if (!add_option($nonce,['company'=>$p['company'],'at'=>time()],'',false))
   return self::denied('SIGNED_NONCE_REPLAY');
  $row=['contract'=>self::CONTRACT,'state'=>'HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING',
   'company'=>$p['company'],'environment'=>$p['environment'],
   'site_uuid'=>$p['site_uuid'],'native_profile_uuid'=>$p['native_profile_uuid'],
   'grant_id'=>$p['grant_id'],'transport_id'=>$p['transport_id'],
   'host_policy_sha256'=>$p['host_policy_sha256'],
   'target_container'=>$p['target_container'],'target_database'=>$p['target_database'],
   'target_wp_actor_id'=>$p['target_wp_actor_id'],
   'signed_sha256'=>$v['signed_sha256'],'proof_epoch'=>time(),
   'native_executor_certified'=>false,'production_mutation'=>false];
  $old=get_option($key,false);
  if (is_array($old) && ($old['state']??'')==='HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING') {
    if (($old['grant_id']??null)!==$p['grant_id']
     || ($old['transport_id']??null)!==$p['transport_id'])
      return self::denied('PREVIOUS_VERIFIED_PAIR_CHANGED');
  }
  if(!update_option($key,$row,false)) {
    if(!add_option($key,$row,'',false))
      return self::denied('PROOF_WRITE_OR_READBACK_UNCERTAIN');
  }
  $read=get_option($key,false);
  if (!is_array($read)||$read!==$row)
    return self::denied('INDEPENDENT_HOST_PROOF_READBACK_UNCERTAIN');
  return ['contract'=>self::CONTRACT,'state'=>$row['state'],'verified'=>true,
   'company'=>$p['company'],'environment'=>$p['environment'],
   'native_executor_certified'=>false,'production_mutation'=>false,
   'native_job_executed'=>false];
 }
 public static function status(string $company,string $env):array {
  $r=get_option(self::RECEIPT.$company.'_'.$env,false);
  if(!is_array($r))return self::denied('HOST_PROOF_NOT_FOUND');
  $enrolled=MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($company,$env);
  $native=MAD4B_Native_VPS_Principal_V1::existing();
  if(($enrolled['state']??'')!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
   ||($enrolled['site_uuid']??null)!==($r['site_uuid']??null)
   || !is_array($native)
   || ($native['policy_sha256']??null)!==($r['host_policy_sha256']??null)
   || !is_int($r['proof_epoch']??null)
   || $r['proof_epoch']>time()+10
   || time() - $r['proof_epoch']>1800
   || ($r['grant_id']??null)!==(get_option('mad4b_company_native_wpcli_slot_v1_'.$company.'_'.$env,[])['grant_id']??null)
   || ($r['transport_id']??null)!==(get_option('mad4b_company_native_wpcli_slot_v1_'.$company.'_'.$env,[])['transport_id']??null))
    return self::denied('HOST_PROOF_ORIGINAL_PAIR_NO_LONGER_ELIGIBLE');
  return ['contract'=>self::CONTRACT,'state'=>$r['state'],
   'verified'=>true,'native_executor_certified'=>false,
   'company'=>$company,'environment'=>$env,'proof_epoch'=>$r['proof_epoch'],
   'production_mutation'=>false,'native_job_executed'=>false];
 }
 public static function cli($args,$assoc):void {
  $action=$args[0]??'';
  if ($action==='status'&&array_keys($assoc)===['company','environment']) {
   WP_CLI::line(wp_json_encode(self::status((string)$assoc['company'],
    (string)$assoc['environment']),JSON_UNESCAPED_SLASHES));return;
  }
  if($action!=='attest'||array_keys($assoc)!==['proof'])
    WP_CLI::error('SIGNED_ORIGINAL_HOST_PROOF_REQUIRED');
  $raw=base64_decode((string)$assoc['proof'],true);
  if(!is_string($raw)||strlen($raw)>6500)
    WP_CLI::error('SIGNED_PROOF_INVALID');
  $p=json_decode($raw,true);
  $r=is_array($p)?self::attest($p):self::denied('INVALID_SIGNED_JSON');
  if(($r['verified']??false)!==true)WP_CLI::error($r['reason']??'HOST_PROOF_DENIED');
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b company-native-host-proof',[__CLASS__,'cli']);
 }
}
MAD4B_Company_Native_Host_Proof_V1::boot();
