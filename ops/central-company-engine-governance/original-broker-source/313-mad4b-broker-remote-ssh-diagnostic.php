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

 public static function cli($args,$assoc):void {
  if (!defined('WP_CLI')||!WP_CLI||!class_exists('WP_CLI')
   || !class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   || !MAD4B_Central_Dual_Lane_Registry_V1::central()
   || get_current_user_id()<1||!current_user_can('manage_options'))WP_CLI::error('CENTRAL_HOST_REQUIRED');
  if (($args[0]??'')==='status') {
   WP_CLI::line(wp_json_encode(['contract'=>self::CONTRACT,'registered'=>true,
     'only_fixed_nonmutating_diagnostic'=>true,'production_mutation'=>false]));
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
