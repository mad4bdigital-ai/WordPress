<?php
/**
 * Plugin Name: MAD4B Broker Historical Native Receipt Reconciliation
 * Description: Signed, evidence-bound closure of an expired previously executed Staging Native Job. Does not execute, extend, or grant.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Broker_Late_Native_Reconciliation_V1 {
 const CONTRACT='mad4b.broker-late-native-reconciliation.v1';
 const NONCE='mad4b_broker_late_native_nonce_v1_';
 const HOST='srv2030908';
 const FIELDS=['contract','host_id','job_id','grant_id','transport_id',
  'company','site_uuid','origin','environment','principal_type','principal_id',
  'correlation_id','command_sha256','host_policy_sha256','native_receipt_sha256',
  'readback_sha256','expected_readback_sha256','intent','native_state',
  'started_epoch','finished_epoch','command_exit_code','readback_exit_code',
  'issued','expires','nonce'];
 private static function deny(string $reason):array {
  return ['contract'=>self::CONTRACT,'verified'=>false,'central_completion_recorded'=>false,
   'reason'=>$reason,'execution_performed'=>false];
 }
 public static function message(array $a) {
  if (array_keys($a)!==array_merge(self::FIELDS,['signature']))return false;
  $fields=[];
  foreach(self::FIELDS as $k) {
   if (!is_string($a[$k]) && !is_int($a[$k]))return false;
   $fields[]=(string)$a[$k];
  }
  return implode("\n",$fields);
 }
 private static function current_scope(array $job,array $grant,array $transport,array $req,array $claim,string $id):bool {
  if (!class_exists('MAD4B_Native_VPS_Principal_V1',false)
   || !MAD4B_Native_VPS_Principal_V1::valid_job_chain($job,$grant,$transport,$req)
   || ($job['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
   || ($job['executed']??null)!==false
   || ($claim['job_id']??null)!==$id
   || ($claim['host_id']??null)!==self::HOST
   || ($claim['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
   || ($req['request_id']??null)!==$id
   || ($req['status']??null)!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
   || ($job['grant_mode']??null)!=='persistent'
   || ($grant['grant_mode']??null)!=='persistent'
   || ($grant['operation']??null)!=='maintenance.shell'
   || ($job['operation']??null)!=='maintenance.shell'
   || ($job['target_host_id']??null)!==self::HOST
   || ($req['target_host_id']??null)!==self::HOST
   || ($job['broker_host_id']??null)!==self::HOST
   || ($job['target_scope']??null)!=='host'
   || ($req['target_scope']??null)!=='host'
   || ($job['central_principal_id']??null)!==0
   || ($req['central_principal_id']??null)!==0
   || ($job['canonical_origin']??null)!=='https://tours.dev.mad4b.com'
   || ($job['environment']??null)!=='staging'
   || ($req['correlation_id']??null)!==($job['correlation_id']??null)
   || ($req['command_sha256']??null)!==($job['command_sha256']??null)
   || (int)($claim['claimed_epoch']??0)<1)return false;
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  $key=$c::CLAIM_PREFIX.$job['grant_id'].'_'.hash('sha256',$job['correlation_id']);
  return get_option($key,false)===$id;
 }
 public static function reconcile(array $p):array {
  if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
    || !MAD4B_Central_Dual_Lane_Registry_V1::central()
    || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
    || !class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
    || !class_exists('MAD4B_Broker_General_Shell_Receipt_V1',false))
    return self::deny('ORIGINAL_CENTRAL_CORE_MISSING');
  $body=self::message($p);
  if (!is_string($body) || strlen($body)>3500)return self::deny('INVALID_PROOF_SHAPE');
  $now=time();
  if ($p['contract']!==self::CONTRACT || $p['host_id']!==self::HOST
   || $p['company']!=='tours-central' || $p['environment']!=='staging'
   || $p['origin']!=='https://tours.dev.mad4b.com'
   || $p['principal_type']!=='vps_native'
   || $p['principal_id']!=='vps-native:srv2030908'
   || $p['intent']!=='diagnostic' || $p['native_state']!=='VERIFIED'
   || !preg_match('/^[a-f0-9]{32}$/D',$p['job_id'])
   || !preg_match('/^[a-f0-9]{32}$/D',$p['grant_id'])
   || !preg_match('/^[a-f0-9]{32}$/D',$p['transport_id'])
   || !preg_match('/^[A-Za-z0-9_.-]{8,90}$/D',$p['correlation_id'])
   || !preg_match('/^[a-f0-9]{32}$/D',$p['nonce']))
    return self::deny('HISTORICAL_SCOPE_INVALID');
  foreach(['command_sha256','host_policy_sha256','native_receipt_sha256',
   'readback_sha256','expected_readback_sha256'] as $key)
    if (!preg_match('/^[a-f0-9]{64}$/D',$p[$key]))return self::deny('HASH_INVALID');
  foreach(['started_epoch','finished_epoch','command_exit_code','readback_exit_code',
   'issued','expires'] as $key)
    if (!is_int($p[$key]))return self::deny('TIMESTAMP_OR_EXIT_TYPE_INVALID');
  if ($p['issued']<$now-120 || $p['issued']>$now+10
   || $p['expires']<$now || $p['expires']>$p['issued']+120
   || $p['started_epoch']<=0 || $p['finished_epoch']<$p['started_epoch']
   || $p['command_exit_code']!==0 || $p['readback_exit_code']!==0
   || !hash_equals($p['readback_sha256'],$p['expected_readback_sha256']))
    return self::deny('HISTORICAL_READBACK_OR_TIME_INVALID');
  $sig=base64_decode($p['signature'],true);
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  if (!function_exists('sodium_crypto_sign_verify_detached')
   || !is_string($sig) || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
   || !is_string($pub) || strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
   || !sodium_crypto_sign_verify_detached($sig,$body,$pub))
    return self::deny('ORIGINAL_HOST_SIGNATURE_INVALID');
  $id=$p['job_id'];$c='MAD4B_Central_SSH_Command_Intake_V1';
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
  $request=get_option($c::REQUEST_PREFIX.$id,false);
  if (!is_array($job)||!is_array($request))return self::deny('JOB_OR_REQUEST_MISSING');
  $grant=get_option($c::PREFIX.(string)($job['grant_id']??''),false);
  $transport=get_option($c::TRANSPORT_PREFIX.(string)($job['transport_id']??''),false);
  $claim=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false);
  if (!is_array($grant)||!is_array($transport)||!is_array($claim)
    || !self::current_scope($job,$grant,$transport,$request,$claim,$id))
    return self::deny('ORIGINAL_CLAIM_OR_NATIVE_GRANT_REVOKED');
  foreach(['grant_id'=>'grant_id','transport_id'=>'transport_id',
   'company'=>'company','site_uuid'=>'site_uuid','environment'=>'environment',
   'principal_type'=>'principal_type','principal_id'=>'principal_id',
   'correlation_id'=>'correlation_id','command_sha256'=>'command_sha256',
   'host_policy_sha256'=>'host_policy_sha256','canonical_origin'=>'origin'] as $j=>$pfield)
    if (($job[$j]??null)!==$p[$pfield])return self::deny('JOB_EVIDENCE_MISMATCH_'.$j);
  $expires=$job['expires_epoch']??null;
  $created=$job['created_epoch']??null;
  $claimed=$claim['claimed_epoch']??null;
  if (!is_int($expires) || !is_int($created) || !is_int($claimed)
   || $expires>=$now || $now-$expires>7*86400
   || $p['started_epoch']<$claimed
   || $p['started_epoch']<$created
   || $p['finished_epoch']>$expires
   || $p['finished_epoch']>$now)
    return self::deny('ORIGINAL_EXECUTION_TIMELINE_NOT_PROVEN');
  $job_key=MAD4B_Central_VPS_Broker_V1::JOB.$id;
  $receipt_key=MAD4B_Broker_General_Shell_Receipt_V1::PREFIX.$id;
  $nonce_key=self::NONCE.$p['nonce'];
  $digest=hash('sha256',$body);
  $existing=get_option($nonce_key,null);
  if ($existing!==null) {
   $old=get_option($receipt_key,false);
   if ($existing!==$id || !is_array($old)
     || ($old['contract']??null)!==self::CONTRACT
     || ($old['signed_payload_sha256']??null)!==$digest
     || ($old['native_receipt_sha256']??null)!==$p['native_receipt_sha256']
     || ($old['nonce']??null)!==$p['nonce'])
     return self::deny('NONCE_REPLAY_OR_CONFLICT');
   // Recover only an interrupted state update; never accept replay after completion.
   if (($job['executed']??null)!==false)return self::deny('ALREADY_COMPLETED');
  } else {
   if (get_option($receipt_key,false)!==false)return self::deny('RECEIPT_ALREADY_EXISTS');
   if (!add_option($nonce_key,$id,'',false))return self::deny('NONCE_REPLAY');
   $record=['contract'=>self::CONTRACT,'state'=>'HISTORICAL_NATIVE_VERIFIED',
    'job_id'=>$id,'grant_id'=>$job['grant_id'],
    'transport_id'=>$job['transport_id'],'correlation_id'=>$job['correlation_id'],
    'command_sha256'=>$p['command_sha256'],
    'readback_sha256'=>$p['readback_sha256'],'native_receipt_sha256'=>$p['native_receipt_sha256'],
    'native_started_epoch'=>$p['started_epoch'],'native_finished_epoch'=>$p['finished_epoch'],
    'nonce'=>$p['nonce'],'signed_payload_sha256'=>$digest,
    'recorded_at'=>gmdate('c'),'original_host_signature_verified'=>true,
    'original_scope_verified_as_historical'=>true,
    'automatic_execution_claimed'=>false,'production_mutation'=>false];
   if (!add_option($receipt_key,$record,'',false))
     return self::deny('RECEIPT_CONFLICT');
  }
  $job=get_option($job_key,false);
  if (!is_array($job) || ($job['executed']??null)!==false
    || ($job['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY')
    return self::deny('JOB_CHANGED_BEFORE_FINALIZE');
  $job['state']='GENERAL_SHELL_HISTORICAL_EXECUTION_RECONCILED';
  $job['executed']=true;
  $job['independent_host_permission_verified']=true;
  $job['independent_readback_verified']=true;
  $job['receipt_contract']=self::CONTRACT;
  $job['historical_reconciliation']=true;
  $job['automatic_execution_claimed']=false;
  $job['historical_native_receipt_sha256']=$p['native_receipt_sha256'];
  if (!update_option($job_key,$job,false))return self::deny('JOB_WRITE_UNCERTAIN');
  $seen=get_option($job_key,false);
  if (!is_array($seen) || ($seen['executed']??null)!==true
   || ($seen['receipt_contract']??null)!==self::CONTRACT
   || ($seen['state']??null)!=='GENERAL_SHELL_HISTORICAL_EXECUTION_RECONCILED')
    return self::deny('JOB_READBACK_UNCERTAIN');
  return ['contract'=>self::CONTRACT,'verified'=>true,
   'central_completion_recorded'=>true,'state'=>$seen['state'],
   'job_id'=>$id,'historical_reconciliation'=>true,
   'automatic_execution_claimed'=>false,'execution_performed'=>false];
 }
 public static function cli($args,$assoc):void {
  if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')
   || $args!==['reconcile'] || array_keys($assoc)!==['payload'])
    WP_CLI::error('SIGNED_HISTORICAL_RECEIPT_REQUIRED');
  $raw=base64_decode((string)$assoc['payload'],true);
  if (!is_string($raw) || strlen($raw)>7000)WP_CLI::error('INVALID_PROOF');
  $data=json_decode($raw,true);
  $r=is_array($data)?self::reconcile($data):self::deny('INVALID_JSON');
  if (($r['verified']??false)!==true)WP_CLI::error($r['reason']??'DENIED');
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b broker-late-native',[__CLASS__,'cli']);
 }
}
MAD4B_Broker_Late_Native_Reconciliation_V1::boot();
