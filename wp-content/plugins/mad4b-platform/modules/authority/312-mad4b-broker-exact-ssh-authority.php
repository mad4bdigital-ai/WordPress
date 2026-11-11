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
 // Distinct locally enrolled host, without delegated root privilege.
 const TARGETS=['hosting-1','hosting-2','hosting-3','srv2030908'];
 // Isolate Remote WP-CLI from existing host shell and same-host WP-CLI.
 const REMOTE_WPCLI_TARGETS=['hosting-2','hosting-3'];
 private static function transport_credential_valid(array $transport,string $target):bool {
  $credential=(string)($transport['credential_ref']??'');
  if ($target===self::HOST) return $credential==='vault:registry/local-srv2030908'
    && ($transport['host_principal']??'')==='root';
  return preg_match('/^vault:registry\\/(hosting-1|hosting-2|hosting-3)$/D',$credential)===1;
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
  if(is_array($job)&&($job['job_type']??null)==='signed_remote_hostinger_wpcli')
    return self::evaluate_remote_wordpress($job_id,$target,$digest,$job);
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
   || !in_array(($req['target_scope']??null),['host','wordpress'],true)
   || ($req['status']??null)!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
   || ($req['grant_id']??null)!==($job['grant_id']??null)
   || (int)($job['expires_epoch']??0)<=time()
   || (int)($req['expires_epoch']??0)<=time())return self::deny('EXACT_CLAIM_NOT_ELIGIBLE');
  $grant=get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.(string)$job['grant_id'],false);
  if (!is_array($grant)||($grant['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
   || !array_key_exists('expires_epoch',$grant)
   || ($grant['expires_epoch']!==null
       && (!is_int($grant['expires_epoch']) || $grant['expires_epoch']<=time()))
   || ($grant['grant_mode']??null)!=='persistent'
   || ($job['grant_mode']??null)!=='persistent'
   || !class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)
   || !MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted(
       (string)($grant['operation']??''),(string)($req['target_scope']??''),
       (string)($job['command_format']??''))
   || ($job['operation']??null)!==($grant['operation']??null)
   || ($req['operation']??null)!==($grant['operation']??null)
   || (($req['target_scope']??null)==='wordpress'
       && (($job['command_format']??null)!=='wpcli.argv.json.v1'
           || ($req['command_format']??null)!=='wpcli.argv.json.v1'))
   || ($grant['company']??null)!==($job['company']??null)
   || ($grant['site_uuid']??null)!==($job['site_uuid']??null)
   || ($grant['environment']??null)!==($job['environment']??null)
   || ($grant['canonical_origin']??null)!==($job['canonical_origin']??null)
   || (int)($grant['central_principal_id']??0)!==(int)($job['central_principal_id']??-1))
     return self::deny('ACTIVE_PERSISTENT_GRANT_REQUIRED');
  $transport_id=(string)($grant['transport_id']??'');
  $transport=get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$transport_id,false);
  if (!is_array($transport)||!class_exists('MAD4B_Original_Verified_WPCLI_Transport_V1',false)
   || !MAD4B_Original_Verified_WPCLI_Transport_V1::permitted($transport,$grant)
   || ($transport['host_id']??null)!==$target
   || ($transport['transport_id']??null)!==$transport_id
   || ($transport['company']??null)!==($job['company']??null)
   || ($transport['site_uuid']??null)!==($job['site_uuid']??null)
   || ($transport['canonical_origin']??null)!==($job['canonical_origin']??null)
   || ($transport['environment']??null)!==($job['environment']??null)
   || (int)($transport['central_principal_id']??0)!==(int)($job['central_principal_id']??-1)
   || (int)($transport['assignment_revision']??-1)!==(int)($grant['assignment_revision']??-2)
   || ($job['transport_id']??null)!==$transport_id
   || ($grant['target_host_id']??null)!==$target
   || ($grant['target_ssh_principal']??null)!==($transport['host_principal']??null)
   || ($job['target_ssh_principal']??null)!==($transport['host_principal']??null)
   || !self::transport_credential_valid($transport,$target))
     return self::deny('TRANSPORT_OR_HOST_BINDING_INVALID');
  // Same physical VPS may host an enrolled customer WordPress site.
  // Treat host identity and COMPANY target identity as different scopes:
  // the exact 305 owner-enrolled wpcli.full transport, original 306 Job,
  // registered company target and signed host proof remain mandatory.
  if ($target===self::HOST && ($req['target_scope']??'')==='wordpress') {
    $same_host_company_wpcli=
      ($job['company']??'')!=='tours-central'
      && preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',(string)($job['environment']??''))
      && MAD4B_Original_Native_Operation_Providers_V1::original_305_operation_registered(
          (string)($grant['operation']??''))
      && ($req['target_scope']??'')==='wordpress'
      && ($job['target_scope']??'')==='wordpress'
      && ($job['job_type']??'')==='signed_vps_wpcli'
      && ($job['command_format']??'')==='wpcli.argv.json.v1'
      && ($req['command_format']??'')==='wpcli.argv.json.v1'
      && ($transport['full_wpcli_grant_id']??null)===($job['grant_id']??null)
      && (($transport['full_wpcli_pending_native_enrollment']??null)===true || MAD4B_Original_Verified_WPCLI_Transport_V1::active($transport,$grant))
      && ($transport['capabilities']??null)===['wpcli.full'];
    if (!$same_host_company_wpcli)
      return self::deny('LOCAL_TARGET_SHELL_ONLY');
  }
  // The signed Job type must agree with its original 305 operation.
  if (($req['target_scope']??'')==='wordpress'
    && ($job['job_type']??'')!=='signed_vps_wpcli')
      return self::deny('SIGNED_WPCLI_JOB_TYPE_REQUIRED');
  if (($req['target_scope']??'')==='wordpress') {
   // Only accept a grant minted during an owner-authenticated 305 enrollment.
   // 312 verifies central scope; the registered native worker proves host-side privilege.
   if (($transport['full_wpcli_grant_id']??null)!==($job['grant_id']??null)
    || (($transport['full_wpcli_pending_native_enrollment']??null)!==true && !MAD4B_Original_Verified_WPCLI_Transport_V1::active($transport,$grant))
    || ($transport['capabilities']??null)!==['wpcli.full']
    || ($grant['transport_id']??null)!==$transport_id)
      return self::deny('OWNER_ENROLLED_WPCLI_TRANSPORT_REQUIRED');
  }
  // A Job may use a persistent standing grant, but every correlation
  // consumes its own original 305 claim. No cross-company replays.
  $original_claim_key=MAD4B_Central_SSH_Command_Intake_V1::CLAIM_PREFIX
    .(string)$job['grant_id'].'_'.hash('sha256',(string)($job['correlation_id']??''));
  if (get_option($original_claim_key,false)!==$job_id)
    return self::deny('ORIGINAL_PER_JOB_CLAIM_MISSING');
  if (($job['company']??'')==='tours-central') {
   // Native client identity must be proved in the ORIGINAL standing
   // 305 grant, 306 Job, transport and exact 305 request together.
   // A WordPress user ID of 0 is NOT a user impersonation or owner grant.
   if (($job['principal_type']??'')==='vps_native') {
     if (!class_exists('MAD4B_Native_VPS_Principal_V1',false)
       || !MAD4B_Native_VPS_Principal_V1::valid_job_chain($job,$grant,$transport,$req)
       || ($job['central_principal_id']??null)!==0)
       return self::deny('NATIVE_PRINCIPAL_CHAIN_NOT_VERIFIED');
   } else {
   // Local native host is not a company execution target.
   if ($target!==self::HOST || ($req['target_scope']??'')!=='host'
     || ($req['target_scope']??'')!=='host'
     || !class_exists('MAD4B_Central_Host_Scope_V1',false)
     || !MAD4B_Central_Host_Scope_V1::matches('tours-central',
       (string)($job['site_uuid']??''),(string)($job['canonical_origin']??''),
       (string)($job['environment']??''),(int)($job['central_principal_id']??0)))
     return self::deny('CENTRAL_HOST_IDENTITY_OR_OWNER_MISMATCH');
   // Match the original 305 consumed claim; a grant cannot authorize
   // another command or an unrelated correlation identifier.
   $claim_key=MAD4B_Central_SSH_Command_Intake_V1::CLAIM_PREFIX.(string)$job['grant_id'];
   if (($grant['grant_mode']??'')==='persistent')
     $claim_key.='_'.hash('sha256',(string)($job['correlation_id']??''));
   if (get_option($claim_key,false)!==$job_id)
     return self::deny('ORIGINAL_LOCAL_GRANT_CLAIM_MISSING');
   }
   // Both the native and original owner lane must consume the original
   // per-correlation 305 claim. A persistent grant is not a wildcard job.
   $native_claim_key=MAD4B_Central_SSH_Command_Intake_V1::CLAIM_PREFIX.(string)$job['grant_id']
     .'_'.hash('sha256',(string)($job['correlation_id']??''));
   if (get_option($native_claim_key,false)!==$job_id)
     return self::deny('ORIGINAL_LOCAL_GRANT_CLAIM_MISSING');
  } else {
  $target_scope=MAD4B_Manager_Auto_Write_V1::company_target((string)$job['company']);
  if (($target_scope['state']??null)!=='REGISTERED'
   || ($target_scope['site_uuid']??null)!==($job['site_uuid']??null)
   || ($target_scope['canonical_origin']??null)!==($job['canonical_origin']??null)
   || ($target_scope['environment']??null)!==($job['environment']??null))
     return self::deny('COMPANY_BINDING_CHANGED');
  $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
  // Recheck the original owner-approved exact Staging assignment on EVERY Job.
  // No fallback to company-only Production rights for a Staging Job.
  $active=((string)($job['environment']??'')==='staging')
    ? (class_exists('MAD4B_Staging_Manager_Admission_Bridge_V1',false)
       && MAD4B_Staging_Manager_Admission_Bridge_V1::has_delegation(
         $manager,(string)$job['company'],'operations_manage',(int)$job['central_principal_id']))
    : MAD4B_Company_Portfolio_Manager_V1::has_delegation(
         $manager,(string)$job['company'],'operations_manage',(int)$job['central_principal_id']);
  if (!$manager || !$active)
     return self::deny('COMPANY_DELEGATION_REVOKED');
  }
  return ['contract'=>self::CONTRACT,'verified'=>true,'eligible'=>true,
   'reason'=>'CENTRAL_SCOPE_VERIFIED_HOST_PROOF_REQUIRED',
   'job_id'=>$job_id,'target_id'=>$target,
   'principal_id'=>($job['principal_type']??'')==='vps_native'
      ?(string)$job['principal_id']:(string)$job['central_principal_id'],
   'principal_type'=>($job['principal_type']??'wordpress_owner'),
   'correlation_id'=>(string)$job['correlation_id'],
   'host_principal'=>$transport['host_principal'],
   'host_key_fingerprint'=>$transport['host_key_fingerprint'],
   'credential_ref'=>$transport['credential_ref'],
   'company'=>$job['company'],'site_uuid'=>$job['site_uuid'],
   'origin'=>$job['canonical_origin'],'environment'=>$job['environment'],
   'operation'=>$job['operation'],'command_sha256'=>$digest,
   'target_scope'=>$req['target_scope'],
   'owner_enrolled_wpcli'=>(($req['target_scope']??'')==='wordpress'),
   'grant_id'=>$job['grant_id'],'transport_id'=>$transport_id,
   'expires_epoch'=>(int)$job['expires_epoch'],
   'shell_executed'=>false,'central_scope_only'=>true];
 }
 /** Original 312 authority for WP-CLI remote; returns a scoped
  * preflight only. Never executes or signs an Original 313 receipt.
  * Uses the same signed `verify` command, original 306 Claim and nonce gate.
  */
 private static function evaluate_remote_wordpress(string $id,string $target,string $sha,array $job):array {
  if(!in_array($target,self::REMOTE_WPCLI_TARGETS,true)
   || !class_exists('MAD4B_Company_Environment_Registry_V2',false)
   || !class_exists('MAD4B_Original_Remote_Hostinger_328_V1',false)
   || !class_exists('MAD4B_Manager_Auto_Write_V1',false)
   || !class_exists('MAD4B_Company_Operation_Admission_V1',false)
   || !class_exists('MAD4B_Company_Portfolio_Manager_V1',false))
    return self::deny('REMOTE_WORDPRESS_CORE_NOT_READY');
  $b='MAD4B_Central_VPS_Broker_V1';$i='MAD4B_Central_SSH_Command_Intake_V1';
  $claim=get_option($b::CLAIM.$id,false);
  $req=get_option($i::REQUEST_PREFIX.$id,false);
  $route=get_option($b::ROUTE_GATE.$id,false);
  $now=time();$company=(string)($job['company']??'');
  $env=(string)($job['environment']??'');
  $site=MAD4B_Company_Environment_Registry_V2::target($company,$env);
  if(!is_array($claim)||!is_array($req)||!is_array($route)||!is_array($site)
   ||($job['job_id']??null)!==$id
   ||($job['broker_host_id']??null)!==self::HOST
   ||($job['target_host_id']??null)!==$target
   ||($job['job_type']??null)!=='signed_remote_hostinger_wpcli'
   ||($job['operation']??null)!=='company.remote.wpcli.full'
   ||($job['target_scope']??null)!=='wordpress'
   ||($job['command_format']??null)!=='wpcli.argv.json.v1'
   ||($job['grant_mode']??null)!=='persistent'
   ||($job['command_sha256']??null)!==$sha
   ||!preg_match('/^[a-f0-9]{64}$/D',(string)($job['sealed_argv_sha256']??''))
   ||($job['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
   ||($job['executed']??true)!==false
   ||($job['independent_host_permission_verified']??true)!==false
   ||(int)($job['expires_epoch']??0)<=$now+90
   ||($claim['contract']??null)!==$b::CONTRACT
   ||($claim['job_id']??null)!==$id
   ||($claim['host_id']??null)!==self::HOST
   ||($claim['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
   ||($claim['production_mutation_performed']??true)!==false
   ||($claim['claimed_epoch']??null)!==($job['claimed_epoch']??null)
   ||(int)($claim['claimed_epoch']??0)<=$now-3600
   ||!preg_match('/^[a-f0-9]{32}$/D',(string)($claim['nonce']??''))
   ||($route['decision']??null)!=='CLAIM'
   ||($req['request_id']??null)!==$id
   ||($req['status']??null)!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
   ||($req['execution_performed']??true)!==false
   ||($req['grant_mode']??null)!=='persistent'
   ||($req['sealed_argv_transport']??null)!=='host_bound_x25519_original_305'
   ||($req['sealed_argv_sha256']??null)!==$job['sealed_argv_sha256']
   ||($req['command_sha256']??null)!==$sha
   ||(int)($req['expires_epoch']??0)<=$now
   ||($site['site_uuid']??null)!==($job['site_uuid']??null)
   ||($site['canonical_origin']??null)!==($job['canonical_origin']??null))
     return self::deny('REMOTE_EXACT_CLAIM_OR_SITE_NOT_ELIGIBLE');
  foreach(['company','environment','site_uuid','canonical_origin','grant_id',
   'transport_id','target_host_id','target_ssh_principal','correlation_id',
   'operation','target_scope','command_format','central_principal_id'] as $key)
   if(($req[$key]??null)!==($job[$key]??null))
     return self::deny('REMOTE_305_306_JOB_DRIFT');
  $grant=get_option($i::PREFIX.(string)($job['grant_id']??''),false);
  $transport=get_option($i::TRANSPORT_PREFIX.(string)($job['transport_id']??''),false);
  if(!is_array($grant)||!is_array($transport)
   ||!array_key_exists('expires_epoch',$grant)
   ||($grant['expires_epoch']!==null && (!is_int($grant['expires_epoch']) || $grant['expires_epoch']<=$now))
   ||($grant['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
   ||($grant['grant_mode']??null)!=='persistent'
   ||($grant['operation']??null)!==$job['operation']
   ||($grant['transport_id']??null)!==$job['transport_id']
   ||($grant['target_host_id']??null)!==$target
   ||($grant['target_ssh_principal']??null)!==($job['target_ssh_principal']??null)
   ||($grant['central_principal_id']??null)!==($job['central_principal_id']??null)
   ||($grant['principal_type']??null)!=='signed_hostinger_ssh_wpcli'
   ||($transport['principal_type']??null)!=='signed_hostinger_ssh_wpcli'
   ||($transport['host_id']??null)!==$target
   ||($transport['host_principal']??null)!==($job['target_ssh_principal']??null)
   ||($transport['transport_id']??null)!==$job['transport_id']
   ||($transport['state']??null)!=='REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED')
     return self::deny('REMOTE_GRANT_OR_TRANSPORT_INACTIVE');
  foreach(['company','environment','site_uuid','canonical_origin'] as $key)
   if(($grant[$key]??null)!==($job[$key]??null)
    ||($transport[$key]??null)!==($job[$key]??null))
      return self::deny('REMOTE_TENANT_BINDING_CHANGED');
  $claimKey=$i::CLAIM_PREFIX.(string)$job['grant_id'].'_'.hash('sha256',(string)$job['correlation_id']);
  if(get_option($claimKey,false)!==$id)
    return self::deny('REMOTE_305_CORRELATION_CLAIM_MISSING');
  if(!MAD4B_Original_Remote_Hostinger_328_V1::active($transport,$grant))
    return self::deny('ORIGINAL_328_REMOTE_HOST_NOT_ACTIVE');
  $current=MAD4B_Manager_Auto_Write_V1::company_target($company);
  $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
  $active=($env==='staging')
    ? (class_exists('MAD4B_Staging_Manager_Admission_Bridge_V1',false)
       && MAD4B_Staging_Manager_Admission_Bridge_V1::has_delegation(
          $manager,$company,'operations_manage',(int)$job['central_principal_id']))
    : MAD4B_Company_Portfolio_Manager_V1::has_delegation(
          $manager,$company,'operations_manage',(int)$job['central_principal_id']);
  if(!is_array($current)||($current['state']??null)!=='REGISTERED'
   ||($current['site_uuid']??null)!==$job['site_uuid']
   ||($current['canonical_origin']??null)!==$job['canonical_origin']
   ||($current['environment']??null)!==$env
   ||!$manager||!$active)
     return self::deny('REMOTE_COMPANY_AUTHORITY_REVOKED');
  return ['contract'=>self::CONTRACT,'verified'=>true,'eligible'=>true,
   'reason'=>'ORIGINAL_REMOTE_WORDPRESS_312_EXACT_SCOPE_VERIFIED',
   'job_id'=>$id,'target_id'=>$target,'company'=>$company,'environment'=>$env,
   'site_uuid'=>$job['site_uuid'],'origin'=>$job['canonical_origin'],
   'broker_host_id'=>self::HOST,'host_principal'=>$transport['host_principal'],
   'host_key_fingerprint'=>$transport['host_key_fingerprint'],
   'credential_ref'=>$transport['credential_ref'],
   'operation'=>$job['operation'],'target_scope'=>'wordpress',
   'job_type'=>$job['job_type'],'command_sha256'=>$sha,
   'sealed_argv_sha256'=>$job['sealed_argv_sha256'],
   'grant_id'=>$job['grant_id'],'transport_id'=>$job['transport_id'],
   'expires_epoch'=>(int)$job['expires_epoch'],'central_scope_only'=>true,
   'owner_enrolled_wpcli'=>true,'shell_executed'=>false,
   'native_313_executor_certified'=>false,'production_mutation'=>false];
 }
 /** Actor-free v2 signature PRECHECK only. Uses original 328->305
  * Broker verification; a verified principal is not a signed 312 job grant.
  * The legacy v1 evaluate() / evaluate_remote_wordpress() stays unchanged.
  */
 public static function verifyHostPrincipalV2(array $proof):array {
  if(!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
    ||!MAD4B_Central_Dual_Lane_Registry_V1::central()
    ||!class_exists('MAD4B_Original_Remote_Hostinger_328_V1',false))
    return self::deny('ORIGINAL_312_HOST_V2_SOURCE_UNAVAILABLE');
  $verified=MAD4B_Original_Remote_Hostinger_328_V1::verifyHostPrincipalV2($proof);
  if(($verified['verified']??false)!==true
    ||($verified['principal_type']??null)!=='signed_host_principal_v2'
    ||($verified['original_328_certified']??true)!==false
    ||($verified['original_312_313_certified']??true)!==false
    ||($verified['write_authorized']??true)!==false
    ||($verified['grant_created']??true)!==false
    ||($verified['execution_performed']??true)!==false
    ||($verified['company']??null)!==($proof['company']??null)
    ||($verified['environment']??null)!==($proof['environment']??null)
    ||($verified['site_uuid']??null)!==($proof['site_uuid']??null)
    ||($verified['command_sha256']??null)!==($proof['command_sha256']??null))
    return self::deny('ORIGINAL_312_HOST_V2_SIGNED_PRINCIPAL_OR_SCOPE_NOT_VERIFIED');
  return ['contract'=>'mad4b.broker-host-principal-precheck.v2',
    'state'=>'HOST_V2_SIGNATURE_VERIFIED__ORIGINAL_312_JOB_GRANT_REQUIRED',
    'verified'=>true,'eligible'=>false,
    'principal_type'=>'signed_host_principal_v2',
    'company'=>$verified['company'],'environment'=>$verified['environment'],
    'site_uuid'=>$verified['site_uuid'],
    'command_sha256'=>$verified['command_sha256'],
    'actor_user_id_required'=>false,'host_write_authorized'=>false,
    'original_305_grant_certified'=>false,'original_328_certified'=>false,
    'original_312_job_certified'=>false,'original_313_execution_certified'=>false,
    'grant_created'=>false,'shell_executed'=>false,'production_mutation'=>false];
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
  if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')
   || !class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   || !MAD4B_Central_Dual_Lane_Registry_V1::central()
   )WP_CLI::error('CENTRAL_HOST_AUTHORITY_REQUIRED');
  $action=(string)($args[0]??'');
  // Signed host preflight can only verify existing exact WordPress grants.
  // Other commands still need an authenticated administrative WordPress principal.
  if ($action==='claimed' && (get_current_user_id()<1 || !current_user_can('manage_options')))
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
      || ($job['grant_mode']??null)!=='persistent'
      || (int)($job['expires_epoch']??0)<=time())continue;
    $items[]=['job_id'=>$job['job_id'],'target_id'=>$job['target_host_id'],
      'command_sha256'=>$job['command_sha256'],'expires_epoch'=>(int)$job['expires_epoch']];
    if (count($items)>=max(1,min(10,(int)($assoc['limit']??10))))break;
   }
   WP_CLI::line(wp_json_encode(['contract'=>self::CONTRACT,'claimed'=>$items],JSON_UNESCAPED_SLASHES));
   return;
  }
  if($action==='verify-host-v2' && array_keys($assoc)===['proof']){
    $raw=base64_decode((string)$assoc['proof'],true);
    if(!is_string($raw)||strlen($raw)>7200)
      WP_CLI::error('ORIGINAL_312_EXACT_HOST_V2_SIGNED_PROOF_REQUIRED');
    $proof=json_decode($raw,true);
    $result=is_array($proof)?self::verifyHostPrincipalV2($proof)
      :self::deny('ORIGINAL_312_HOST_V2_JSON_INVALID');
    WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
    if(($result['verified']??false)!==true)WP_CLI::halt(2);
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
