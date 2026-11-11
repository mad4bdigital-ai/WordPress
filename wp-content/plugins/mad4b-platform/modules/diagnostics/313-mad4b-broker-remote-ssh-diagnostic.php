<?php
/**
 * Plugin Name: MAD4B Broker Remote SSH Diagnostic Receipt
 * Description: Verify broker-signed execution/readback of a fixed non-mutating diagnostic on a scoped Registry SSH target.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Broker_Remote_SSH_Receipt_V1 {
 const CONTRACT='mad4b.broker-remote-ssh-diagnostic.v1';
 const COMMAND='printf MAD4B_REGISTRY_SSH_READ_CANARY';
 const OUTPUT='MAD4B_REGISTRY_SSH_READ_CANARY';
 const PREFIX='mad4b_broker_remote_ssh_receipt_v1_';
 const HOST='srv2030908';
 const WPCLI_CONTRACT='mad4b.broker-remote-wpcli-receipt.v1';
 const WPCLI_PREFIX='mad4b_broker_remote_wpcli_receipt_v1_';
 const WPCLI_FINISH='mad4b.broker-wpcli-native-finish.v1';
 const WPCLI_FINISH_PREFIX='mad4b_broker_wpcli_native_finish_v1_';
 const WPCLI_FINISH_FIELDS=['contract','host_id','job_id','target_id','grant_id',
   'transport_id','site_uuid','origin','environment','command_sha256',
   'native_receipt_sha256','expected_readback_sha256','readback_sha256',
   'output_sha256','exit_code','issued','expires','nonce'];

 private static function deny($why):array {return ['contract'=>self::CONTRACT,'verified'=>false,'reason'=>$why];}
 private static function record(array $a):array {
  if (!class_exists('MAD4B_Broker_Exact_SSH_Authority_V1',false))return self::deny('AUTHORITY_PROVIDER_MISSING');
  $id=(string)($a['job']??''); $target=(string)($a['target']??'');
  $digest=(string)($a['command_sha256']??'');
  $out=(string)($a['output_sha256']??'');$read=(string)($a['readback_sha256']??'');
  $nonce=(string)($a['nonce']??'');$iat=(int)($a['issued']??0);$exp=(int)($a['expires']??0);
  if ($digest!==hash('sha256',self::COMMAND)||$out!==hash('sha256',self::OUTPUT)
    || $read!==hash('sha256',self::OUTPUT)
    || !preg_match('/^[a-f0-9]{32}$/D',$nonce)
    || $iat<time()-60 || $iat>time()+10 || $exp<$iat || $exp>$iat+60 || $exp<time())
     return self::deny('RECEIPT_SCOPE_INVALID');
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode((string)($a['signature']??''),true);
  if (!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    || !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
      return self::deny('BROKER_SIGNER_MISSING');
  $msg=implode("\n",[self::CONTRACT,'receipt',self::HOST,$id,$target,$nonce,
    (string)$iat,(string)$exp,$digest,$out,$read]);
  if (!sodium_crypto_sign_verify_detached($sig,$msg,$pub))return self::deny('INVALID_BROKER_SIGNATURE');
  $authority=MAD4B_Broker_Exact_SSH_Authority_V1::evaluate($id,$target,$digest);
  if (($authority['eligible']??false)!==true)return self::deny('CENTRAL_GRANT_OR_CLAIM_NO_LONGER_ELIGIBLE');
  $row=['contract'=>self::CONTRACT,'job_id'=>$id,'target_id'=>$target,
    'site_uuid'=>$authority['site_uuid'],'environment'=>$authority['environment'],
    'company'=>$authority['company'],'grant_id'=>$authority['grant_id'],
    'command_sha256'=>$digest,'output_sha256'=>$out,'readback_sha256'=>$read,
    'broker_host_id'=>self::HOST,'signed_at_epoch'=>$iat,'recorded_epoch'=>time(),
    'signed_host_execution'=>true,'independent_readback_verified'=>true,
    'state'=>'REGISTRY_SSH_DIAGNOSTIC_READBACK_VERIFIED','production_mutation'=>false];
  if (!add_option(self::PREFIX.$id,$row,'',false))return self::deny('RECEIPT_ALREADY_EXISTS');
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
  if (!is_array($job))return self::deny('JOB_DISAPPEARED');
  $job['state']='REGISTRY_SSH_DIAGNOSTIC_READBACK_VERIFIED';
  $job['executed']=true;
  $job['independent_host_permission_verified']=true;
  $job['independent_readback_verified']=true;
  $job['receipt_contract']=self::CONTRACT;
  if (!update_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,$job,false))
     return self::deny('JOB_UPDATE_FAILED');
  return ['contract'=>self::CONTRACT,'verified'=>true,
    'state'=>'REGISTRY_SSH_DIAGNOSTIC_READBACK_VERIFIED','job_id'=>$id,
    'target_id'=>$target,'independent_readback_verified'=>true,'production_mutation'=>false];
 }

 // A separate signed Full WP-CLI receipt, sharing the original 312 scope gate.
 // A receipt without a certified native executor is retained as PENDING,
 // not treated as evidence of successful remote execution.
 private static function wpcli_receipt(array $a):array {
  $id=(string)($a['job']??'');
  $target=(string)($a['target']??'');
  $digest=(string)($a['command_sha256']??'');
  $output=(string)($a['output_sha256']??'');
  $readback=(string)($a['readback_sha256']??'');
  $nonce=(string)($a['nonce']??'');
  $issued=(int)($a['issued']??0);
  $expires=(int)($a['expires']??0);
  $exit=(string)($a['exit_code']??'');
  if (!preg_match('/^[a-f0-9]{32}$/D',$id)
    || !preg_match('/^[a-f0-9]{64}$/D',$digest)
    || !preg_match('/^[a-f0-9]{64}$/D',$output)
    || !preg_match('/^[a-f0-9]{64}$/D',$readback)
    || !preg_match('/^[a-f0-9]{32}$/D',$nonce)
    || !preg_match('/^(?:0|[1-9][0-9]{0,2})$/D',$exit)
    || (int)$exit>255 || $issued<time()-60 || $issued>time()+10
    || $expires<$issued || $expires>$issued+60 || $expires<time())
      return self::deny('WPCLI_RECEIPT_SCOPE_INVALID');
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode((string)($a['signature']??''),true);
  if (!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
      || !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
        return self::deny('WPCLI_SIGNER_MISSING');
  // Purpose separation avoids reusing the old fixed-diagnostic signature.
  $payload=implode("\n",[self::WPCLI_CONTRACT,'receipt',self::HOST,$id,$target,
    $nonce,(string)$issued,(string)$expires,$digest,$output,$readback,$exit]);
  if (!sodium_crypto_sign_verify_detached($sig,$payload,$pub))
    return self::deny('INVALID_WPCLI_BROKER_SIGNATURE');
  $authority=MAD4B_Broker_Exact_SSH_Authority_V1::evaluate($id,$target,$digest);
  if (($authority['eligible']??false)!==true
   || ($authority['owner_enrolled_wpcli']??false)!==true
   || ($authority['operation']??null)!=='company.remote.wpcli.full'
   || ($authority['target_scope']??null)!=='wordpress')
      return self::deny('OWNER_WPCLI_GRANT_OR_CLAIM_NOT_ELIGIBLE');
  $row=array(
    'contract'=>self::WPCLI_CONTRACT,'job_id'=>$id,'target_id'=>$target,
    'company'=>$authority['company'],'site_uuid'=>$authority['site_uuid'],
    'origin'=>$authority['origin'],'environment'=>$authority['environment'],
    'grant_id'=>$authority['grant_id'],'transport_id'=>$authority['transport_id'],
    'operation'=>$authority['operation'],'command_sha256'=>$digest,
    'output_sha256'=>$output,'readback_sha256'=>$readback,
    'exit_code'=>(int)$exit,'received_epoch'=>time(),
    'host_signed_attestation_received'=>true,'native_executor_certified'=>false,
    'independent_host_readback_verified'=>false,
    'state'=>'PENDING_INDEPENDENT_NATIVE_READBACK'
  );
  if (!add_option(self::WPCLI_PREFIX.$id,$row,'',false))
    return self::deny('WPCLI_RECEIPT_ALREADY_EXISTS');
  return array('contract'=>self::WPCLI_CONTRACT,'verified'=>false,
    'state'=>'PENDING_INDEPENDENT_NATIVE_READBACK',
    'job_id'=>$id,'target_id'=>$target,
    'native_executor_certified'=>false,'production_mutation'=>false);
 }


 // Environment comes from the exact enrolled original 312 scope, never a fixed environment name.
 // Signed Native completion is independent of the earlier pending 313
 // attestation. It cannot create a Job, Grant, Claim, or Host privilege.
 public static function native_finish(array $a):array {
   if (!class_exists('MAD4B_Broker_Exact_SSH_Authority_V1',false))
     return self::deny('NATIVE_AUTHORITY_MISSING');
   if (array_keys($a)!==array_merge(self::WPCLI_FINISH_FIELDS,['signature']))
     return self::deny('NATIVE_FINISH_SHAPE_INVALID');
   foreach (self::WPCLI_FINISH_FIELDS as $k)
     if (!is_string($a[$k]) && !is_int($a[$k]))
       return self::deny('NATIVE_FINISH_TYPES_INVALID');
   $job=(string)$a['job_id'];$target=(string)$a['target_id'];
   $now=time();
   if ($a['contract']!==self::WPCLI_FINISH || $a['host_id']!==self::HOST
    || !preg_match('/^[a-f0-9]{32}$/D',$job)
    || !preg_match('/^[a-f0-9]{32}$/D',(string)$a['grant_id'])
    || !preg_match('/^[a-f0-9]{32}$/D',(string)$a['transport_id'])
    || !preg_match('/^[a-f0-9]{32}$/D',(string)$a['nonce'])
    || !is_string($a['environment'])
    || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$a['environment'])
    || !is_int($a['exit_code']) || $a['exit_code']!==0
    || !is_int($a['issued']) || !is_int($a['expires'])
    || $a['issued']<$now-120 || $a['issued']>$now+10
    || $a['expires']<$now || $a['expires']>$a['issued']+120)
      return self::deny('NATIVE_FINISH_SCOPE_INVALID');
   foreach (['command_sha256','native_receipt_sha256',
             'expected_readback_sha256','readback_sha256','output_sha256'] as $k)
     if (!preg_match('/^[a-f0-9]{64}$/D',(string)$a[$k]))
       return self::deny('NATIVE_FINISH_HASH_INVALID');
   if (!hash_equals($a['expected_readback_sha256'],$a['readback_sha256']))
      return self::deny('NATIVE_INDEPENDENT_READBACK_MISMATCH');
   $signed=implode(chr(10),array_map(static function($k)use($a){return (string)$a[$k];},
                    self::WPCLI_FINISH_FIELDS));
   $sig=base64_decode((string)$a['signature'],true);
   $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
   if (!is_string($sig) || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
     || !is_string($pub) || strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
     || !sodium_crypto_sign_verify_detached($sig,$signed,$pub))
       return self::deny('NATIVE_FINISH_SIGNATURE_INVALID');
   $auth=MAD4B_Broker_Exact_SSH_Authority_V1::evaluate($job,$target,(string)$a['command_sha256']);
   if (($auth['eligible']??false)!==true || ($auth['verified']??false)!==true
     || ($auth['owner_enrolled_wpcli']??false)!==true
     || ($auth['operation']??'')!=='company.remote.wpcli.full')
       return self::deny('NATIVE_ORIGINAL_SCOPE_REVOKED');
   foreach (['grant_id','transport_id','site_uuid','origin','environment'] as $key)
     if (($auth[$key]??null)!==$a[$key])
       return self::deny('NATIVE_SCOPE_CHANGED_'.$key);
   $original=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$job,false);
   if (!is_array($original) || ($original['executed']??null)!==false
     || ($original['state']??'')!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
     || !((($original['job_type']??null)==='signed_vps_wpcli' && $target===self::HOST)
       || (($original['job_type']??null)==='signed_remote_hostinger_wpcli'
           && $target!==self::HOST
           && class_exists('MAD4B_Broker_Exact_SSH_Authority_V1',false)
           && in_array($target,MAD4B_Broker_Exact_SSH_Authority_V1::REMOTE_WPCLI_TARGETS,true)
           && ($original['target_host_id']??null)===$target
           && ($auth['job_type']??null)==='signed_remote_hostinger_wpcli'))
     || ($original['target_scope']??'')!=='wordpress')
       return self::deny('NATIVE_ORIGINAL_JOB_NOT_PENDING');
   $pending=get_option(self::WPCLI_PREFIX.$job,false);
   if (!is_array($pending) || ($pending['state']??'')!=='PENDING_INDEPENDENT_NATIVE_READBACK'
     || ($pending['command_sha256']??'')!==$a['command_sha256']
     || ($pending['readback_sha256']??'')!==$a['readback_sha256']
     || ($pending['output_sha256']??'')!==$a['output_sha256']
     || ($pending['exit_code']??null)!==0
     || ($pending['target_id']??'')!==$target
     || ($pending['grant_id']??'')!==$a['grant_id'])
       return self::deny('NATIVE_PENDING_313_EVIDENCE_MISMATCH');
   // A signed payload cannot attest its own durable native receipt.
   // Original Job, scope and 313 pending evidence are checked above.
   // This provider is independently enrolled; it cannot be created here.
   if (!class_exists('MAD4B_Native_WPCLI_Receipt_Verifier_V1',false)
     || !is_callable(['MAD4B_Native_WPCLI_Receipt_Verifier_V1','verify_exact']))
       return self::deny('NATIVE_INDEPENDENT_VERIFIER_REQUIRED');
   $proof=MAD4B_Native_WPCLI_Receipt_Verifier_V1::verify_exact(
     $a,$auth,$original,$pending);
   if (!is_array($proof) || ($proof['verified']??false)!==true
     || ($proof['job_id']??null)!==$job
     || ($proof['native_receipt_sha256']??null)!==$a['native_receipt_sha256']
     || ($proof['command_sha256']??null)!==$a['command_sha256']
     || ($proof['readback_sha256']??null)!==$a['readback_sha256'])
       return self::deny('NATIVE_INDEPENDENT_RECEIPT_NOT_VERIFIED');
   $production_mutation=($proof['production_mutation']??false)===true;
   $digest=hash('sha256',$signed);
   $key=self::WPCLI_FINISH_PREFIX.$job;
   $existing=get_option($key,false);
   if ($existing!==false && (!is_array($existing)
     || ($existing['signed_sha256']??'')!==$digest
     || ($existing['nonce']??'')!==$a['nonce']))
       return self::deny('NATIVE_FINISH_REPLAY_DIFFERENT_EVIDENCE');
   if ($existing===false && !add_option($key,[
     'signed_sha256'=>$digest,'nonce'=>$a['nonce'],
     'native_receipt_sha256'=>$a['native_receipt_sha256'],
     'recorded_at'=>gmdate('c')], '', false))
       return self::deny('NATIVE_FINISH_NONCE_CONFLICT');
   $pending['state']='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED';
   $pending['native_executor_certified']=true;
   $pending['independent_host_readback_verified']=true;
   $pending['native_receipt_sha256']=$a['native_receipt_sha256'];
   $pending['production_mutation']=$production_mutation;
   if (!update_option(self::WPCLI_PREFIX.$job,$pending,false))
     return self::deny('NATIVE_313_UPDATE_UNCERTAIN');
   $original['state']='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED';
   $original['executed']=true;
   $original['independent_host_permission_verified']=true;
   $original['independent_readback_verified']=true;
   $original['receipt_contract']=self::WPCLI_FINISH;
   $original['production_mutation']=$production_mutation;
   if (!update_option(MAD4B_Central_VPS_Broker_V1::JOB.$job,$original,false))
     return self::deny('NATIVE_ORIGINAL_JOB_UPDATE_UNCERTAIN');
   $read=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$job,false);
   if (!is_array($read) || ($read['state']??'')!=='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
     || ($read['executed']??false)!==true)
     return self::deny('NATIVE_ORIGINAL_JOB_READBACK_UNCERTAIN');
   return ['contract'=>self::WPCLI_FINISH,'verified'=>true,
      'state'=>$read['state'],'job_id'=>$job,
      'independent_host_readback_verified'=>true,
      'native_receipt_sha256'=>$a['native_receipt_sha256'],
      'production_mutation'=>$production_mutation];
 }

 /**
  * PERMANENT 313 READBACK CONTRACT: generic across company, environment and
  * target, using the original stored broker Job as the only identity.
  *
  * An expired Job or expired signed independent proof is RECOVERY_REQUIRED,
  * NEVER VERIFIED. This projection cannot revive authority or rerun the
  * native executor. Successful 313 needs the original signed native_finish.
  */
 public static function lifecycle_readback(string $job_id):array {
  $base=[
   'contract'=>'mad4b.original-313-dynamic-readback.v1',
   'job_id'=>$job_id,'state'=>'UNKNOWN','signed_313_certified'=>false,
   'execution_replayed'=>false,'grant_created'=>false,'mutation_performed'=>false
  ];
  if(!preg_match('/^[a-f0-9]{32}$/D',$job_id) ||
     !class_exists('MAD4B_Central_VPS_Broker_V1',false))
    return $base+['reason'=>'JOB_ID_OR_OWNER_INVALID'];
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$job_id,false);
  if(!is_array($job) || ($job['job_id']??null)!==$job_id)
    return $base+['reason'=>'ORIGINAL_JOB_MISSING'];
  $p=get_option(self::WPCLI_PREFIX.$job_id,false);
  $finished=get_option(self::WPCLI_FINISH_PREFIX.$job_id,false);
  $proof=class_exists('MAD4B_Native_WPCLI_Receipt_Verifier_V1',false)
     ?get_option(MAD4B_Native_WPCLI_Receipt_Verifier_V1::PREFIX.$job_id,false):false;
  $expiry=(int)($job['expires_epoch']??0);
  $proof_expiry=is_array($proof)?(int)($proof['expires']??0):0;
  $finished_valid=is_array($p)&&is_array($finished)
     &&($job['state']??'')==='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
     &&($job['executed']??false)===true
     &&($p['state']??'')==='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
     &&($p['native_executor_certified']??false)===true
     &&($p['independent_host_readback_verified']??false)===true
     &&is_string($p['native_receipt_sha256']??null)
     &&hash_equals($p['native_receipt_sha256'],(string)($finished['native_receipt_sha256']??''));
  $expired=$expiry>0&&$expiry<=time();
  $pending=is_array($p)&&($p['state']??'')==='PENDING_INDEPENDENT_NATIVE_READBACK';
  $state=$finished_valid?'WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
     :($expired&&$pending?'EXPIRED_RECEIPT_ONLY_RECOVERY_REQUIRED'
     :(string)($job['state']??'UNKNOWN'));
  return array_merge( $base, [
    'company'=>(string)($job['company']??''),
    'environment'=>(string)($job['environment']??''),
    'target_host_id'=>(string)($job['target_host_id']??''),
    'state'=>$state,
    'original_job_expired'=>$expired,
    'original_job_expiry_epoch'=>$expiry,
    'pending_original_313'=>$pending,
    'independent_signed_proof_present'=>is_array($proof),
    'independent_signed_proof_expired'=>$proof_expiry>0&&$proof_expiry<=time(),
    'native_receipt_proven_by_finish'=>$finished_valid,
    'receipt_only_recovery_required'=>$state==='EXPIRED_RECEIPT_ONLY_RECOVERY_REQUIRED',
    'signed_313_certified'=>$finished_valid,
    'reissue_original_job_required'=>false,
    'execution_replay_permitted'=>false
  ] );
 }

 public static function cli($args,$assoc):void {
  if (!defined('WP_CLI')||!WP_CLI||!class_exists('WP_CLI')
   || !class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   || !MAD4B_Central_Dual_Lane_Registry_V1::central()
   || get_current_user_id()<1||!current_user_can('manage_options'))WP_CLI::error('CENTRAL_HOST_REQUIRED');
  if (($args[0]??'')==='lifecycle') {
   if(array_keys($assoc)!==['job'])
    WP_CLI::error('EXACT_EXISTING_JOB_ID_REQUIRED');
   WP_CLI::line(wp_json_encode(self::lifecycle_readback((string)$assoc['job']),JSON_UNESCAPED_SLASHES));
   return;
  }
  if (($args[0]??'')==='status') {
   WP_CLI::line(wp_json_encode(['contract'=>self::CONTRACT,'registered'=>true,
     'only_fixed_nonmutating_diagnostic'=>true,'production_mutation'=>false]));
   return;
  }
  if (($args[0]??'')==='wpcli-finish') {
   if (array_keys($assoc)!==['payload']) WP_CLI::error('NATIVE_SIGNED_PAYLOAD_REQUIRED');
   $raw=base64_decode((string)$assoc['payload'],true);
   if (!is_string($raw)||strlen($raw)>8000) WP_CLI::error('NATIVE_PAYLOAD_INVALID');
   $a=json_decode($raw,true);
   if (!is_array($a)) WP_CLI::error('NATIVE_JSON_INVALID');
   $result=self::native_finish($a);
   if (($result['verified']??false)!==true)
     WP_CLI::error((string)($result['reason']??'NATIVE_FINISH_DENIED'));
   WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
   return;
  }
  if (($args[0]??'')==='wpcli-receipt') {
   $result=self::wpcli_receipt($assoc);
   if (($result['state']??'')!=='PENDING_INDEPENDENT_NATIVE_READBACK')
      WP_CLI::error((string)($result['reason']??'WPCLI_RECEIPT_DENIED'));
   WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
   return;
  }
  if (($args[0]??'')!=='receipt')WP_CLI::error('STATUS_OR_RECEIPT');
  $row=self::record($assoc);
  if (($row['verified']??false)!==true)WP_CLI::error((string)($row['reason']??'INVALID_RECEIPT'));
  WP_CLI::line(wp_json_encode($row,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if (defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b broker-remote-ssh',[__CLASS__,'cli']);
 }
}
MAD4B_Broker_Remote_SSH_Receipt_V1::boot();
