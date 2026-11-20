<?php
/**
 * Plugin Name: MAD4B VPS Broker Fixed Diagnostic Execution
 * Description: Host-signed, exact-hash, one-time, read-only canary execution receipts; no arbitrary queued shell execution.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_VPS_Broker_Diagnostic_Execute_V1 {
 const CONTRACT='mad4b.vps-broker.diagnostic-execute.v1';
 const RECEIPT='mad4b_vps_broker_diagnostic_receipt_v1_';
 const COMMAND='printf MAD4B_BROKER_DIAGNOSTIC';
 const OUTPUT='MAD4B_BROKER_DIAGNOSTIC';
 const COMPANY='wayfatours';
 const SITE='b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b';
 const HOST='srv2030908';
 private static function reject($code) {return new WP_Error('mad4b_diag_exec_'.$code,'Broker diagnostic: '.$code);}
 public static function inspect(string $id) {
  if(!preg_match('/^[a-f0-9]{32}$/D',$id)
     || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
     || !class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
     || !class_exists('MAD4B_Company_Operation_Admission_V1',false)
     || !class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
     || !MAD4B_Central_Dual_Lane_Registry_V1::central()) return self::reject('scope_invalid');
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
  $claim=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false);
  if(!is_array($job)||!is_array($claim)
    || ($job['job_id']??'')!==$id || ($job['company']??'')!==self::COMPANY
    || ($job['site_uuid']??'')!==self::SITE
    || ($job['canonical_origin']??'')!=='https://wayfatours.mad4b.com'
    || ($job['environment']??'')!=='production'
    || ($job['target_host_id']??'')!==self::HOST
    || ($job['target_ssh_principal']??'')!=='root'
    || ($job['operation']??'')!=='maintenance.shell'
    || ($job['state']??'')!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
    || ($job['executed']??true)!==false
    || ($job['command_sha256']??'')!==hash('sha256',self::COMMAND)
    || (int)($job['expires_epoch']??0)<=time()
    || ($claim['job_id']??'')!==$id
    || ($claim['host_id']??'')!==self::HOST
    || ($claim['state']??'')!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
    || (int)($claim['claimed_epoch']??0)<=0) return self::reject('job_claim_or_expiry');
  if(get_option(self::RECEIPT.$id,false)!==false)return self::reject('already_receipted');
  $grant=get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.
    (string)($job['grant_id']??''),false);
  if(!is_array($grant)||($grant['state']??'')!=='CENTRAL_OPERATION_SCOPE_GRANTED'
    || ($grant['company']??'')!==self::COMPANY
    || ($grant['site_uuid']??'')!==self::SITE
    || ($grant['operation']??'')!=='maintenance.shell'
    || (int)($grant['central_principal_id']??0)!==(int)($job['central_principal_id']??-1)
    || (isset($grant['expires_epoch'])&&(int)$grant['expires_epoch']<=time()))
    return self::reject('grant_expired_or_revoked');
  if (($grant['grant_mode']??'one_time')==='persistent') {
    $transport=get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.
      (string)($grant['transport_id']??''),false);
    if(!is_array($transport) || ($transport['state']??'')!=='PENDING_INDEPENDENT_SSH_VERIFICATION'
      || ($transport['company']??'')!==self::COMPANY
      || ($transport['site_uuid']??'')!==self::SITE)
      return self::reject('persistent_transport_revoked');
  }
  $uid=(int)($job['central_principal_id']??0);
  $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
  if($uid<1||$manager===''||!MAD4B_Company_Portfolio_Manager_V1::has_delegation(
       $manager,self::COMPANY,'operations_manage',$uid))
    return self::reject('delegation_inactive');
  return $job;
 }
 public static function claimed(int $limit=10):array {
  global $wpdb;
  $names=$wpdb->get_col($wpdb->prepare(
    'SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 200',
    $wpdb->esc_like(MAD4B_Central_VPS_Broker_V1::JOB).'%'));
  $items=[];
  foreach((array)$names as $name) {
   $id=substr((string)$name,strlen(MAD4B_Central_VPS_Broker_V1::JOB));
   $job=self::inspect($id);
   if(is_wp_error($job))continue;
   $items[]=['job_id'=>$id,'site_uuid'=>self::SITE,'environment'=>'production',
     'host_id'=>self::HOST,'command_sha256'=>$job['command_sha256'],
     'expires_epoch'=>$job['expires_epoch']];
   if(count($items)>=max(1,min(20,$limit)))break;
  }
  return $items;
 }
 private static function receipt(array $args) {
  if(!function_exists('sodium_crypto_sign_verify_detached'))return self::reject('signature_support_missing');
  $id=(string)($args['job']??'');
  $job=self::inspect($id);
  if(is_wp_error($job))return $job;
  $nonce=(string)($args['nonce']??'');
  $issued=(int)($args['issued']??0);$expires=(int)($args['expires']??0);
  $output=(string)($args['output_sha256']??'');
  $readback=(string)($args['readback_sha256']??'');
  if(!preg_match('/^[a-f0-9]{32}$/D',$nonce)
    || $issued<time()-50||$issued>time()+10
    || $expires<$issued||$expires>$issued+50||$expires<time()
    || $output!==hash('sha256',self::OUTPUT)
    || $readback!==hash('sha256',self::OUTPUT))
    return self::reject('receipt_fields_invalid');
  $key=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $signature=base64_decode((string)($args['signature']??''),true);
  if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    || !is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES)
    return self::reject('receipt_key_invalid');
  $message=implode("\n",[self::CONTRACT,'receipt',$id,self::SITE,'production',
    self::HOST,$nonce,(string)$issued,(string)$expires,$output,$readback]);
  if(!sodium_crypto_sign_verify_detached($signature,$message,$key))
    return self::reject('signature_invalid');
  $row=['contract'=>self::CONTRACT,'job_id'=>$id,'company'=>self::COMPANY,
   'site_uuid'=>self::SITE,'host_id'=>self::HOST,'command_sha256'=>$job['command_sha256'],
   'output_sha256'=>$output,'readback_sha256'=>$readback,'nonce'=>$nonce,
   'signed_at_epoch'=>$issued,'recorded_at_epoch'=>time(),
   'state'=>'DIAGNOSTIC_EXECUTION_READBACK_VERIFIED',
   'signed_host_execution'=>true,'independent_readback_verified'=>true,
   'company_production_mutation'=>false];
  // The original job stays CLAIMED until its immutable signed receipt exists.
  if(!add_option(self::RECEIPT.$id,$row,'',false))return self::reject('receipt_already_recorded');
  $job['state']='DIAGNOSTIC_EXECUTION_READBACK_VERIFIED';
  $job['executed']=true;
  $job['independent_host_permission_verified']=true;
  $job['receipt_contract']=self::CONTRACT;
  if(!update_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,$job,false))
     return self::reject('job_readback_update_failed');
  return $row;
 }
 public static function cli($args,$assoc):void {
  if(!defined('WP_CLI')||!WP_CLI||!class_exists('WP_CLI')
    ||get_current_user_id()<1||!current_user_can('manage_options'))
    WP_CLI::error('local_authorized_broker_required');
  $action=(string)($args[0]??'');
  if($action==='claimed') {
   WP_CLI::line(wp_json_encode(['contract'=>self::CONTRACT,
    'eligible'=>self::claimed((int)($assoc['limit']??10))],JSON_UNESCAPED_SLASHES));
   return;
  }
  if($action==='receipt') {
   $row=self::receipt($assoc);
   if(is_wp_error($row))WP_CLI::error($row->get_error_code());
   WP_CLI::line(wp_json_encode($row,JSON_UNESCAPED_SLASHES));
   return;
  }
  WP_CLI::error('Supported: claimed, receipt');
 }
 public static function boot():void {
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b broker-diagnostic',[__CLASS__,'cli']);
 }
}
MAD4B_VPS_Broker_Diagnostic_Execute_V1::boot();
