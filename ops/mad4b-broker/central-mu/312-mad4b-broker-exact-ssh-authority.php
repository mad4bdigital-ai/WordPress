<?php
/**
 * Plugin Name: MAD4B Broker Exact SSH Authority Preflight
 * Description: Broker-signed per-job grant verification for enrolled Registry SSH transports. Does not issue grants or execute commands.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Broker_Exact_SSH_Authority_V1 {
 const CONTRACT='mad4b.broker-exact-ssh-authority.v1';
 const NONCE='mad4b_broker_exact_ssh_preflight_nonce_v1_';
 const HOST='srv2030908';
 // Local target remains a central scope, not a root/host authorization.
 const TARGETS=['hosting-1','hosting-3','srv2030908'];
 private static function transport_credential_valid(array $transport,string $target):bool {
  $credential=(string)($transport['credential_ref']??'');
  if ($target===self::HOST) return $credential==='vault:registry/local-srv2030908'
    && ($transport['host_principal']??'')==='root';
  return preg_match('/^vault:registry\\/(hosting-1|hosting-3)$/D',$credential)===1;
 }
 private static function deny(string $code):array {return ['contract'=>self::CONTRACT,'verified'=>false,'eligible'=>false,'reason'=>$code,'shell_executed'=>false];}
 public static function evaluate(string $job_id,string $target,string $digest):array {
  if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
    || !MAD4B_Central_Dual_Lane_Registry_V1::central())return self::deny('NOT_CENTRAL');
  if (!preg_match('/^[a-f0-9]{32}$/D',$job_id) || !in_array($target,self::TARGETS,true)
    || !preg_match('/^[a-f0-9]{64}$/D',$digest))return self::deny('INPUT_INVALID');
  if (!class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
    || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
    || !class_exists('MAD4B_Manager_Auto_Write_V1',false)
    || !class_exists('MAD4B_Company_Operation_Admission_V1',false)
    || !class_exists('MAD4B_Company_Portfolio_Manager_V1',false))return self::deny('GOVERNANCE_MISSING');
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$job_id,false);
  $claim=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$job_id,false);
  $req=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$job_id,false);
  if (!is_array($job)||!is_array($claim)||!is_array($req))return self::deny('JOB_OR_CLAIM_MISSING');
  if (($job['job_id']??null)!==$job_id || ($job['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
   || ($job['executed']??null)!==false || ($claim['job_id']??null)!==$job_id
   || ($claim['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
   || ($claim['host_id']??null)!==self::HOST || (int)($claim['claimed_epoch']??0)<1
   || ($job['broker_host_id']??null)!==self::HOST
   || ($job['target_host_id']??null)!==$target
   || ($job['command_sha256']??null)!==$digest
   || ($req['command_sha256']??null)!==$digest
   || ($req['request_id']??null)!==$job_id
   || ($req['target_scope']??null)!=='host'
   || ($req['status']??null)!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
   || ($req['grant_id']??null)!==($job['grant_id']??null)
   || (int)($job['expires_epoch']??0)<=time()
   || (int)($req['expires_epoch']??0)<=time())return self::deny('EXACT_CLAIM_NOT_ELIGIBLE');
  $grant=get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.(string)$job['grant_id'],false);
  if (!is_array($grant)||($grant['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
   || !array_key_exists('expires_epoch',$grant)
   || ($grant['expires_epoch']!==null
       && (!is_int($grant['expires_epoch']) || $grant['expires_epoch']<=time()))
   || !in_array($grant['grant_mode']??null,['one_time','persistent'],true)
   || ($job['grant_mode']??null)!==($grant['grant_mode']??null)
   || ($req['grant_mode']??null)!==($grant['grant_mode']??null)
   || (($grant['grant_mode']??null)==='one_time' && !is_int($grant['expires_epoch']??null))
   || ($grant['operation']??null)!=='maintenance.shell'
   || ($job['operation']??null)!=='maintenance.shell'
   || ($grant['company']??null)!==($job['company']??null)
   || ($grant['site_uuid']??null)!==($job['site_uuid']??null)
   || ($grant['environment']??null)!==($job['environment']??null)
   || ($grant['canonical_origin']??null)!==($job['canonical_origin']??null)
   || (int)($grant['central_principal_id']??0)!==(int)($job['central_principal_id']??-1))
     return self::deny('ACTIVE_EXACT_GRANT_REQUIRED');
  $transport_id=(string)($grant['transport_id']??'');
  $transport=get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$transport_id,false);
  if (!is_array($transport)||($transport['state']??null)!=='PENDING_INDEPENDENT_SSH_VERIFICATION'
   || ($transport['host_id']??null)!==$target
   || ($transport['transport_id']??null)!==$transport_id
   || ($transport['company']??null)!==($job['company']??null)
   || ($transport['site_uuid']??null)!==($job['site_uuid']??null)
   || ($transport['canonical_origin']??null)!==($job['canonical_origin']??null)
   || ($transport['environment']??null)!==($job['environment']??null)
   || (int)($transport['central_principal_id']??0)!==(int)($job['central_principal_id']??-1)
   || (int)($transport['assignment_revision']??-1)!==(int)($grant['assignment_revision']??-2)
   || ($job['transport_id']??null)!==$transport_id
   || ($req['transport_id']??null)!==$transport_id
   || ($grant['target_host_id']??null)!==$target
   || ($grant['target_ssh_principal']??null)!==($transport['host_principal']??null)
   || ($job['target_ssh_principal']??null)!==($transport['host_principal']??null)
   || !self::transport_credential_valid($transport,$target))
     return self::deny('TRANSPORT_OR_HOST_BINDING_INVALID');
  // Match the original 305 one-time consumption key to this exact job.
  // A grant alone can never authorize a second command/job.
  $mode=(string)$grant['grant_mode'];
  $claim_key=MAD4B_Central_SSH_Command_Intake_V1::CLAIM_PREFIX.(string)$job['grant_id'];
  if ($mode==='persistent') $claim_key.='_'.hash('sha256',(string)$job['correlation_id']);
  if (get_option($claim_key,false)!==$job_id) return self::deny('ORIGINAL_GRANT_CLAIM_MISSING_OR_CHANGED');
  $target_scope=MAD4B_Manager_Auto_Write_V1::company_target((string)$job['company']);
  if (($target_scope['state']??null)!=='REGISTERED'
   || ($target_scope['site_uuid']??null)!==($job['site_uuid']??null)
   || ($target_scope['canonical_origin']??null)!==($job['canonical_origin']??null)
   || ($target_scope['environment']??null)!==($job['environment']??null))
     return self::deny('COMPANY_BINDING_CHANGED');
  $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
  if (!$manager || !MAD4B_Company_Portfolio_Manager_V1::has_delegation(
      $manager,(string)$job['company'],'operations_manage',(int)$job['central_principal_id']))
     return self::deny('COMPANY_DELEGATION_REVOKED');
  return ['contract'=>self::CONTRACT,'verified'=>true,'eligible'=>true,
   'reason'=>'CENTRAL_SCOPE_VERIFIED_HOST_PROOF_REQUIRED',
   'job_id'=>$job_id,'target_id'=>$target,
   'principal_id'=>(string)$job['central_principal_id'],
   'correlation_id'=>(string)$job['correlation_id'],
   'host_principal'=>$transport['host_principal'],
   'host_key_fingerprint'=>$transport['host_key_fingerprint'],
   'credential_ref'=>$transport['credential_ref'],
   'company'=>$job['company'],'site_uuid'=>$job['site_uuid'],
   'origin'=>$job['canonical_origin'],'environment'=>$job['environment'],
   'operation'=>$job['operation'],'command_sha256'=>$digest,
   'grant_id'=>$job['grant_id'],'transport_id'=>$transport_id,
   'expires_epoch'=>(int)$job['expires_epoch'],
   'shell_executed'=>false,'central_scope_only'=>true];
 }
 private static function verify_signature(array $a):bool {
  $nonce=(string)($a['nonce']??'');
  $issued=(int)($a['issued']??0);$expires=(int)($a['expires']??0);
  $sig=base64_decode((string)($a['signature']??''),true);
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  if (!preg_match('/^[a-f0-9]{32}$/D',$nonce) || $issued<time()-60 || $issued>time()+10
   || $expires<$issued || $expires>$issued+60 || $expires<time()
   || !is_string($sig) || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
   || !is_string($pub) || strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)return false;
  $msg=implode("\n",[self::CONTRACT,'verify',self::HOST,(string)($a['job']??''),
    (string)($a['target']??''),(string)($a['command_sha256']??''),
    $nonce,(string)$issued,(string)$expires]);
  return sodium_crypto_sign_verify_detached($sig,$msg,$pub);
 }
 public static function cli($args,$assoc):void {
  // A signed, replay-protected host preflight is an independent service
  // identity, never a synthetic WordPress user. It only reads an existing
  // exact job/grant and cannot create or mutate a grant/target.
  if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')
   || !class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   || !MAD4B_Central_Dual_Lane_Registry_V1::central())WP_CLI::error('CENTRAL_HOST_AUTHORITY_REQUIRED');
  $action=(string)($args[0]??'');
  if ($action!=='verify' && (get_current_user_id()<1 || !current_user_can('manage_options')))
    WP_CLI::error('CENTRAL_HOST_AUTHORITY_REQUIRED');
  if ($action==='claimed') {
   global $wpdb;
   $prefix=MAD4B_Central_VPS_Broker_V1::JOB;
   $keys=$wpdb->get_col($wpdb->prepare(
       'SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 200',
       $wpdb->esc_like($prefix).'%'));
   $items=array();
   foreach((array)$keys as $key) {
    $job=get_option($key,false);
    if (!is_array($job) || ($job['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
      || ($job['broker_host_id']??null)!==self::HOST
      || !in_array($job['target_host_id']??null,self::TARGETS,true)
      || ($job['executed']??true)!==false
      || !in_array($job['grant_mode']??null,['one_time','persistent'],true)
      || !preg_match('/^[a-f0-9]{32}$/D',(string)($job['transport_id']??''))
      || (int)($job['expires_epoch']??0)<=time())continue;
    $items[]=['job_id'=>$job['job_id'],'target_id'=>$job['target_host_id'],
      'command_sha256'=>$job['command_sha256'],'expires_epoch'=>(int)$job['expires_epoch']];
    if (count($items)>=max(1,min(10,(int)($assoc['limit']??10))))break;
   }
   WP_CLI::line(wp_json_encode(['contract'=>self::CONTRACT,'claimed'=>$items],JSON_UNESCAPED_SLASHES));
   return;
  }
  if ($action==='status') {
   WP_CLI::line(wp_json_encode(['contract'=>self::CONTRACT,'verifier_registered'=>true,
     'central_scope_source'=>'original_wordpress_grants_claims_and_delegations',
     'remote_execution_authority_automatically_granted'=>false,
     'production_supported'=>true,'shell_executed'=>false]));
   return;
  }
  if ($action!=='verify')WP_CLI::error('status_claimed_or_verify_required');
  if (!self::verify_signature($assoc))WP_CLI::error('BROKER_SIGNED_PREFLIGHT_REQUIRED');
  if (!add_option(self::NONCE.(string)$assoc['nonce'],time(),'',false))
    WP_CLI::error('BROKER_PREFLIGHT_NONCE_REPLAY');
  WP_CLI::line(wp_json_encode(self::evaluate((string)($assoc['job']??''),
    (string)($assoc['target']??''),(string)($assoc['command_sha256']??'')),JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b broker-authority',[__CLASS__,'cli']);
 }
}
MAD4B_Broker_Exact_SSH_Authority_V1::boot();
