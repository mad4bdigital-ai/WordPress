<?php
/**
 * MAD4B Original 305 remote-host binding for independent Hostinger SSH.
 * Signed broker root issuance; reuses the Original 305 grant/transport/slot.
 * This module does not activate 328 or certify 312/313.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Original_Remote_Hostinger_305_V1', false)) {
final class MAD4B_Original_Remote_Hostinger_305_V1 {
 const CONTRACT='mad4b.company-native-wpcli-enrollment.remote-hostinger.v1';
 const PRINCIPAL='signed_hostinger_ssh_wpcli';
 const DEFAULT_PRINCIPAL='signed_host_principal_v2';
 const HOST_V2_CONTRACT='mad4b.company-native-host-principal.305.v2';
 // Deliberately no WordPress actor field. Legacy self::FIELDS remains intact.
 const HOST_V2_FIELDS=['contract','company','environment','site_uuid',
  'canonical_origin','host_id','ssh_target','ssh_principal','profile_digest',
  'host_key_sha256','ssh_root_sha256','registry_sha256','policy_sha256',
  'operation','command_sha256','issued','expires','nonce'];
 const FIELDS=['contract','action','company','environment','site_uuid','canonical_origin',
  'host_id','host_principal','actor_user_id','assignment_revision',
  'host_policy_sha256','host_key_fingerprint','issued','expires','nonce',
  'native_profile_uuid','target_wordpress_root_sha256','target_database',
  'target_wp_actor_id','registry_sha256','credential_ref','remote_readback_sha256'];
 private static function deny($why):array{return ['state'=>'DENIED','reason'=>$why,'original_305_grant_issued'=>false,'original_312_ready'=>false,'original_313_certified'=>false];}
 // Central authority identity is separate from the target environment.
 // Preserve the existing pinned Central Core check without hardcoding its
 // current DB or environment inside this remote provider.
 private static function exactCentral():bool{return defined('WP_CLI')&&WP_CLI
  &&class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
  &&MAD4B_Central_Dual_Lane_Registry_V1::central();}
 private static function registeredEnvironment($company,$environment):bool {
  return is_string($company)&&preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
   &&is_string($environment)&&preg_match('/^[a-z][a-z0-9_-]{1,39}$/D',$environment)
   &&class_exists('MAD4B_Company_Environment_Registry_V2',false)
   &&is_array(MAD4B_Company_Environment_Registry_V2::target($company,$environment));
 }

 /**
  * Environment-specific Staging delegation verified independently of the
  * global company binding (which may correctly point to Production).
  * NEVER treats Staging rights as a Production grant.
  */
 private static function stagingOperationsDelegated(
   string $manager,string $company,string $environment,int $user_id
 ):bool {
  if($environment!=='staging')
    return MAD4B_Company_Portfolio_Manager_V1::has_delegation(
      $manager,$company,'operations_manage',$user_id);
  if($user_id<1
    ||!class_exists('MAD4B_Company_Environment_Portfolio_Evidence_V1',false)
    ||!class_exists('MAD4B_Company_Environment_Registry_V2',false)
    ||!class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
    ||!MAD4B_Company_Portfolio_Manager_V1::staging())return false;
  $target=MAD4B_Company_Environment_Registry_V2::target($company,'staging');
  $proof=MAD4B_Company_Environment_Portfolio_Evidence_V1::readback($company,'staging');
  if(!is_array($target)||!is_array($proof)
    ||($target['company_id']??null)!==$company
    ||($target['environment']??null)!=='staging'
    ||($proof['contract']??null)!=='mad4b.company-environment-portfolio-evidence.v1'
    ||($proof['company']??null)!==$company
    ||($proof['environment']??null)!=='staging'
    ||($proof['signed_identity_verified']??false)!==true
    ||($proof['portfolio_assignment_bound']??false)!==true
    ||($proof['operations_delegation_approved']??false)!==true
    ||($proof['grant_issued']??true)!==false
    ||($proof['production_mutation_allowed']??true)!==false
    ||($proof['site_uuid']??null)!==($target['site_uuid']??null)
    ||($proof['canonical_origin']??null)!==($target['canonical_origin']??null)
    ||!self::isHash($proof['portfolio_binding_sha256']??null))return false;
  $managerClass='MAD4B_Company_Portfolio_Manager_V1';
  $managers=get_option($managerClass::MANAGERS,[]);
  $members=get_option($managerClass::MEMBERS,[]);
  $assignments=get_option($managerClass::ASSIGNMENTS,[]);
  $memberKey=$manager.'|'.$user_id;
  $assignmentKey=$manager.'|'.$company;
  $member=is_array($members)?($members[$memberKey]??null):null;
  $owner=is_array($managers)?($managers[$manager]??null):null;
  $assignment=is_array($assignments)?($assignments[$assignmentKey]??null):null;
  return is_array($owner)&&($owner['state']??null)==='registered'
    &&is_array($member)&&($member['state']??null)==='active'
    &&is_array($assignment)&&($assignment['state']??null)==='active'
    &&($assignment['manager_id']??null)===$manager
    &&($assignment['company_code']??null)===$company
    &&($assignment['company_site_uuid']??null)===$target['site_uuid']
    &&is_string($assignment['binding_sha256']??null)
    &&hash_equals($proof['portfolio_binding_sha256'],$assignment['binding_sha256'])
    &&in_array('operations_manage',(array)($assignment['rights']??[]),true);
 }
 private static function isHash($v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
 private static function isId($v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{32}$/D',$v)===1;}
 private static function sourceScope($p):array {
  if(!self::exactCentral())return self::deny('REMOTE_ORIGINAL_305_EXACT_CENTRAL_REQUIRED');
  if(!is_array($p)||array_keys($p)!==array_merge(self::FIELDS,['signature']))
   return self::deny('REMOTE_ORIGINAL_305_SIGNED_SHAPE_INVALID');
  foreach(self::FIELDS as $key)if(!is_string($p[$key])&&!is_int($p[$key]))
   return self::deny('REMOTE_ORIGINAL_305_FIELD_TYPE_INVALID');
  $now=time();
  if($p['contract']!==self::CONTRACT||$p['action']!=='reissue'
   ||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$p['company'])
   ||$p['company']==='tours-central'
   ||!self::registeredEnvironment($p['company'],$p['environment'])
   ||!wp_is_uuid($p['site_uuid'])||!wp_is_uuid($p['native_profile_uuid'])
   ||!preg_match('/^hosting-[123]$/D',$p['host_id'])
   ||!preg_match('/^u[0-9]{9}$/D',$p['host_principal'])
   ||!is_int($p['actor_user_id'])||$p['actor_user_id']<1
   ||!is_int($p['assignment_revision'])||$p['assignment_revision']<1
   ||!is_int($p['target_wp_actor_id'])||$p['target_wp_actor_id']<1
   ||!is_int($p['issued'])||!is_int($p['expires'])
   ||$p['issued']<$now-60||$p['issued']>$now+10
   ||$p['expires']<$now||$p['expires']>$p['issued']+60
   ||!self::isId($p['nonce'])
   ||!preg_match('/^SHA256:[A-Za-z0-9+\/]{43}=?$/D',$p['host_key_fingerprint'])
   ||!preg_match('/^[A-Za-z0-9_]{4,90}$/D',$p['target_database'])
   ||$p['credential_ref']!=='vault:registry/'.$p['host_id']
   ||!self::isHash($p['target_wordpress_root_sha256'])
   ||!self::isHash($p['host_policy_sha256'])
   ||!self::isHash($p['registry_sha256'])
   ||!self::isHash($p['remote_readback_sha256']))
   return self::deny('REMOTE_ORIGINAL_305_SCOPE_OR_TIME_INVALID');
  if(!class_exists('MAD4B_Central_VPS_Broker_V1',false)
    ||!class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
    ||!class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false)
    ||!class_exists('MAD4B_Company_Environment_Registry_V2',false)
    ||!class_exists('MAD4B_Company_Execution_Resolver_V1',false)
    ||!class_exists('MAD4B_Company_Operation_Admission_V1',false)
    ||!class_exists('MAD4B_Company_Portfolio_Manager_V1',false))
   return self::deny('REMOTE_ORIGINAL_305_CORE_COMPONENT_MISSING');
  $co=$p['company'];$env=$p['environment'];
  $site=MAD4B_Company_Environment_Registry_V2::target($co,$env);
  $resolver=MAD4B_Company_Execution_Resolver_V1::discover()[$co.':'.$env]??null;
  if(!is_array($site)||!is_array($resolver)
   ||($site['company_id']??null)!==$co
   ||($site['environment']??null)!==$env
   ||($site['site_uuid']??null)!==$p['site_uuid']
   ||($site['canonical_origin']??null)!==$p['canonical_origin']
   ||($resolver['profile_uuid']??null)!==$p['native_profile_uuid']
   ||($resolver['site_uuid']??null)!==$p['site_uuid'])
   return self::deny('REMOTE_ORIGINAL_305_SITE_PROFILE_MISMATCH');
  $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
  $actor=get_user_by('id',$p['actor_user_id']);
  $assignments=get_option(MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS,[]);
  $a=is_array($assignments)?($assignments[$manager.'|'.$co]??null):null;
  if($manager===''||!$actor
   ||!user_can($actor,'manage_options')||!user_can($actor,'install_plugins')
   ||!self::stagingOperationsDelegated($manager,$co,$env,(int)$p['actor_user_id'])
   ||!is_array($a)||($a['state']??null)!=='active'
   ||($a['revision']??null)!==$p['assignment_revision']
   ||($a['company_site_uuid']??null)!==$p['site_uuid'])
   return self::deny('REMOTE_ORIGINAL_305_MANAGER_DELEGATION_REVOKED');
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode((string)$p['signature'],true);
  if(!function_exists('sodium_crypto_sign_verify_detached')
   ||!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
   ||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
   return self::deny('REMOTE_ORIGINAL_305_PINNED_SIGNER_MISSING');
  $message=implode("\n",array_map(static fn($key)=>(string)$p[$key],self::FIELDS));
  if(!sodium_crypto_sign_verify_detached($sig,$message,$pub))
   return self::deny('REMOTE_ORIGINAL_305_INVALID_BROKER_SIGNATURE');
  return ['state'=>'SIGNED_REMOTE_305_EXACT_SCOPE_VERIFIED',
   'company'=>$co,'environment'=>$env,'site_uuid'=>$p['site_uuid'],
   'canonical_origin'=>$p['canonical_origin'],
   'actor_user_id'=>$p['actor_user_id'],
   'assignment_revision'=>$p['assignment_revision'],
   'transport_host_id'=>$p['host_id'],
   'transport_principal'=>$p['host_principal'],
   'profile_uuid'=>$p['native_profile_uuid'],
   'receipt_sha256'=>hash('sha256',$message)];
 }
 /** Versioned Host Principal is the DEFAULT for new remote operations.
  * This read-only, signed admission never fabricates 305/328 grants or WP users.
  * The original 305 issuer/328 activation/312/313 must accept v2 independently
  * before it can become an execution principal. Existing v1 records are valid.
  */
 public static function defaultHostPrincipal(string $co,string $env):array {
  $site=self::registeredEnvironment($co,$env)
   ?MAD4B_Company_Environment_Registry_V2::target($co,$env):null;
  if(!self::exactCentral()||!is_array($site))return self::deny('HOST_V2_EXACT_ENROLLED_CENTRAL_TARGET_REQUIRED');
  $i=class_exists('MAD4B_Native_Host_Exact_Grant_V1',false)
    ?MAD4B_Native_Host_Exact_Grant_V1::dynamic_identity_status($co,$env):[];
  // The earlier v55 signer has no independent host-policy anchor; v59+ is
  // required before any v2 signed proof can count toward release eligibility.
  $anchored=class_exists('MAD4B_Native_Host_Exact_Grant_V1',false)
    &&method_exists('MAD4B_Native_Host_Exact_Grant_V1','independent_anchor_live_v2');
  return ['contract'=>self::HOST_V2_CONTRACT,'principal_type'=>self::DEFAULT_PRINCIPAL,
   'default_for_new_operations'=>true,'legacy_actor_v1_supported'=>true,
   'company'=>$co,'environment'=>$env,'site_uuid'=>$site['site_uuid'],
   'canonical_origin'=>$site['canonical_origin'],
   'signed_host_identity_verified'=>$anchored&&($i['identity_readback_verified']??false)===true,
   'state'=>$anchored&&($i['identity_readback_verified']??false)===true
       ?'SIGNED_HOST_IDENTITY_AVAILABLE__ORIGINAL_305_V2_GRANT_REQUIRED'
       :'HOST_PRINCIPAL_DEFAULT__ORIGINAL_SIGNED_AUTHORITY_PENDING',
   'actor_user_id_required'=>false,'grant_created'=>false,
   'host_write_authorized'=>false,'original_328_certified'=>false,
   'original_312_313_certified'=>false];
 }
 public static function verifyHostPrincipalV2(array $p):array {
  if(array_keys($p)!==array_merge(self::HOST_V2_FIELDS,['signature']))
    return self::deny('HOST_V2_SIGNED_SHAPE_INVALID');
  foreach(self::HOST_V2_FIELDS as $field)
    if(!is_string($p[$field])&&!is_int($p[$field]))
      return self::deny('HOST_V2_FIELD_TYPE_INVALID');
  $co=$p['company'];$env=$p['environment'];$now=time();
  if(!self::exactCentral()||$p['contract']!==self::HOST_V2_CONTRACT
    ||$p['operation']!=='company.remote.wpcli.full'
    ||!self::registeredEnvironment($co,$env)
    ||$co==='tours-central'
    ||!wp_is_uuid($p['site_uuid'])
    ||!preg_match('/^hosting-[123]$/D',$p['ssh_target'])
    ||!preg_match('/^u[0-9]{9}$/D',$p['ssh_principal'])
    ||$p['host_id']!=='srv2030908'
    ||!preg_match('/^[a-f0-9]{32}$/D',$p['nonce'])
    ||!is_int($p['issued'])||!is_int($p['expires'])
    ||$p['issued']<$now-60||$p['issued']>$now+10
    ||$p['expires']<$now||$p['expires']>$p['issued']+60)
    return self::deny('HOST_V2_EXACT_SCOPE_OR_TIME_INVALID');
  foreach(['profile_digest','host_key_sha256','ssh_root_sha256',
    'registry_sha256','policy_sha256','command_sha256'] as $key)
    if(!self::isHash($p[$key]))return self::deny('HOST_V2_SHA256_INVALID');
  $site=MAD4B_Company_Environment_Registry_V2::target($co,$env);
  if(!is_array($site)||($site['company_id']??null)!==$co
    ||($site['environment']??null)!==$env
    ||($site['site_uuid']??null)!==$p['site_uuid']
    ||($site['canonical_origin']??null)!==$p['canonical_origin']
    ||($site['identity_evidence']['profile_digest']??null)!==$p['profile_digest'])
     return self::deny('HOST_V2_COMPANY_SITE_IDENTITY_MISMATCH');
  if(!class_exists('MAD4B_Central_VPS_Broker_V1',false))return self::deny('HOST_V2_ORIGINAL_BROKER_REQUIRED');
  $public=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $signature=base64_decode((string)$p['signature'],true);
  if(!is_string($public)||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    ||!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES)
     return self::deny('HOST_V2_ORIGINAL_BROKER_PUBLIC_KEY_INVALID');
  $body=implode("\n",array_map(static fn($field)=>(string)$p[$field],self::HOST_V2_FIELDS));
  if(!sodium_crypto_sign_verify_detached($signature,$body,$public))
     return self::deny('HOST_V2_BROKER_SIGNATURE_INVALID');
  // A signature alone is NOT an independent Host Authority policy attestation.
  // v59's trusted anchor and the original 305/328/312/313 grants remain needed.
  $status=self::defaultHostPrincipal($co,$env);
  if(($status['signed_host_identity_verified']??false)!==true
    ||!MAD4B_Native_Host_Exact_Grant_V1::independent_anchor_live_v2($p))
    return self::deny('HOST_V2_INDEPENDENT_SIGNED_HOST_AUTHORITY_REQUIRED');
  return ['contract'=>self::HOST_V2_CONTRACT,
    'state'=>'HOST_V2_SIGNATURE_AND_SITE_VERIFIED__SIGNED_GRANT_REQUIRED',
    'verified'=>true,'principal_type'=>self::DEFAULT_PRINCIPAL,
    'company'=>$co,'environment'=>$env,'site_uuid'=>$p['site_uuid'],
    'command_sha256'=>$p['command_sha256'],
    'signature_sha256'=>hash('sha256',$body),
    'actor_user_id_required'=>false,'grant_created'=>false,
    'host_write_authorized'=>false,'original_328_certified'=>false,
    'original_312_313_certified'=>false];
 }
 public static function readback(string $co,string $env,array $slot,array $t,array $g):array {
  $ref='MAD4B_Company_Native_WPCLI_Enrollment_V1';
  $site=MAD4B_Company_Environment_Registry_V2::target($co,$env);
  $profile=MAD4B_Company_Execution_Resolver_V1::discover()[$co.':'.$env]??null;
  $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
  $uid=(int)($g['central_principal_id']??0);
  $actor=$uid>0?get_user_by('id',$uid):false;
  $assignments=get_option(MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS,[]);
  $assignment=is_array($assignments)?($assignments[$manager.'|'.$co]??null):null;
  if(!is_array($site)||!is_array($profile)
    ||($site['company_id']??null)!==$co||($site['environment']??null)!==$env
    ||($site['site_uuid']??null)!==($slot['site_uuid']??null)
    ||($site['canonical_origin']??null)!==($slot['canonical_origin']??null)
    ||($profile['profile_uuid']??null)!==($t['remote_native_profile_uuid']??null)
    ||($profile['site_uuid']??null)!==($slot['site_uuid']??null)
    ||($t['company']??null)!==$co||($g['company']??null)!==$co
    ||($t['environment']??null)!==$env||($g['environment']??null)!==$env
    ||($t['site_uuid']??null)!==($slot['site_uuid']??null)
    ||($g['site_uuid']??null)!==($slot['site_uuid']??null)
    ||($t['canonical_origin']??null)!==($slot['canonical_origin']??null)
    ||($g['canonical_origin']??null)!==($slot['canonical_origin']??null)
    ||($t['principal_type']??null)!==self::PRINCIPAL
    ||($g['principal_type']??null)!==self::PRINCIPAL
    ||($t['principal_id']??null)!=='mad4b:hostinger:'.$t['host_id']
    ||($g['principal_id']??null)!==($t['principal_id']??null)
    ||!preg_match('/^hosting-[123]$/D',(string)($t['host_id']??''))
    ||!preg_match('/^u[0-9]{9}$/D',(string)($t['host_principal']??''))
    ||($t['credential_ref']??null)!=='vault:registry/'.$t['host_id']
    ||!preg_match('/^SHA256:[A-Za-z0-9+\/]{43}=?$/D',(string)($t['host_key_fingerprint']??''))
    ||!self::isHash($t['remote_host_policy_sha256']??null)
    ||!self::isHash($t['remote_registry_sha256']??null)
    ||!self::isHash($t['remote_wordpress_root_sha256']??null)
    ||!self::isHash($t['remote_readback_sha256']??null)
    ||!self::isHash($t['remote_305_enrollment_receipt_sha256']??null)
    ||!is_int($t['remote_wp_actor_id']??null)||$t['remote_wp_actor_id']<1
    ||!preg_match('/^[A-Za-z0-9_]{4,90}$/D',(string)($t['remote_target_database']??''))
    ||($t['host_id']??null)!==($g['target_host_id']??null)
    ||($t['host_principal']??null)!==($g['target_ssh_principal']??null)
    ||($t['transport_id']??null)!==($slot['transport_id']??null)
    ||($g['grant_id']??null)!==($slot['grant_id']??null)
    ||($t['full_wpcli_grant_id']??null)!==($g['grant_id']??null)
    ||($g['transport_id']??null)!==($t['transport_id']??null)
    ||($g['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
    ||($g['operation']??null)!=='company.remote.wpcli.full'
    ||($g['grant_mode']??null)!=='persistent'
    ||!array_key_exists('expires_epoch',$g)||$g['expires_epoch']!==null
    ||($t['capabilities']??null)!==['wpcli.full']
    ||($t['full_wpcli_pending_native_enrollment']??false)!==true
    ||!in_array(($t['state']??null),['PENDING_INDEPENDENT_SSH_VERIFICATION','REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED'],true)
    ||($t['central_principal_id']??null)!==($g['central_principal_id']??null)
    ||($t['assignment_revision']??null)!==($g['assignment_revision']??null)
    ||!is_array($assignment)||($assignment['state']??null)!=='active'
    ||($assignment['revision']??null)!==($g['assignment_revision']??null)
    ||($assignment['company_site_uuid']??null)!==($slot['site_uuid']??null)
    ||!$actor||!user_can($actor,'manage_options')||!user_can($actor,'install_plugins')
    ||!self::stagingOperationsDelegated($manager,$co,$env,$uid))
   return self::deny('REMOTE_ORIGINAL_305_PAIR_REVOKED_OR_CHANGED');
  return ['state'=>'ORIGINAL_305_PERSISTENT_PAIR_ENROLLED',
   'company'=>$co,'environment'=>$env,'site_uuid'=>$slot['site_uuid'],
   'transport_state'=>$t['state'],'host_authority_verified'=>($t['host_authority_verified']??false)===true,
   'full_wpcli_active'=>($t['full_wpcli_active']??false)===true,
   'persistent_grant_present'=>true,'grant_reused'=>true,
   'grant_issued'=>false,'execution_performed'=>false,
   'remote_hostinger'=>true];
 }
 public static function reissue(array $proof):array {
  $v=self::sourceScope($proof);
  if(($v['state']??null)!=='SIGNED_REMOTE_305_EXACT_SCOPE_VERIFIED')return $v;
  $co=$v['company'];$env=$v['environment'];
  $n='MAD4B_Company_Native_WPCLI_Enrollment_V1';
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  $slotName=$n::SLOT.$co.'_'.$env;
  global $wpdb;
  $lock='mad4b-305-rotation-'.substr(hash('sha256',$co.':'.$env),0,32);
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,4)',$lock))!==1)
   return self::deny('REMOTE_ORIGINAL_305_ROTATION_LOCK_BUSY');
  try {
   $old=get_option($slotName,false);
   if(!is_array($old)||($old['site_uuid']??null)!==$v['site_uuid']
     ||($old['company']??null)!==$co||($old['environment']??null)!==$env
     ||($old['canonical_origin']??null)!==$v['canonical_origin']
     ||!self::isId($old['transport_id']??null)||!self::isId($old['grant_id']??null))
    return self::deny('REMOTE_ORIGINAL_305_OLD_PAIR_NOT_ENROLLED');
   $oldRead=$n::readback($co,$env);
   $oldT=get_option($c::TRANSPORT_PREFIX.$old['transport_id'],false);
   $oldG=get_option($c::PREFIX.$old['grant_id'],false);
   if(($oldRead['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
     ||!is_array($oldT)||!is_array($oldG)
     ||($oldT['host_id']??null)!=='srv2030908'
     ||($oldT['host_principal']??null)!=='root'
     ||($oldT['principal_type']??null)!=='signed_vps_wpcli'
     ||($oldG['principal_type']??null)!=='signed_vps_wpcli'
     ||($oldG['target_host_id']??null)!=='srv2030908'
     ||($oldG['target_ssh_principal']??null)!=='root'
     ||($oldT['state']??null)!=='PENDING_INDEPENDENT_SSH_VERIFICATION'
     ||($oldG['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED')
    return self::deny('REMOTE_ORIGINAL_305_OLD_LOCAL_PAIR_NOT_ELIGIBLE');
   if(!add_option($n::NONCE.$proof['nonce'],['company'=>$co,'epoch'=>time()],'',false))
    return self::deny('REMOTE_ORIGINAL_305_NONCE_REPLAY');
   $tid=bin2hex(random_bytes(16));$gid=bin2hex(random_bytes(16));
   $base=['contract'=>'mad4b.central-ssh-command-intake.v1',
     'company'=>$co,'environment'=>$env,'site_uuid'=>$v['site_uuid'],
     'canonical_origin'=>$v['canonical_origin'],
     'assignment_revision'=>$v['assignment_revision'],
     'central_principal_id'=>$v['actor_user_id'],
     'principal_type'=>self::PRINCIPAL,
     'principal_id'=>'mad4b:hostinger:'.$v['transport_host_id']];
   $t=$base+['transport_id'=>$tid,'mode'=>'persistent_ssh_configuration',
     'host_id'=>$v['transport_host_id'],
     'host_principal'=>$v['transport_principal'],
     'host_key_fingerprint'=>$proof['host_key_fingerprint'],
     'credential_ref'=>$proof['credential_ref'],
     'state'=>'PENDING_INDEPENDENT_SSH_VERIFICATION',
     'capabilities'=>['wpcli.full'],'full_wpcli_grant_id'=>$gid,
     'full_wpcli_pending_native_enrollment'=>true,
     'full_wpcli_active'=>false,'host_authority_verified'=>false,
     'ssh_secret_stored_in_wordpress'=>false,
     'remote_native_profile_uuid'=>$proof['native_profile_uuid'],
     'remote_host_policy_sha256'=>$proof['host_policy_sha256'],
     'remote_registry_sha256'=>$proof['registry_sha256'],
     'remote_wordpress_root_sha256'=>$proof['target_wordpress_root_sha256'],
     'remote_target_database'=>$proof['target_database'],
     'remote_wp_actor_id'=>$proof['target_wp_actor_id'],
     'remote_readback_sha256'=>$proof['remote_readback_sha256'],
     'remote_305_enrollment_receipt_sha256'=>$v['receipt_sha256']];
   $g=$base+['grant_id'=>$gid,'operation'=>'company.remote.wpcli.full',
     'target_host_id'=>$v['transport_host_id'],
     'target_ssh_principal'=>$v['transport_principal'],
     'grant_mode'=>'persistent','transport_id'=>$tid,'expires_epoch'=>null,
     'issued_epoch'=>time(),'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED',
     'requires_fresh_host_auth_for_each_command'=>true,
     'execution_authorized'=>false,'executed'=>false];
   $newSlot=['transport_id'=>$tid,'grant_id'=>$gid,'company'=>$co,
     'environment'=>$env,'site_uuid'=>$v['site_uuid'],
     'canonical_origin'=>$v['canonical_origin']];
   $tKey=$c::TRANSPORT_PREFIX.$tid;$gKey=$c::PREFIX.$gid;
   if(!add_option($tKey,$t,'',false))return self::deny('REMOTE_ORIGINAL_305_NEW_TRANSPORT_PERSIST_FAILED');
   if(!add_option($gKey,$g,'',false)){
    delete_option($tKey);return self::deny('REMOTE_ORIGINAL_305_NEW_GRANT_PERSIST_FAILED');
   }
   if(!update_option($slotName,$newSlot,false)){
    delete_option($gKey);delete_option($tKey);
    return self::deny('REMOTE_ORIGINAL_305_NEW_SLOT_PERSIST_FAILED');
   }
   $read=$n::readback($co,$env);
   if(($read['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
       ||($read['remote_hostinger']??false)!==true){
    update_option($slotName,$old,false);delete_option($gKey);delete_option($tKey);
    return self::deny('REMOTE_ORIGINAL_305_NEW_PAIR_READBACK_FAILED');
   }
   $revokedG=$oldG;$revokedG['state']='CENTRAL_OPERATION_SCOPE_REVOKED';
   $revokedG['execution_authorized']=false;$revokedG['revoked_for_reissue_epoch']=time();
   $revokedT=$oldT;$revokedT['state']='REVOKED_FOR_NATIVE_ACCESS_GRANT_REISSUE';
   $revokedT['full_wpcli_active']=false;$revokedT['host_authority_verified']=false;
   $revokedT['revoked_for_reissue_epoch']=time();
   $oldGKey=$c::PREFIX.$old['grant_id'];$oldTKey=$c::TRANSPORT_PREFIX.$old['transport_id'];
   update_option($oldGKey,$revokedG,false);
   update_option($oldTKey,$revokedT,false);
   $actualG=get_option($oldGKey,false);$actualT=get_option($oldTKey,false);
   if(!is_array($actualG)||!is_array($actualT)
     ||($actualG['state']??null)!=='CENTRAL_OPERATION_SCOPE_REVOKED'
     ||($actualT['state']??null)!=='REVOKED_FOR_NATIVE_ACCESS_GRANT_REISSUE'){
    // Revert this reissue if old scope revocation could not be proven.
    update_option($oldGKey,$oldG,false);update_option($oldTKey,$oldT,false);
    update_option($slotName,$old,false);delete_option($gKey);delete_option($tKey);
    return self::deny('REMOTE_ORIGINAL_305_OLD_SCOPE_REVOCATION_NOT_CONFIRMED');
   }
   $after=$n::readback($co,$env);
   if(($after['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
     ||($after['remote_hostinger']??false)!==true)
    return self::deny('REMOTE_ORIGINAL_305_FINAL_INDEPENDENT_READBACK_FAILED');
   $after['grant_issued']=true;$after['legacy_grant_revoked']=true;
   $after['grant_reissued_same_site']=true;
   $after['original_312_ready']=false;$after['original_313_certified']=false;
   return $after;
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 public static function cli($args,$assoc):void {
  if(in_array(($args[0]??''),['','plan','principal'],true)
    &&array_keys($assoc)===['company','environment']){
   WP_CLI::line(wp_json_encode(self::defaultHostPrincipal((string)$assoc['company'],
      (string)$assoc['environment']),JSON_UNESCAPED_SLASHES));return;
  }
  if(($args[0]??'')==='verify-host-v2'&&array_keys($assoc)===['proof']){
   $raw=base64_decode((string)$assoc['proof'],true);
   if(!is_string($raw)||strlen($raw)>7200)WP_CLI::error('HOST_V2_EXACT_SIGNED_PROOF_REQUIRED');
   $doc=json_decode($raw,true);$result=is_array($doc)
     ?self::verifyHostPrincipalV2($doc):self::deny('HOST_V2_PROOF_JSON_INVALID');
   WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
   if(($result['verified']??false)!==true)WP_CLI::halt(2);
   return;
  }
  if(($args[0]??'')==='status'&&array_keys($assoc)===['company','environment']){
   $co=(string)$assoc['company'];$env=(string)$assoc['environment'];
   WP_CLI::line(wp_json_encode(MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($co,$env),JSON_UNESCAPED_SLASHES));return;
  }
  if(($args[0]??'')!=='reissue'||array_keys($assoc)!==['proof'])
   WP_CLI::error('REMOTE_ORIGINAL_305_EXACT_SIGNED_REISSUE_REQUIRED');
  $raw=base64_decode((string)$assoc['proof'],true);
  if(!is_string($raw)||strlen($raw)>7500)WP_CLI::error('REMOTE_ORIGINAL_305_SIGNED_PROOF_INVALID');
  $p=json_decode($raw,true);$r=is_array($p)?self::reissue($p):self::deny('PROOF_JSON_INVALID');
  if(($r['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED')
   WP_CLI::error($r['reason']??'REMOTE_ORIGINAL_305_REISSUE_DENIED');
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b company-remote-hostinger-305',[__CLASS__,'cli']);
 }
}
MAD4B_Original_Remote_Hostinger_305_V1::boot();
}
