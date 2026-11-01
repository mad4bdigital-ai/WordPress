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
