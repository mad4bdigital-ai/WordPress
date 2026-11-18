<?php
/**
 * Plugin Name: MAD4B Host-Attested Native WP-CLI Grant
 * Description: Central one-time grant for exact, read-only, signed staging canary.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Native_Host_Exact_Grant_V1 {
 const CONTRACT='mad4b.native-host-exact-grant.v1';
 const GRANT='mad4b_native_host_grant_v1_';
 const REQUEST='mad4b_native_host_request_v1_';
 const NONCE='mad4b_native_host_grant_nonce_v1_';
 const HOST='srv2030908';
 const TARGET='hosting-2';
 const COMPANY='all-royal-egypt';
 const SITE='49c562d1-8f2f-456f-b454-26816c6ba4cb';
 const ORIGIN='https://staging.allroyalegypt.com';
 const SSH_USER='u948953904';
 const DYNAMIC_IDENTITY_CONTRACT='mad4b.company.native-host-signed-identity.v2';
 const DYNAMIC_IDENTITY_PREFIX='mad4b_company_native_host_signed_identity_v2_';
 const DYNAMIC_IDENTITY_NONCE='mad4b_company_native_host_signed_identity_nonce_v2_';
 const DYNAMIC_ANCHOR_PREFIX='mad4b_company_native_host_identity_anchor_v1_';
 const DYNAMIC_ANCHOR_CONTRACT='mad4b.company-host-authority-anchor.v2';
 const DYNAMIC_ANCHOR_SIGNED_FIELDS=['contract','company','environment','site_uuid','canonical_origin','profile_digest','host_id','ssh_target','ssh_principal','ssh_root_sha256','host_key_sha256','registry_sha256','policy_sha256','revision','issued','expires','nonce','state','revoked','source'];
 const DYNAMIC_IDENTITY_FIELDS=['contract','company','environment','site_uuid',
  'canonical_origin','profile_digest','host_id','ssh_target','ssh_principal',
  'ssh_root_sha256','host_key_sha256','registry_sha256','policy_sha256',
  'issued','expires','nonce'];
 private static function okhex($x,$n) { return is_string($x) && preg_match('/^[a-f0-9]{'.(int)$n.'}$/D',$x); }
 private static function deny($code) { return ['contract'=>self::CONTRACT,'eligible'=>false,'reason'=>$code,'grant_created'=>false,'execution_performed'=>false]; }
 private static function identity() {
  if (!class_exists('MAD4B_Environment_Signed_Identity_V4',false)) return false;
  $s=MAD4B_Environment_Signed_Identity_V4::status(self::COMPANY,'staging');
  return ($s['centrally_verified']??false)===true &&
    ($s['site_uuid']??'')===self::SITE && ($s['canonical_origin']??'')===self::ORIGIN;
 }
 private static function authorized_host($x,$action='issue') {
  if (!defined('WP_CLI') || !WP_CLI ||
    !class_exists('MAD4B_Central_VPS_Broker_V1',false) ||
    !class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false) ||
    !MAD4B_Central_Dual_Lane_Registry_V1::central()) return false;
  $nonce=(string)($x['nonce']??'');
  $issued=(int)($x['issued']??0);$expires=(int)($x['expires']??0);
  $payload=(string)($x['payload']??'');
  if (!self::okhex($nonce,32) || $issued<time()-60 || $issued>time()+10 ||
    $expires<$issued || $expires>$issued+60 || $expires<time() ||
    strlen($payload)>5300) return false;
  $body=base64_decode($payload,true);
  if (!is_string($body) || strlen($body)>3900 || strlen($body)<50) return false;
  $signature=base64_decode((string)($x['signature']??''),true);
  $public=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  if (!is_string($signature) || strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES ||
      !is_string($public) || strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;
  $signed=implode("\n",[self::CONTRACT,$action,self::HOST,self::TARGET,
    hash('sha256',$body),$nonce,(string)$issued,(string)$expires]);
  return sodium_crypto_sign_verify_detached($signature,$signed,$public);
 }
 public static function issue(array $x) {
  if (!self::authorized_host($x)) return self::deny('SIGNED_ORIGINAL_HOST_REQUIRED');
  if (!self::identity()) return self::deny('SIGNED_STAGING_SITE_IDENTITY_REQUIRED');
  $body=base64_decode((string)$x['payload'],true);
  $data=json_decode($body,true);
  if (!is_array($data) || array_keys($data)!==[
     'args','contract','expected_readback_sha256','readback_args'] ||
     ($data['contract']??'')!=='mad4b.wpcli-exact-operation.v1' ||
     ($data['args']??null)!==['option','get','siteurl'] ||
     ($data['readback_args']??null)!==['option','get','siteurl'] ||
     ($data['expected_readback_sha256']??'')!==hash('sha256',self::ORIGIN."\n"))
      return self::deny('EXACT_READ_ONLY_CANARY_SCOPE_REQUIRED');
  $rebuild=wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  if (!hash_equals($rebuild,$body)) return self::deny('CANONICAL_OPERATION_MISMATCH');
  $nonce=(string)$x['nonce'];
  if (!add_option(self::NONCE.$nonce,time(),'',false))
      return self::deny('SIGNED_HOST_ISSUANCE_REPLAY');
  $id=bin2hex(random_bytes(16));
  $grant=bin2hex(random_bytes(16));
  $expiry=time()+300;
  $row=['contract'=>self::CONTRACT,'request_id'=>$id,'grant_id'=>$grant,
        'host_id'=>self::HOST,'target_host_id'=>self::TARGET,
        'company'=>self::COMPANY,'site_uuid'=>self::SITE,
        'canonical_origin'=>self::ORIGIN,'environment'=>'staging',
        'target_ssh_principal'=>self::SSH_USER,
        'operation'=>'maintenance.shell','target_scope'=>'wordpress',
        'grant_mode'=>'one_time','command_sha256'=>hash('sha256',$body),
        'issued_at'=>time(),'expires_epoch'=>$expiry,
        'state'=>'SIGNED_HOST_STAGING_CANARY_ADMITTED',
        'execution_performed'=>false,'host_signature_verified'=>true,
        'site_signed_v4_verified'=>true];
  if (!add_option(self::GRANT.$grant,$row,'',false) ||
      !add_option(self::REQUEST.$id,$row,'',false))
      return self::deny('CENTRAL_GRANT_PERSISTENCE_FAILED');
  $actual=get_option(self::REQUEST.$id,null);
  if (!is_array($actual) || ($actual['command_sha256']??'')!==$row['command_sha256'])
      return self::deny('CENTRAL_GRANT_READBACK_FAILED');
  return ['contract'=>self::CONTRACT,'eligible'=>true,'state'=>$row['state'],
    'request_id'=>$id,'grant_id'=>$grant,
    'command_sha256'=>$row['command_sha256'],
    'site_uuid'=>self::SITE,'environment'=>'staging',
    'expires_epoch'=>$expiry,'host_signed'=>true,
    'site_signed_v4_verified'=>true,'execution_performed'=>false];
 }
 public static function verify($request,$target,$digest) {
  if (!self::okhex($request,32) || !self::okhex($digest,64) ||
      $target!==self::TARGET || !self::identity()) return self::deny('NATIVE_HOST_SCOPE_NOT_VALID');
  $row=get_option(self::REQUEST.$request,false);
  if (!is_array($row) || ($row['contract']??'')!==self::CONTRACT ||
      ($row['state']??'')!=='SIGNED_HOST_STAGING_CANARY_ADMITTED' ||
      ($row['request_id']??'')!==$request ||
      ($row['target_host_id']??'')!==self::TARGET ||
      ($row['command_sha256']??'')!==$digest ||
      ($row['site_uuid']??'')!==self::SITE ||
      ($row['canonical_origin']??'')!==self::ORIGIN ||
      ($row['environment']??'')!=='staging' ||
      (int)($row['expires_epoch']??0)<=time() ||
      ($row['execution_performed']??true)!==false)
     return self::deny('ORIGINAL_HOST_CANARY_GRANT_NOT_ELIGIBLE');
  $grant=get_option(self::GRANT.$row['grant_id'],false);
  if (!is_array($grant) || $grant!==$row)
      return self::deny('ORIGINAL_NATIVE_GRANT_REVOKED_OR_CHANGED');
  return ['contract'=>'mad4b.wpcli-exact-central-grant.v1',
    'verified'=>true,'eligible'=>true,
    'reason'=>'SIGNED_HOST_AND_SIGNED_V4_SITE_VERIFIED',
    'request_id'=>$request,'target_id'=>self::TARGET,
    'command_sha256'=>$digest,'company'=>self::COMPANY,
    'site_uuid'=>self::SITE,'canonical_origin'=>self::ORIGIN,
    'environment'=>'staging','principal_id'=>'host:'.self::HOST,
    'host_principal'=>self::SSH_USER,'grant_id'=>$row['grant_id'],
    'grant_mode'=>'one_time','expires_epoch'=>(int)$row['expires_epoch'],
    'central_scope_only'=>true,'shell_executed'=>false,'grant_created'=>false];
 }
 public static function complete(array $x) {
  if (!self::authorized_host($x,'complete'))
    return self::deny('SIGNED_HOST_RECEIPT_REQUIRED');
  if (!self::identity())
    return self::deny('SIGNED_STAGING_SITE_IDENTITY_REQUIRED');
  $body=base64_decode((string)$x['payload'],true);
  $data=json_decode($body,true);
  if (!is_array($data) ||
      array_keys($data)!==['at_epoch','command_sha256','readback_sha256','receipt_sha256','request_id','state'] ||
      !self::okhex($data['request_id']??null,32) ||
      !self::okhex($data['command_sha256']??null,64) ||
      !self::okhex($data['readback_sha256']??null,64) ||
      !self::okhex($data['receipt_sha256']??null,64) ||
      ($data['state']??'')!=='VERIFIED' ||
      ($data['readback_sha256']??'')!==hash('sha256',self::ORIGIN."\n") ||
      !is_int($data['at_epoch']) ||
      !hash_equals((string)wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$body))
      return self::deny('EXACT_SIGNED_RECEIPT_INVALID');
  $id=$data['request_id'];
  $row=get_option(self::REQUEST.$id,false);
  if (!is_array($row) || ($row['request_id']??'')!==$id ||
      ($row['company']??'')!==self::COMPANY ||
      ($row['environment']??'')!=='staging' ||
      ($row['site_uuid']??'')!==self::SITE ||
      ($row['command_sha256']??'')!==$data['command_sha256'])
      return self::deny('ORIGINAL_REQUEST_NO_LONGER_MATCHES');
  if (($row['state']??'')==='VERIFIED') {
      return ($row['receipt_sha256']??'')===$data['receipt_sha256']
         ? ['contract'=>self::CONTRACT,'eligible'=>true,
            'state'=>'ALREADY_COMPLETED','request_id'=>$id,
            'grant_id'=>$row['grant_id'],'grant_created'=>false,
            'execution_performed'=>false]
         : self::deny('ALREADY_COMPLETED_RECEIPT_CONFLICT');
  }
  if (($row['state']??'')!=='SIGNED_HOST_STAGING_CANARY_ADMITTED' ||
      ($row['execution_performed']??true)!==false ||
      $data['at_epoch']<(int)($row['issued_at']??0) ||
      $data['at_epoch']>(int)($row['expires_epoch']??0)+300)
      return self::deny('EXACT_EXECUTION_WINDOW_NOT_ADMISSIBLE');
  $grant=get_option(self::GRANT.(string)$row['grant_id'],false);
  if (!is_array($grant) || $grant!==$row)
      return self::deny('ORIGINAL_GRANT_CHANGED_BEFORE_COMPLETION');
  $done=$row;
  $done['state']='VERIFIED';
  $done['execution_performed']=true;
  $done['completed_at']=gmdate('c');
  $done['receipt_sha256']=$data['receipt_sha256'];
  $done['readback_sha256']=$data['readback_sha256'];
  $done['central_receipt_signed_by_original_host']=true;
  if (!update_option(self::REQUEST.$id,$done,false))
      return self::deny('CENTRAL_REQUEST_COMPLETION_WRITE_FAILED');
  if (!update_option(self::GRANT.$row['grant_id'],$done,false))
      return self::deny('CENTRAL_GRANT_CONSUMPTION_RECONCILIATION_REQUIRED');
  $readback=get_option(self::REQUEST.$id,false);
  if (($readback['state']??'')!=='VERIFIED' ||
      ($readback['receipt_sha256']??'')!==$data['receipt_sha256'])
      return self::deny('CENTRAL_RECEIPT_READBACK_FAILED');
  return ['contract'=>self::CONTRACT,'eligible'=>true,
    'state'=>'VERIFIED','request_id'=>$id,'grant_id'=>$row['grant_id'],
    'receipt_sha256'=>$data['receipt_sha256'],
    'grant_consumed'=>true,'grant_created'=>false,
    'recorded_execution_verified'=>true,'shell_executed_by_this_call'=>false];
 }
 /**
  * First-party signed HOST identity readback for any enrolled company/env.
  * Distinct from signed target P-256 V4 and NEVER a 305/318 mutation grant.
  * The already pinned VPS Host signer authorizes an exact read-only claim.
  */
 /** Separately enrolled host authority snapshot; NEVER derived from a
  * self-signed identity claim or a Hostinger read route alone. An enrolled
  * anchor must be maintained by the original signed Host authority lifecycle.
  */
 /** Separately signed Host Authority anchor, using the existing Central
  * Broker public key. A WordPress option/source label is NEVER an authority.
  * The signed anchor is stored only by a separate approved enroll/reconcile.
  */
 private static function dynamic_anchor(string $company,string $environment):?array {
  $key=self::DYNAMIC_ANCHOR_PREFIX.$company.'_'.$environment;
  $row=get_option($key,false);
  if(!is_array($row)
     ||array_keys($row)!==array_merge(self::DYNAMIC_ANCHOR_SIGNED_FIELDS,['signature'])
     ||($row['contract']??null)!==self::DYNAMIC_ANCHOR_CONTRACT
     ||($row['company']??null)!==$company
     ||($row['environment']??null)!==$environment
     ||($row['state']??null)!=='ACTIVE_INDEPENDENT_HOST_AUTHORITY'
     ||($row['revoked']??true)!==false
     ||($row['source']??null)!=='original_approved_host_authority_reconcile'
     ||!is_int($row['revision'])||$row['revision']<1
     ||!is_int($row['issued'])||!is_int($row['expires'])
     ||$row['issued']>time()+10||$row['expires']<time()
     ||$row['expires']<=$row['issued']||$row['expires']>$row['issued']+3600
     ||!self::okhex($row['nonce'],32)
     ||!wp_is_uuid($row['site_uuid']))return null;
  foreach(['host_id','ssh_target','ssh_principal','canonical_origin'] as $field)
    if(!is_string($row[$field]??null)||$row[$field]==='')return null;
  if($row['host_id']!==self::HOST
    ||!preg_match('/^hosting-[123]$/D',$row['ssh_target'])
    ||!preg_match('/^u[0-9]{9}$/D',$row['ssh_principal'])
    ||!preg_match('/^https:\/\/[a-z0-9.-]+$/D',$row['canonical_origin']))return null;
  foreach(['profile_digest','ssh_root_sha256','host_key_sha256','registry_sha256','policy_sha256'] as $field)
    if(!self::okhex($row[$field]??null,64))return null;
  if(!class_exists('MAD4B_Central_VPS_Broker_V1',false)
    ||!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
    ||!MAD4B_Central_Dual_Lane_Registry_V1::central())return null;
  $public=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $signature=base64_decode((string)$row['signature'],true);
  if(!is_string($public)||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
     ||!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES)return null;
  $message=implode("\n",array_map(static fn($field)=>(string)$row[$field],self::DYNAMIC_ANCHOR_SIGNED_FIELDS));
  if(!sodium_crypto_sign_verify_detached($signature,$message,$public))return null;
  return $row;
 }
 /** Signed revoked Anchor state ONLY. Not a write grant or final 313 proof.
  * The independent root revision/high-water ledger is mandatory for certification.
  * An older valid ACTIVE snapshot can be restored to WordPress options; the root
  * consumer must reject it if its revision is below the independent current epoch.
  */
 public static function independent_revoked_anchor_observation_v2(string $company,string $environment):array {
  $out=['contract'=>'mad4b.original318.signed-revocation-observation.v2',
   'company'=>$company,'environment'=>$environment,
   'signed_revocation_record_observed'=>false,
   'independent_highwater_verified'=>false,
   'original313_certified'=>false,
   'write_authorized'=>false,'grant_created'=>false];
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
    ||!preg_match('/^[a-z][a-z0-9_-]{1,39}$/D',$environment))return $out;
  $row=get_option(self::DYNAMIC_ANCHOR_PREFIX.$company.'_'.$environment,false);
  if(!is_array($row)
    ||array_keys($row)!==array_merge(self::DYNAMIC_ANCHOR_SIGNED_FIELDS,['signature'])
    ||($row['contract']??null)!==self::DYNAMIC_ANCHOR_CONTRACT
    ||($row['company']??null)!==$company
    ||($row['environment']??null)!==$environment
    ||($row['state']??null)!=='REVOKED_INDEPENDENT_HOST_AUTHORITY'
    ||($row['revoked']??null)!==true
    ||($row['source']??null)!=='original_approved_host_authority_reconcile'
    ||!is_int($row['revision'])||$row['revision']<2
    ||!is_int($row['issued'])||!is_int($row['expires'])
    ||$row['issued']>time()+10||$row['expires']<=$row['issued']
    ||!self::okhex($row['nonce']??null,32)
    ||!wp_is_uuid($row['site_uuid']??null)
    ||!class_exists('MAD4B_Central_VPS_Broker_V1',false)
    ||!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
    ||!MAD4B_Central_Dual_Lane_Registry_V1::central())return $out;
  foreach(['host_id','ssh_target','ssh_principal','canonical_origin'] as $field)
    if(!is_string($row[$field]??null)||$row[$field]==='')return $out;
  if($row['host_id']!==self::HOST
    ||!preg_match('/^hosting-[123]$/D',$row['ssh_target'])
    ||!preg_match('/^u[0-9]{9}$/D',$row['ssh_principal'])
    ||!preg_match('/^https:\/\/[a-z0-9.-]+$/D',$row['canonical_origin']))return $out;
  foreach(['profile_digest','ssh_root_sha256','host_key_sha256','registry_sha256','policy_sha256'] as $field)
    if(!self::okhex($row[$field]??null,64))return $out;
  $site=class_exists('MAD4B_Company_Environment_Registry_V2',false)
    ?MAD4B_Company_Environment_Registry_V2::target($company,$environment):null;
  if(!is_array($site)
    ||($site['site_uuid']??null)!==$row['site_uuid']
    ||($site['canonical_origin']??null)!==$row['canonical_origin']
    ||($site['identity_evidence']['profile_digest']??null)!==$row['profile_digest'])return $out;
  $public=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $signature=base64_decode((string)$row['signature'],true);
  if(!is_string($public)||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    ||!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES)return $out;
  $message=implode("\n",array_map(static fn($field)=>(string)$row[$field],self::DYNAMIC_ANCHOR_SIGNED_FIELDS));
  if(!sodium_crypto_sign_verify_detached($signature,$message,$public))return $out;
  $out['signed_revocation_record_observed']=true;
  $out['site_uuid']=$row['site_uuid'];
  $out['anchor_revision']=$row['revision'];
  $out['anchor_nonce']=$row['nonce'];
  $out['signed_anchor_message_sha256']=hash('sha256',$message);
  return $out;
 }
 /** Read-only attestation cannot create or refresh its own authority anchor. */
 private static function dynamic_anchor_matches(array $anchor,array $p):bool {
  foreach(['company','environment','site_uuid','canonical_origin',
    'profile_digest','host_id','ssh_target','ssh_principal','ssh_root_sha256',
    'host_key_sha256','registry_sha256','policy_sha256'] as $key)
    if(!isset($anchor[$key],$p[$key]) ||
       !hash_equals((string)$anchor[$key],(string)$p[$key]))return false;
  return true;
 }
 /** Exact per-command host signer binding: not a grant or privilege.
  * Only an independently enrolled, nonrevoked v59+ host anchor may approve
  * this predicate; the identity receipt alone cannot certify a host policy.
  */
 public static function independent_anchor_live_v2(array $proof):bool {
  $co=$proof['company']??null;$env=$proof['environment']??null;
  if(!is_string($co)||!is_string($env))return false;
  $anchor=self::dynamic_anchor($co,$env);
  if(!is_array($anchor)||!self::dynamic_anchor_matches($anchor,$proof))return false;
  $identity=self::dynamic_identity_status($co,$env);
  return ($identity['identity_readback_verified']??false)===true;
 }
 public static function verify_dynamic_identity(array $p):array {
  if(array_keys($p)!==array_merge(self::DYNAMIC_IDENTITY_FIELDS,['signature']))
   return self::deny('DYNAMIC_HOST_IDENTITY_SIGNED_SHAPE_INVALID');
  foreach(self::DYNAMIC_IDENTITY_FIELDS as $f)
   if(!is_string($p[$f])&&!is_int($p[$f]))
    return self::deny('DYNAMIC_HOST_IDENTITY_TYPE_INVALID');
  $co=$p['company'];$env=$p['environment'];$now=time();
  if($p['contract']!==self::DYNAMIC_IDENTITY_CONTRACT
   ||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$co)
   ||!preg_match('/^[a-z][a-z0-9_-]{1,39}$/D',$env)
   ||!wp_is_uuid($p['site_uuid'])
   ||!preg_match('/^https:\/\/[a-z0-9.-]+$/D',$p['canonical_origin'])
   ||!self::okhex($p['profile_digest'],64)
   ||$p['host_id']!==self::HOST
   ||!preg_match('/^hosting-[123]$/D',$p['ssh_target'])
   ||!preg_match('/^u[0-9]{9}$/D',$p['ssh_principal'])
   ||!self::okhex($p['ssh_root_sha256'],64)
   ||!self::okhex($p['host_key_sha256'],64)
   ||!self::okhex($p['registry_sha256'],64)
   ||!self::okhex($p['policy_sha256'],64)
   ||!self::okhex($p['nonce'],32)
   ||!is_int($p['issued'])||!is_int($p['expires'])
   ||$p['issued']<$now-60||$p['issued']>$now+10
   ||$p['expires']<$now||$p['expires']>$p['issued']+60)
   return self::deny('DYNAMIC_HOST_IDENTITY_SCOPE_OR_TIME_INVALID');
  if(!defined('WP_CLI')||!WP_CLI
   ||!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
   ||!MAD4B_Central_Dual_Lane_Registry_V1::central()
   ||!class_exists('MAD4B_Central_VPS_Broker_V1',false)
   ||!class_exists('MAD4B_Company_Environment_Registry_V2',false))
   return self::deny('DYNAMIC_HOST_IDENTITY_CENTRAL_PROVIDER_MISSING');
  $site=MAD4B_Company_Environment_Registry_V2::target($co,$env);
  if(!is_array($site)
   ||($site['company_id']??null)!==$co
   ||($site['environment']??null)!==$env
   ||($site['site_uuid']??null)!==$p['site_uuid']
   ||($site['canonical_origin']??null)!==$p['canonical_origin']
   ||($site['identity_evidence']['profile_digest']??null)!==$p['profile_digest'])
   return self::deny('DYNAMIC_HOST_IDENTITY_CANONICAL_SITE_MISMATCH');
  $anchor=self::dynamic_anchor($co,$env);
  if(!$anchor || !self::dynamic_anchor_matches($anchor,$p))
   return self::deny('DYNAMIC_HOST_IDENTITY_INDEPENDENT_ANCHOR_MISSING_OR_DRIFT');
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode((string)$p['signature'],true);
  if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
   ||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
   return self::deny('DYNAMIC_HOST_IDENTITY_BROKER_KEY_MISSING');
  $message=implode("\n",array_map(static fn($key)=>(string)$p[$key],self::DYNAMIC_IDENTITY_FIELDS));
  if(!sodium_crypto_sign_verify_detached($sig,$message,$pub))
   return self::deny('DYNAMIC_HOST_IDENTITY_SIGNATURE_INVALID');
  return ['contract'=>self::DYNAMIC_IDENTITY_CONTRACT,'eligible'=>true,
   'state'=>'DYNAMIC_SIGNED_HOST_SITE_IDENTITY_VERIFIED',
   'company'=>$co,'environment'=>$env,'site_uuid'=>$p['site_uuid'],
   'canonical_origin'=>$p['canonical_origin'],
   'signed_sha256'=>hash('sha256',$message),
   'host_signed'=>true,'word_press_user_required'=>false,
   'remote_write_authorized'=>false,'grant_created'=>false,'execution_performed'=>false];
 }
 public static function attest_dynamic_identity(array $p):array {
  $v=self::verify_dynamic_identity($p);
  if(($v['eligible']??false)!==true)return $v;
  if(!add_option(self::DYNAMIC_IDENTITY_NONCE.$p['nonce'],
    ['company'=>$p['company'],'environment'=>$p['environment'],'epoch'=>time()],'',false))
   return self::deny('DYNAMIC_HOST_IDENTITY_NONCE_REPLAY');
  $key=self::DYNAMIC_IDENTITY_PREFIX.$p['company'].'_'.$p['environment'];
  $record=['contract'=>self::DYNAMIC_IDENTITY_CONTRACT,
   'state'=>'SIGNED_HOST_IDENTITY_READBACK_RECENT',
   'company'=>$p['company'],'environment'=>$p['environment'],
   'site_uuid'=>$p['site_uuid'],'canonical_origin'=>$p['canonical_origin'],
   'profile_digest'=>$p['profile_digest'],'host_id'=>$p['host_id'],
   'ssh_target'=>$p['ssh_target'],'ssh_principal'=>$p['ssh_principal'],
   'ssh_root_sha256'=>$p['ssh_root_sha256'],
   'host_key_sha256'=>$p['host_key_sha256'],
   'registry_sha256'=>$p['registry_sha256'],'policy_sha256'=>$p['policy_sha256'],
   'signed_sha256'=>$v['signed_sha256'],
   'issued'=>$p['issued'],'expires'=>$p['expires'],
   'nonce'=>$p['nonce'],'signature'=>$p['signature'],
   'anchor_revision'=>(int)(self::dynamic_anchor($p['company'],$p['environment'])['revision']??0),
   'verified_at_epoch'=>time(),
   'remote_write_authorized'=>false,'grant_created'=>false];
  if(!update_option($key,$record,false) && get_option($key,null)!==$record)
   return self::deny('DYNAMIC_HOST_IDENTITY_READBACK_PERSIST_FAILED');
  return self::dynamic_identity_status($p['company'],$p['environment']);
 }
 public static function dynamic_identity_status(string $company,string $environment):array {
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
    ||!preg_match('/^[a-z][a-z0-9_-]{1,39}$/D',$environment))
   return self::deny('DYNAMIC_IDENTITY_SCOPE_INVALID');
  $key=self::DYNAMIC_IDENTITY_PREFIX.$company.'_'.$environment;
  $row=get_option($key,false);
  $site=class_exists('MAD4B_Company_Environment_Registry_V2',false)
   ?MAD4B_Company_Environment_Registry_V2::target($company,$environment):null;
  $anchor=self::dynamic_anchor($company,$environment);
  // Rebuild the exact signed message on every readback. A stored option
  // alone cannot attest to itself, and expired signed proof is never current.
  $signedInput=[];
  if(is_array($row)) {
    foreach(self::DYNAMIC_IDENTITY_FIELDS as $field)$signedInput[$field]=$row[$field]??null;
    $signedInput['signature']=$row['signature']??null;
  }
  $reverified=is_array($anchor)&&is_array($site)&&is_array($row)
    &&(self::verify_dynamic_identity($signedInput)['eligible']??false)===true;
  $ok=$reverified&&is_array($anchor)
   &&self::dynamic_anchor_matches($anchor,$row)
   &&($row['anchor_revision']??null)===($anchor['revision']??null)
   &&($row['signed_sha256']??null)===hash('sha256',implode("\n",
        array_map(static fn($field)=>(string)$signedInput[$field],self::DYNAMIC_IDENTITY_FIELDS)))
   &&($row['contract']??'')===self::DYNAMIC_IDENTITY_CONTRACT
   &&($row['company']??'')===$company
   &&($row['environment']??'')===$environment
   &&($row['site_uuid']??'')===($site['site_uuid']??null)
   &&($row['canonical_origin']??'')===($site['canonical_origin']??null)
   &&($row['profile_digest']??'')===($site['identity_evidence']['profile_digest']??null)
   &&($row['state']??'')==='SIGNED_HOST_IDENTITY_READBACK_RECENT'
   &&($row['remote_write_authorized']??true)===false
   &&($row['grant_created']??true)===false
   &&($row['verified_at_epoch']??0)>0
   &&($row['verified_at_epoch']??0)<=time()
   &&time()-$row['verified_at_epoch']<=60;
  return ['contract'=>self::DYNAMIC_IDENTITY_CONTRACT,
   'state'=>$ok?'SIGNED_HOST_IDENTITY_READBACK_RECENT':'HOST_IDENTITY_ATTESTATION_MISSING_OR_STALE',
   'company'=>$company,'environment'=>$environment,
   'site_uuid'=>$ok?$row['site_uuid']:($site['site_uuid']??null),
   'identity_readback_verified'=>$ok,
   'word_press_user_required'=>false,'remote_write_authorized'=>false,
   'grant_created'=>false,'execution_performed'=>false];
 }
 /**
  * Fresh signed Original318 snapshot for the *independent* Original313 reader.
  *
  * This verifies the Broker signature against the current Site Profile.
  * It NEVER asserts the independent root-ledger high-water, a reserved Job,
  * write eligibility or an Original313 receipt. A restored but valid old
  * ACTIVE WordPress option is rejected by the separate root checkpoint.
  */
 public static function current_anchor_readback_v2(string $company,string $environment):array {
  $out=['contract'=>'mad4b.original318.host-anchor-current-readback.v2',
    'source'=>'original318_fresh_wp_readback',
    'company'=>$company,'environment'=>$environment,
    'observed_epoch'=>time(),'readback_verified'=>false,
    'state'=>'MISSING_OR_INVALID','revision'=>0,
    'anchor_nonce'=>null,'site_uuid'=>null,
    'current_anchor_signature_verified'=>false,
    'current_anchor_eligible'=>false,'signed_anchor_active_in_option'=>false,
    'prior_grants_ineligible'=>false,
    'fresh_central_highwater_verified'=>false,
    'original313_certified'=>false,'write_authorized'=>false,
    'grant_created'=>false];
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
    ||!preg_match('/^[a-z][a-z0-9_-]{1,39}$/D',$environment)
    ||!class_exists('MAD4B_Company_Environment_Registry_V2',false))return $out;
  $site=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
  if(!is_array($site)||($site['company_id']??null)!==$company
    ||($site['environment']??null)!==$environment
    ||!wp_is_uuid((string)($site['site_uuid']??''))
    ||!is_string($site['identity_evidence']['profile_digest']??null))return $out;
  $record=self::dynamic_anchor($company,$environment);
  if(is_array($record)
    &&($record['site_uuid']??null)===$site['site_uuid']
    &&($record['canonical_origin']??null)===$site['canonical_origin']
    &&($record['profile_digest']??null)===$site['identity_evidence']['profile_digest']) {
    $message=implode(chr(10),array_map(static fn($field)=>(string)$record[$field],
      self::DYNAMIC_ANCHOR_SIGNED_FIELDS));
    $out['site_uuid']=$record['site_uuid'];
    $out['state']='ACTIVE';$out['revision']=$record['revision'];
    $out['anchor_nonce']=$record['nonce'];
    $out['readback_verified']=true;
    $out['current_anchor_signature_verified']=true;
    $out['signed_anchor_active_in_option']=true;
    $out['signed_anchor_message_sha256']=hash('sha256',$message);
    return $out;
  }
  $revoked=self::independent_revoked_anchor_observation_v2($company,$environment);
  if(($revoked['signed_revocation_record_observed']??false)===true
    &&($revoked['site_uuid']??null)===$site['site_uuid']) {
    $out['site_uuid']=$revoked['site_uuid'];
    $out['state']='REVOKED';$out['revision']=$revoked['anchor_revision'];
    $out['anchor_nonce']=$revoked['anchor_nonce'];
    $out['readback_verified']=true;
    $out['signed_anchor_message_sha256']=$revoked['signed_anchor_message_sha256'];
  }
  return $out;
 }
 /**
  * Original318 v95: purpose-separated WP-CLI reconciliation, NOT a grant.
  * Requires BOTH signed Broker Anchor payload and independent Original313
  * pre-write approval for an exact claimed Original306 Job.
  *
  * No REST endpoint, no --user authority, no generic SQL executor.
  * The Root issuer must certify a fresh 318 post-write result separately;
  * the response never claims that Original313 has certified execution.
  */
 const V95_APPROVAL_CONTRACT='mad4b.original313.host-anchor-write-admission.v1';
 const V95_APPROVAL_FIELDS=['contract','job_id','operation_id','company','environment',
   'site_uuid','plan_sha256','command_sha256','grant_id','anchor_revision_before',
   'anchor_revision_after','anchor_nonce','anchor_message_sha256',
   'issued_epoch','expires_epoch'];
 const V95_APPROVAL_SIGN_DOMAIN="MAD4B_ORIGINAL313_HOST_ANCHOR_WRITE_V1\n";
 // Exact immutable public key (not private), registered in the original
 // independent Root Original313 Registry; never read from an admin option.
 const V95_INDEPENDENT_313_PUBLIC_B64='4jwcLgOUf2d/j2uDYbVysCnkSMJW64cXnhMPaeix11Y=';

 private static function v95_approval_message(array $row):string {
  $copy=[];foreach(self::V95_APPROVAL_FIELDS as $name)$copy[$name]=$row[$name];
  ksort($copy,SORT_STRING);
  return self::V95_APPROVAL_SIGN_DOMAIN .
    wp_json_encode($copy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 }
 private static function v95_denied($reason):array {
  return ['contract'=>'mad4b.original318.anchor-reconcile-signed.v1',
    'state'=>'DENIED','reason'=>$reason,'anchor_issued'=>false,
    'anchor_revoked'=>false,'original313_certified'=>false,
    'write_authorized'=>false,'grant_created'=>false];
 }
 public static function reconcile_signed_anchor_v95(array $document):array {
  if(!defined('WP_CLI')||!WP_CLI
     ||!class_exists('MAD4B_Central_VPS_Broker_V1',false)
     ||!class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
     ||!class_exists('MAD4B_Original_Remote_Hostinger_328_V1',false)
     ||!class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false)
     ||!class_exists('MAD4B_Company_Environment_Registry_V2',false)
     ||!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
     ||!MAD4B_Central_Dual_Lane_Registry_V1::central())
     return self::v95_denied('ORIGINAL_CENTRAL_WPCLI_REQUIRED');
  if(array_keys($document)!==['contract','job_id','operation_id','anchor','approval']
     ||($document['contract']??null)!=='mad4b.original318.anchor-reconcile-signed.v1')
    return self::v95_denied('EXACT_SIGNED_ENVELOPE_REQUIRED');
  $jid=$document['job_id'];$op=$document['operation_id'];
  if(!self::okhex($jid,32)
     ||!in_array($op,['company.host-authority.anchor.issue',
                      'company.host-authority.anchor.revoke'],true))
    return self::v95_denied('CLAIMED_TYPED_JOB_REQUIRED');
  $anchor=$document['anchor'];$approval=$document['approval'];
  if(!is_array($anchor)||!is_array($approval)
     ||array_keys($anchor)!==array_merge(self::DYNAMIC_ANCHOR_SIGNED_FIELDS,['signature'])
     ||array_keys($approval)!==array_merge(self::V95_APPROVAL_FIELDS,['signature'])
     ||($approval['contract']??null)!==self::V95_APPROVAL_CONTRACT)
    return self::v95_denied('EXACT_DUAL_SIGNED_RECORDS_REQUIRED');
  $company=$anchor['company'];$env=$anchor['environment'];
  if($company!=='all-royal-egypt'||$env!=='staging'
     ||($anchor['contract']??null)!==self::DYNAMIC_ANCHOR_CONTRACT
     ||($anchor['source']??null)!=='original_approved_host_authority_reconcile'
     ||($anchor['host_id']??null)!==self::HOST
     ||($anchor['ssh_target']??null)!=='hosting-2'
     ||!preg_match('/^u[0-9]{9}$/D',(string)($anchor['ssh_principal']??''))
     ||!is_int($anchor['revision'])||$anchor['revision']<1
     ||!is_int($anchor['issued'])||!is_int($anchor['expires'])
     ||$anchor['issued']<time()-60||$anchor['issued']>time()+10
     ||$anchor['expires']<time()+30||$anchor['expires']>$anchor['issued']+3600
     ||!self::okhex($anchor['nonce'],32)
     ||!wp_is_uuid($anchor['site_uuid']))
    return self::v95_denied('SIGNED_STAGING_ANCHOR_SCOPE_INVALID');
  foreach(['profile_digest','ssh_root_sha256','host_key_sha256',
           'registry_sha256','policy_sha256'] as $field)
   if(!self::okhex($anchor[$field]??null,64))
     return self::v95_denied('SIGNED_ANCHOR_DIGEST_INVALID');
  $site=MAD4B_Company_Environment_Registry_V2::target($company,$env);
  if(!is_array($site)
     ||($site['site_uuid']??null)!==$anchor['site_uuid']
     ||($site['canonical_origin']??null)!==$anchor['canonical_origin']
     ||($site['identity_evidence']['profile_digest']??null)!==$anchor['profile_digest'])
    return self::v95_denied('SIGNED_SITE_IDENTITY_CHANGED');
  $message=implode("\n",array_map(
     static fn($field)=>(string)$anchor[$field],
     self::DYNAMIC_ANCHOR_SIGNED_FIELDS));
  $signature=base64_decode((string)$anchor['signature'],true);
  $broker=base64_decode((string)get_option(
     MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  if(!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES
     ||!is_string($broker)||strlen($broker)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
     ||!sodium_crypto_sign_verify_detached($signature,$message,$broker))
    return self::v95_denied('ORIGINAL312_BROKER_ANCHOR_SIGNATURE_INVALID');
  if($approval['job_id']!==$jid ||$approval['operation_id']!==$op
     ||$approval['company']!==$company||$approval['environment']!==$env
     ||$approval['site_uuid']!==$anchor['site_uuid']
     ||$approval['anchor_nonce']!==$anchor['nonce']
     ||$approval['anchor_revision_after']!==$anchor['revision']
     ||$approval['anchor_message_sha256']!==hash('sha256',$message)
     ||!is_int($approval['anchor_revision_before'])
     ||$approval['anchor_revision_before']<0
     ||$approval['anchor_revision_after']!==$approval['anchor_revision_before']+1
     ||!is_int($approval['issued_epoch'])||!is_int($approval['expires_epoch'])
     ||$approval['issued_epoch']<time()-60||$approval['issued_epoch']>time()+10
     ||$approval['expires_epoch']<=time()
     ||$approval['expires_epoch']>$approval['issued_epoch']+120)
    return self::v95_denied('ORIGINAL313_WRITE_APPROVAL_SCOPE_OR_TIME_INVALID');
  foreach(['plan_sha256','command_sha256','anchor_message_sha256'] as $field)
   if(!self::okhex($approval[$field]??null,64))
    return self::v95_denied('ORIGINAL313_SIGNED_DIGEST_INVALID');
  $independent=base64_decode(self::V95_INDEPENDENT_313_PUBLIC_B64,true);
  $approvalSignature=base64_decode((string)$approval['signature'],true);
  if(!is_string($independent)||strlen($independent)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
     ||!is_string($approvalSignature)||strlen($approvalSignature)!==SODIUM_CRYPTO_SIGN_BYTES
     ||!sodium_crypto_sign_verify_detached(
        $approvalSignature,self::v95_approval_message($approval),$independent))
    return self::v95_denied('INDEPENDENT313_WRITE_APPROVAL_SIGNATURE_INVALID');
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$jid,false);
  $claimed=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$jid,false);
  $request=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$jid,false);
  $grant=is_array($request)?get_option(
    MAD4B_Central_SSH_Command_Intake_V1::PREFIX.
    (string)($request['grant_id']??''),false):false;
  if(!is_array($job)||!is_array($claimed)||!is_array($request)||!is_array($grant)
     ||($job['job_id']??null)!==$jid
     ||($job['operation_id']??null)!==$op
     ||($job['operation']??null)!==$op
     ||($job['broker_host_id']??null)!==self::HOST
     ||($job['grant_id']??null)!==($request['grant_id']??null)
     ||($request['request_id']??null)!==$jid
     ||($request['operation']??null)!==$op
     ||($request['company']??null)!==$company
     ||($request['site_uuid']??null)!==$anchor['site_uuid']
     ||($request['environment']??null)!==$env
     ||($request['target_host_id']??null)!=='hosting-2'
     ||($request['target_ssh_principal']??null)!==$anchor['ssh_principal']
     ||($request['command_sha256']??null)!==$approval['command_sha256']
     ||($request['status']??null)!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
     ||($request['execution_performed']??true)!==false
     ||($request['target_scope']??null)!=='host'
     ||($request['command_format']??null)!=='host.anchor.ref.v2'
     ||($job['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
     ||($job['job_type']??null)!=='signed_remote_hostinger_wpcli_v2'
     ||($job['principal_type']??null)!=='signed_host_principal_v2'
     ||($job['target_scope']??null)!=='host'
     ||($job['command_format']??null)!=='host.anchor.ref.v2'
     ||($job['company']??null)!==$company
     ||($job['environment']??null)!==$env
     ||($job['site_uuid']??null)!==$anchor['site_uuid']
     ||($job['target_host_id']??null)!=='hosting-2'
     ||($job['target_ssh_principal']??null)!==$anchor['ssh_principal']
     ||($job['plan_sha256']??null)!==$approval['plan_sha256']
     ||($job['command_sha256']??null)!==$approval['command_sha256']
     ||($job['grant_id']??null)!==$approval['grant_id']
     ||($job['executed']??true)!==false
     ||(int)($job['expires_epoch']??0)<=time()
     ||($claimed['job_id']??null)!==$jid
     ||($claimed['state']??null)!==$job['state']
     ||($claimed['production_mutation_performed']??true)!==false
     ||($job['claimed_epoch']??null)!==($claimed['claimed_epoch']??null)
     ||($claimed['host_id']??null)!==self::HOST
     ||($request['host_anchor_identity']['plan_sha256']??null)!==$approval['plan_sha256']
     ||($request['host_v2_bootstrap_only']??null)!==($grant['host_v2_bootstrap_only']??null)
     ||($request['central_principal_id']??null)!==($grant['central_principal_id']??null)
     ||($grant['operation']??null)!==$op
     ||($grant['principal_type']??null)!=='signed_host_principal_v2'
     ||($grant['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED')
    return self::v95_denied('ORIGINAL305_306_CLAIM_OR_GRANT_CHANGED');
  $slot=get_option(MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.
       $company.'_'.$env,false);
  $parent=is_array($slot)?get_option(
       MAD4B_Central_SSH_Command_Intake_V1::PREFIX.(string)($slot['grant_id']??''),false):false;
  $transport=is_array($slot)?get_option(
       MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.
       (string)($slot['transport_id']??''),false):false;
  if(!is_array($slot)||!is_array($parent)||!is_array($transport)
     ||($slot['transport_id']??null)!==($grant['transport_id']??null)
     ||($parent['grant_id']??null)!==($grant['original328_parent_grant_id']??null)
     ||($parent['operation']??null)!=='company.remote.wpcli.full'
     ||($parent['central_principal_id']??null)!==($grant['central_principal_id']??null)
     ||($parent['site_uuid']??null)!==$anchor['site_uuid']
     ||($parent['company']??null)!==$company
     ||($parent['environment']??null)!==$env
     ||($parent['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
     ||($transport['transport_id']??null)!==($slot['transport_id']??null)
     ||($transport['company']??null)!==$company
     ||($transport['environment']??null)!==$env
     ||($transport['site_uuid']??null)!==$anchor['site_uuid']
     ||($transport['host_id']??null)!=='hosting-2'
     ||($transport['state']??null)!=='REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED'
     ||!MAD4B_Original_Remote_Hostinger_328_V1::active($transport,$parent))
    return self::v95_denied('ORIGINAL328_ACTIVE_OWNER_PARENT_REQUIRED');

  $key=self::DYNAMIC_ANCHOR_PREFIX.$company.'_'.$env;
  $previous=get_option($key,false);
  $first=$op==='company.host-authority.anchor.issue'
     &&($job['authority_mode']??null)==='bootstrap'
     &&($request['host_v2_bootstrap_only']??null)===true
     &&($grant['host_v2_bootstrap_only']??null)===true;
  $revoke=$op==='company.host-authority.anchor.revoke'
     &&($job['authority_mode']??null)==='operational'
     &&($request['host_v2_bootstrap_only']??false)===false
     &&($grant['host_v2_bootstrap_only']??false)===false;
  if($first) {
    if($previous!==false||$approval['anchor_revision_before']!==0
       ||$anchor['revision']!==1||$anchor['state']!=='ACTIVE_INDEPENDENT_HOST_AUTHORITY'
       ||$anchor['revoked']!==false)
      return self::v95_denied('FIRST_ANCHOR_ALREADY_EXISTS_OR_INVALID');
    if(!add_option($key,$anchor,'',false))
      return self::v95_denied('FIRST_ANCHOR_ATOMIC_INSERT_CONFLICT');
  } elseif($revoke) {
    if(!is_array($previous)
       ||$approval['anchor_revision_before']<1
       ||$anchor['state']!=='REVOKED_INDEPENDENT_HOST_AUTHORITY'
       ||$anchor['revoked']!==true
       ||($previous['revision']??null)!==$approval['anchor_revision_before']
       ||($previous['state']??null)!=='ACTIVE_INDEPENDENT_HOST_AUTHORITY'
       ||($previous['revoked']??null)!==false)
      return self::v95_denied('CURRENT_ACTIVE_ANCHOR_REQUIRED_FOR_REVOKE');
    // Update with a SQL compare-and-swap, not a blind update_option.
    global $wpdb;
    $updated=$wpdb->query($wpdb->prepare(
       "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",
       maybe_serialize($anchor),$key,maybe_serialize($previous)));
    if($updated!==1)
      return self::v95_denied('REVOKE_ANCHOR_CONCURRENT_CHANGE');
    wp_cache_delete($key,'options');
    wp_cache_delete('notoptions','options');
  } else {
    return self::v95_denied('OPERATIONAL_ISSUE_OR_BOOTSTRAP_REVOKE_NOT_ADMITTED');
  }
  $actual=get_option($key,false);
  if(!is_array($actual)||$actual!==$anchor)
    return self::v95_denied('ORIGINAL318_ANCHOR_POSTWRITE_READBACK_FAILED');
  $readback=self::current_anchor_readback_v2($company,$env);
  if(($readback['state']??null)!==($first?'ACTIVE':'REVOKED')
    ||($readback['revision']??null)!==$anchor['revision']
    ||($readback['anchor_nonce']??null)!==$anchor['nonce']
    ||($readback['readback_verified']??false)!==true)
    return self::v95_denied('SIGNED_POSTWRITE_ANCHOR_READBACK_NOT_VALID');
  return [
    'contract'=>'mad4b.original318.anchor-reconcile-signed.v1',
    'state'=>'WORDPRESS_SIGNED_ANCHOR_POSTWRITE_VERIFIED__ROOT_313_RECEIPT_PENDING',
    'job_id'=>$jid,'company'=>$company,'environment'=>$env,
    'anchor_revision'=>$anchor['revision'],'anchor_nonce'=>$anchor['nonce'],
    'anchor_issued'=>$first,'anchor_revoked'=>$revoke,
    'original313_certified'=>false,
    'write_authorized'=>false,'grant_created'=>false,
    'fresh_wordpress_readback'=>$readback
  ];
 }

 public static function cli($args,$assoc) {
  $action=(string)($args[0]??'');
  if($action==='anchor-current-readback') {
   if(array_keys($assoc)!==['company','environment'])
     WP_CLI::error('EXACT_COMPANY_ENVIRONMENT_REQUIRED');
   WP_CLI::line(wp_json_encode(self::current_anchor_readback_v2(
     (string)$assoc['company'],(string)$assoc['environment']),JSON_UNESCAPED_SLASHES));
   return;
  }
  if($action==='anchor-reconcile-signed') {
   if(array_keys($assoc)!==['proof'])
     WP_CLI::error('EXACT_ORIGINAL312_313_PROOF_REQUIRED');
   $raw=base64_decode((string)$assoc['proof'],true);
   if(!is_string($raw)||strlen($raw)>13000||strlen($raw)<250)
     WP_CLI::error('ORIGINAL312_313_PROOF_SIZE_INVALID');
   $doc=json_decode($raw,true);
   $r=is_array($doc)?self::reconcile_signed_anchor_v95($doc):
        self::v95_denied('SIGNED_RECONCILE_JSON_INVALID');
   WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES));
   if(($r['state']??null)!==
      'WORDPRESS_SIGNED_ANCHOR_POSTWRITE_VERIFIED__ROOT_313_RECEIPT_PENDING')
     WP_CLI::halt(2);
   return;
  }
  if($action==='identity-status') {
   if(array_keys($assoc)!==['company','environment'])WP_CLI::error('EXACT_COMPANY_ENVIRONMENT_REQUIRED');
   WP_CLI::line(wp_json_encode(self::dynamic_identity_status((string)$assoc['company'],(string)$assoc['environment']),JSON_UNESCAPED_SLASHES));
   return;
  }
  if($action==='attest-identity') {
   if(array_keys($assoc)!==['proof'])WP_CLI::error('SIGNED_HOST_PROOF_REQUIRED');
   $decoded=base64_decode((string)$assoc['proof'],true);
   if(!is_string($decoded)||strlen($decoded)>5000)WP_CLI::error('SIGNED_HOST_PROOF_INVALID');
   $doc=json_decode($decoded,true);
   $result=is_array($doc)?self::attest_dynamic_identity($doc):self::deny('SIGNED_HOST_PROOF_JSON_INVALID');
   WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
   if(empty($result['identity_readback_verified']))WP_CLI::halt(2);
   return;
  }
  if (!in_array($action,['issue','complete'],true)) WP_CLI::error('ISSUE_COMPLETE_OR_IDENTITY_ACTION_REQUIRED');
  $response=$action==='complete'?self::complete($assoc):self::issue($assoc);
  WP_CLI::line(wp_json_encode($response,JSON_UNESCAPED_SLASHES));
  if (empty($response['eligible'])) WP_CLI::halt(2);
 }
 public static function boot() {
  if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b wpcli-host-grant',[__CLASS__,'cli']);
 }
}
MAD4B_Native_Host_Exact_Grant_V1::boot();
