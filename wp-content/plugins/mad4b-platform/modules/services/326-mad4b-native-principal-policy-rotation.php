<?php
/**
 * Plugin Name: MAD4B Signed VPS Principal Policy Rotation
 * Description: Replaces a signed standing VPS principal pair after root host-policy drift, preserving historical evidence.
 * Version: 0.1.0-candidate
 */
defined('ABSPATH') || exit;
final class MAD4B_Native_VPS_Policy_Rotation_V1 {
 const CONTRACT='mad4b.native-vps-policy-rotation.v1';
 const NONCE='mad4b_native_vps_policy_rotation_nonce_v1_';
 const FIELDS=['contract','action','host_id','company','site_uuid','canonical_origin',
  'environment','old_policy_sha256','new_policy_sha256',
  'host_key_fingerprint','issued','expires','nonce'];
 private static function deny(string $why):array {
  return ['contract'=>self::CONTRACT,'state'=>'DENIED','reason'=>$why,
   'grant_issued'=>false,'execution_performed'=>false];
 }
 private static function jobs_clear():bool {
  if (!class_exists('MAD4B_Central_VPS_Broker_V1',false))return false;
  global $wpdb;
  $prefix=MAD4B_Central_VPS_Broker_V1::JOB;
  $rows=$wpdb->get_results($wpdb->prepare(
   "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
   $wpdb->esc_like($prefix).'%'),ARRAY_A);
  if(!is_array($rows))return false;
  foreach($rows as $row) {
   $v=maybe_unserialize($row['option_value']??null);
   if (!is_array($v) || ($v['principal_type']??null)!=='vps_native'
    || ($v['company']??null)!=='tours-central')continue;
   if(($v['executed']??false)!==true
    || !in_array(($v['state']??''),[
      'GENERAL_SHELL_EXECUTION_READBACK_VERIFIED',
      'GENERAL_SHELL_HISTORICAL_EXECUTION_RECONCILED'],true))
     return false;
  }
  return true;
 }
 public static function verify(array $p):array {
  if (array_keys($p)!==array_merge(self::FIELDS,['signature']))
   return self::deny('SIGNATURE_SHAPE_INVALID');
  foreach(self::FIELDS as $field) {
   if (!is_string($p[$field])&&!is_int($p[$field]))
    return self::deny('FIELD_TYPE_INVALID');
  }
  $s=MAD4B_Native_VPS_Principal_V1::scope();
  $old=MAD4B_Native_VPS_Principal_V1::existing();
  if (!is_array($old)||!is_array($s)
   || !MAD4B_Native_VPS_Principal_V1::ledger($old,$s)
   || ($s['state']??'')!=='CENTRAL_HOST_IDENTITY_VERIFIED')
    return self::deny('EXACT_ORIGINAL_STANDING_PRINCIPAL_REQUIRED');
  $now=time();
  if ($p['contract']!==self::CONTRACT||$p['action']!=='rotate'
   || $p['host_id']!==MAD4B_Native_VPS_Principal_V1::HOST
   || $p['company']!=='tours-central'||$p['environment']!=='staging'
   || $p['site_uuid']!==$s['site_uuid']
   || $p['canonical_origin']!==MAD4B_Native_VPS_Principal_V1::ORIGIN
   || $p['old_policy_sha256']!==$old['policy_sha256']
   || !preg_match('/^[a-f0-9]{64}$/D',$p['new_policy_sha256'])
   || $p['new_policy_sha256']===$p['old_policy_sha256']
   || $p['host_key_fingerprint']!==$old['host_key_fingerprint']
   || !is_int($p['issued'])||!is_int($p['expires'])
   || $p['issued']<$now-60||$p['issued']>$now+10
   || $p['expires']<$now||$p['expires']>$p['issued']+60
   || !preg_match('/^[a-f0-9]{32}$/D',$p['nonce']))
   return self::deny('EXACT_POLICY_ROTATION_SCOPE_INVALID');
  if (!self::jobs_clear())return self::deny('UNCONFIRMED_NATIVE_JOBS_MUST_BE_RESOLVED');
  if (!class_exists('MAD4B_Central_VPS_Broker_V1',false)
   || !class_exists('MAD4B_Central_SSH_Command_Intake_V1',false))
   return self::deny('ORIGINAL_BROKER_MISSING');
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode((string)$p['signature'],true);
  if (!function_exists('sodium_crypto_sign_verify_detached')
   || !is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
   || !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
   return self::deny('PINNED_ORIGINAL_SIGNER_MISSING');
  $message=implode("\n",array_map(static fn($k)=>(string)$p[$k],self::FIELDS));
  if(!sodium_crypto_sign_verify_detached($sig,$message,$pub))
   return self::deny('ORIGINAL_HOST_SIGNATURE_INVALID');
  return ['contract'=>self::CONTRACT,'state'=>'SIGNED_ROOT_POLICY_ROTATION_ADMITTED',
   'original_slot'=>$old,'new_policy_sha256'=>$p['new_policy_sha256'],
   'grant_issued'=>false,'execution_performed'=>false];
 }
 public static function rotate(array $p):array {
  $verified=self::verify($p);
  if (($verified['state']??null)!=='SIGNED_ROOT_POLICY_ROTATION_ADMITTED')
   return $verified;
  if (!add_option(self::NONCE.$p['nonce'],
      ['at'=>time(),'host_id'=>$p['host_id']],'',false))
   return self::deny('SIGNED_NONCE_REPLAY');
  $old=$verified['original_slot'];
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  $oldT=get_option($c::TRANSPORT_PREFIX.$old['transport_id'],false);
  $oldG=get_option($c::PREFIX.$old['grant_id'],false);
  if (!is_array($oldT)||!is_array($oldG))
   return self::deny('ORIGINAL_PAIR_DISAPPEARED');
  $newT=$oldT;$newG=$oldG;
  $tid=bin2hex(random_bytes(16));$gid=bin2hex(random_bytes(16));
  $newT['transport_id']=$tid;$newG['grant_id']=$gid;
  $newT['host_policy_sha256']=$p['new_policy_sha256'];
  $newG['host_policy_sha256']=$p['new_policy_sha256'];
  $newG['transport_id']=$tid;
  $newT['host_authority_verified']=false;
  $newG['execution_authorized']=false;$newG['executed']=false;
  $newT['state']='PENDING_INDEPENDENT_SSH_VERIFICATION';
  $newG['state']='CENTRAL_OPERATION_SCOPE_GRANTED';
  $new=['principal'=>$old['principal'],'host_id'=>$old['host_id'],
   'site_uuid'=>$old['site_uuid'],
   'policy_sha256'=>$p['new_policy_sha256'],
   'host_key_fingerprint'=>$old['host_key_fingerprint'],
   'transport_id'=>$tid,'grant_id'=>$gid];
  if(!add_option($c::TRANSPORT_PREFIX.$tid,$newT,'',false))
   return self::deny('REPLACEMENT_TRANSPORT_NOT_CREATED');
  if(!add_option($c::PREFIX.$gid,$newG,'',false)){
   delete_option($c::TRANSPORT_PREFIX.$tid);
   return self::deny('REPLACEMENT_GRANT_NOT_CREATED');
  }
  // Keep admission fail-closed if an older native job became uncertain
  // while the replacement pair was being prepared.
  if (!self::jobs_clear()) {
   delete_option($c::PREFIX.$gid);delete_option($c::TRANSPORT_PREFIX.$tid);
   return self::deny('IN_FLIGHT_NATIVE_JOB_APPEARED_BEFORE_ROTATION');
  }
  if (!update_option(MAD4B_Native_VPS_Principal_V1::SLOT,$new,false)) {
   delete_option($c::PREFIX.$gid);delete_option($c::TRANSPORT_PREFIX.$tid);
   return self::deny('ORIGINAL_PRINCIPAL_SLOT_NOT_SWITCHED');
  }
  $nowSlot=MAD4B_Native_VPS_Principal_V1::existing();
  $scope=MAD4B_Native_VPS_Principal_V1::scope();
  if (!is_array($nowSlot)||$nowSlot!==$new
   || !MAD4B_Native_VPS_Principal_V1::ledger($new,$scope))
   return self::deny('NEW_PRINCIPAL_READBACK_UNCERTAIN_STOP');
  // Historic records are retained, but their old grant and transport are
  // explicitly revoked so they cannot be used for future jobs.
  $oldG['state']='REVOKED';$oldT['state']='REVOKED';
  update_option($c::PREFIX.$old['grant_id'],$oldG,false);
  update_option($c::TRANSPORT_PREFIX.$old['transport_id'],$oldT,false);
  $oldRevoked=(get_option($c::PREFIX.$old['grant_id'],[])['state']??null)==='REVOKED'
   && (get_option($c::TRANSPORT_PREFIX.$old['transport_id'],[])['state']??null)==='REVOKED';
  return ['contract'=>self::CONTRACT,
   'state'=>$oldRevoked?'ORIGINAL_PRINCIPAL_POLICY_ROTATED':'OLD_PAIR_REVOCATION_READBACK_REQUIRED',
   'original_principal_ledger_verified'=>true,
   'historical_records_retained'=>true,
   'old_pair_revoked'=>$oldRevoked,
   'grant_issued'=>true,'execution_performed'=>false];
 }
 public static function cli($args,$assoc):void {
  if(!defined('WP_CLI')||!WP_CLI||!class_exists('WP_CLI')||
     $args!==['rotate']||array_keys($assoc)!==['proof'])
   WP_CLI::error('SIGNED_ORIGINAL_POLICY_ROTATION_REQUIRED');
  $raw=base64_decode((string)$assoc['proof'],true);
  if (!is_string($raw)||strlen($raw)>3500)WP_CLI::error('SIGNED_PROOF_INVALID');
  $proof=json_decode($raw,true);
  $result=is_array($proof)?self::rotate($proof):self::deny('INVALID_JSON');
  if (($result['state']??null)!=='ORIGINAL_PRINCIPAL_POLICY_ROTATED')
   WP_CLI::error($result['reason']??$result['state']??'ROTATION_FAILED');
  WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b native-vps-policy',[__CLASS__,'cli']);
 }
}
MAD4B_Native_VPS_Policy_Rotation_V1::boot();
