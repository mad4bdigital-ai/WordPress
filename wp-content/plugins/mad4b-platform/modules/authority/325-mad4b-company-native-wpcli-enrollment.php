<?php
/**
 * Plugin Name: MAD4B Signed Company Native WP-CLI Enrollment
 * Description: Original 305 scoped persistent company WP-CLI registration using signed native VPS identity (not OAuth or WP user impersonation).
 * Version: 0.1.0-candidate
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Native_WPCLI_Enrollment_V1 {
 const CONTRACT='mad4b.company-native-wpcli-enrollment.v1';
 const NONCE='mad4b_company_native_wpcli_nonce_v1_';
 const SLOT='mad4b_company_native_wpcli_slot_v1_';
 const FIELDS=['contract','action','company','environment','site_uuid','canonical_origin',
  'host_id','host_principal','actor_user_id','assignment_revision',
  'host_policy_sha256','host_key_fingerprint','issued','expires','nonce'];
 private static function fail(string $error):array {
  return ['contract'=>self::CONTRACT,'state'=>'DENIED','reason'=>$error,
   'grant_issued'=>false,'execution_performed'=>false];
 }
 private static function target(string $company,string $env) {
  if (!class_exists('MAD4B_Company_Environment_Registry_V2',false))return null;
  return MAD4B_Company_Environment_Registry_V2::target($company,$env);
 }
 public static function verify(array $proof):array {
  if (array_keys($proof)!==array_merge(self::FIELDS,['signature']))
   return self::fail('SIGNED_ENVELOPE_SHAPE_REQUIRED');
  foreach(self::FIELDS as $f)if(!is_string($proof[$f])&&!is_int($proof[$f]))
   return self::fail('SIGNED_ENVELOPE_FIELD_TYPE_INVALID');
  if (!function_exists('wp_get_environment_type')
   || wp_get_environment_type()!=='staging'
   || !defined('DB_NAME') || DB_NAME!=='etg_commerce_staging'
   || untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
   return self::fail('EXACT_CENTRAL_WORDPRESS_REQUIRED');
  $company=$proof['company'];$env=$proof['environment'];
  $uid=$proof['actor_user_id'];$revision=$proof['assignment_revision'];
  $now=time();
  if ($proof['contract']!==self::CONTRACT || !in_array($proof['action'],['enroll','rotate','reissue'],true)
   || !is_string($company) || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
   || $company==='tours-central'
   || (!is_string($env)||!preg_match('/^[a-z][a-z0-9_-]{1,31}$/D',$env))
   || !is_int($uid)||$uid<1||$uid>2147483647
   || !is_int($revision)||$revision<1
   || !is_int($proof['issued'])||!is_int($proof['expires'])
   || $proof['issued']<$now-60||$proof['issued']>$now+10
   || $proof['expires']<$now||$proof['expires']>$proof['issued']+60
   || !preg_match('/^[a-f0-9]{32}$/D',(string)$proof['nonce'])
   || !preg_match('/^[a-f0-9]{64}$/D',(string)$proof['host_policy_sha256'])
   || !preg_match('/^SHA256:[A-Za-z0-9+\/]{43}=?$/D',(string)$proof['host_key_fingerprint'])
   || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{2,79}$/D',(string)$proof['host_id'])
   || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D',(string)$proof['host_principal']))
   return self::fail('SCOPE_OR_TIME_INVALID');
  $site=self::target($company,$env);
  if (!is_array($site) || !wp_is_uuid((string)($site['site_uuid']??''))
   || ($site['site_uuid']??'')!==$proof['site_uuid']
   || ($site['canonical_origin']??'')!==$proof['canonical_origin']
   || ($site['environment']??'')!==$env
   || ($site['company_id']??'')!==$company)
   return self::fail('ORIGINAL_COMPANY_SITE_BINDING_REQUIRED');
  if (!class_exists('MAD4B_Native_VPS_Principal_V1',false)
   || !class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
   || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
   || !class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
   || !class_exists('MAD4B_Company_Operation_Admission_V1',false))
   return self::fail('ORIGINAL_CORE_COMPONENT_MISSING');
  $root=MAD4B_Native_VPS_Principal_V1::existing();
  $scope=MAD4B_Native_VPS_Principal_V1::scope();
  if (!is_array($root)||!MAD4B_Native_VPS_Principal_V1::ledger($root,$scope)
   || ($root['policy_sha256']??null)!==$proof['host_policy_sha256']
   || ($root['host_key_fingerprint']??null)!==$proof['host_key_fingerprint']
   || $proof['host_id']!==MAD4B_Native_VPS_Principal_V1::HOST
   || $proof['host_principal']!=='root')
   return self::fail('ORIGINAL_SIGNED_VPS_ROOT_PRINCIPAL_REQUIRED');
  $m=MAD4B_Company_Operation_Admission_V1::manager_id();
  $u=get_user_by('id',$uid);
  $authorized=$env==='production'
   ? MAD4B_Company_Portfolio_Manager_V1::has_production_delegation(
       $m,$company,'operations_manage',$uid)
   : MAD4B_Company_Portfolio_Manager_V1::has_delegation(
       $m,$company,'operations_manage',$uid);
  if ($m===''||!$u||!user_can($u,'manage_options')
   || !user_can($u,'install_plugins')||!$authorized)
   return self::fail('EXACT_ENROLLED_COMPANY_MANAGER_REQUIRED');
  // Exact environment-scoped assignment is authoritative for Production.
  // Legacy manager|company assignments remain Staging-only history.
  $a=$env==='production'
    ? MAD4B_Company_Portfolio_Manager_V1::production_assignment_status($m,$company)
    : (get_option(MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS,[])[$m.'|'.$company]??null);
  $authorized=$env==='production'
    ? MAD4B_Company_Portfolio_Manager_V1::has_production_delegation(
       $m,$company,'operations_manage',$uid)
    : MAD4B_Company_Portfolio_Manager_V1::has_delegation(
       $m,$company,'operations_manage',$uid);
  if (!$authorized||!is_array($a)||($a['state']??'')!=='active'
   || ($a['revision']??null)!==$revision
   || ($a['company_site_uuid']??null)!==$proof['site_uuid'])
   return self::fail('COMPANY_MANAGER_ASSIGNMENT_REVISION_CHANGED');
  // A WordPress user ID is the actor, not a credential. The independently
  // pinned Ed25519 broker signer authenticates the VPS workload.
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode((string)$proof['signature'],true);
  if (!function_exists('sodium_crypto_sign_verify_detached')
   || !is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
   || !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
   return self::fail('ORIGINAL_NATIVE_BROKER_SIGNER_MISSING');
  $signed=implode("\n",array_map(static fn($field)=>(string)$proof[$field],self::FIELDS));
  if (!sodium_crypto_sign_verify_detached($sig,$signed,$pub))
   return self::fail('INVALID_ORIGINAL_NATIVE_SIGNATURE');
  return ['contract'=>self::CONTRACT,'state'=>'ORIGINAL_NATIVE_COMPANY_SCOPE_VERIFIED',
   'company'=>$company,'environment'=>$env,'site_uuid'=>$proof['site_uuid'],
   'canonical_origin'=>$proof['canonical_origin'],'host_id'=>$proof['host_id'],
   'host_principal'=>$proof['host_principal'],'actor_user_id'=>$uid,
   'assignment_revision'=>$revision,
   'host_key_fingerprint'=>$proof['host_key_fingerprint'],
   'grant_issued'=>false,'execution_performed'=>false];
 }
 private static function slot(string $company,string $env):string {
  return self::SLOT.$company.'_'.$env;
 }
 public static function readback(string $company,string $env):array {
  $r=get_option(self::slot($company,$env),false);
  if (!is_array($r))return self::fail('NOT_ENROLLED');
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  $t=get_option($c::TRANSPORT_PREFIX.(string)($r['transport_id']??''),false);
  $g=get_option($c::PREFIX.(string)($r['grant_id']??''),false);
  if(is_array($t) && ($t['principal_type']??null)==='signed_hostinger_ssh_wpcli'){
   if(!class_exists('MAD4B_Original_Remote_Hostinger_305_V1',false) || !is_array($g))
    return self::fail('REMOTE_ORIGINAL_305_PROVIDER_UNAVAILABLE');
   return MAD4B_Original_Remote_Hostinger_305_V1::readback($company,$env,$r,$t,$g);
  }
  if (!is_array($t)||!is_array($g)
   || ($g['state']??'')!=='CENTRAL_OPERATION_SCOPE_GRANTED'
   || ($g['grant_mode']??'')!=='persistent'
   || !array_key_exists('expires_epoch',$g)
   || $g['expires_epoch']!==null
   || ($g['operation']??'')!=='company.remote.wpcli.full'
   || ($g['transport_id']??null)!==($r['transport_id']??null)
   || ($t['full_wpcli_grant_id']??null)!==($r['grant_id']??null)
   || ($t['capabilities']??null)!==['wpcli.full']
   || ($t['company']??null)!==$company || ($g['company']??null)!==$company
   || ($t['environment']??null)!==$env || ($g['environment']??null)!==$env
   || ($t['host_id']??null)!==MAD4B_Native_VPS_Principal_V1::HOST
   || ($t['host_principal']??null)!=='root'
   || ($t['credential_ref']??null)!=='vault:registry/local-srv2030908'
   || ($g['target_host_id']??null)!==($t['host_id']??null)
   || ($g['target_ssh_principal']??null)!==($t['host_principal']??null)
   || ($t['full_wpcli_pending_native_enrollment']??false)!==true
   || ($t['site_uuid']??null)!==($r['site_uuid']??null)
   || ($g['site_uuid']??null)!==($r['site_uuid']??null)
   || ($g['central_principal_id']??null)!==($t['central_principal_id']??null)
   || ($g['assignment_revision']??null)!==($t['assignment_revision']??null)
   || ($g['canonical_origin']??null)!==($r['canonical_origin']??null)
   || ($t['canonical_origin']??null)!==($r['canonical_origin']??null))
   return self::fail('ORIGINAL_305_PAIR_REVOKED_OR_CHANGED');
  $site=self::target($company,$env);
  if (!is_array($site)||($site['site_uuid']??null)!==($r['site_uuid']??null)
   || ($site['canonical_origin']??null)!==($r['canonical_origin']??null))
   return self::fail('COMPANY_SITE_BINDING_DRIFT');
  $uid=(int)($g['central_principal_id']??0);
  $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
  $user=$uid>0?get_user_by('id',$uid):false;
  $a=$env==='production'
    ? MAD4B_Company_Portfolio_Manager_V1::production_assignment_status($manager,$company)
    : (get_option(MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS,[])[$manager.'|'.$company]??null);
  $authorized=$env==='production'
    ? MAD4B_Company_Portfolio_Manager_V1::has_production_delegation(
        $manager,$company,'operations_manage',$uid)
    : MAD4B_Company_Portfolio_Manager_V1::has_delegation(
        $manager,$company,'operations_manage',$uid);
  if(!$user||!user_can($user,'manage_options')||
     !user_can($user,'install_plugins')||!$authorized||
     !is_array($a)||($a['revision']??null)!==($g['assignment_revision']??null))
     return self::fail('ORIGINAL_COMPANY_DELEGATION_REVOKED_OR_CHANGED');
  return ['contract'=>self::CONTRACT,'state'=>'ORIGINAL_305_PERSISTENT_PAIR_ENROLLED',
   'company'=>$company,'environment'=>$env,'site_uuid'=>$r['site_uuid'],
   'transport_state'=>$t['state']??null,
   'host_authority_verified'=>($t['host_authority_verified']??false)===true,
   'full_wpcli_active'=>($t['full_wpcli_active']??false)===true,
   'persistent_grant_present'=>true,
   'grant_reused'=>true,
   'grant_issued'=>false,'execution_performed'=>false];
 }
 public static function enroll(array $proof):array {
  $v=self::verify($proof);
  if (($v['state']??null)!=='ORIGINAL_NATIVE_COMPANY_SCOPE_VERIFIED')return $v;
  $company=$v['company'];$env=$v['environment'];
  $old=get_option(self::slot($company,$env),false);
  $reissuing=$proof['action']==='reissue';
  $rotating=$proof['action']==='rotate'||$reissuing;
  if ($old!==false && !$rotating)return self::readback($company,$env);
  if ($old===false && $rotating)return self::fail('ORIGINAL_REISSUE_REQUIRES_PREVIOUS_SITE_SLOT');
  if ($reissuing){
   // Same-site reissuance requires the original currently bound identity.
   // It never creates a second independent scope or inherits old credentials.
   if (!is_array($old)||($old['company']??'')!==$company
    ||($old['environment']??'')!==$env
    ||($old['site_uuid']??'')!==$v['site_uuid']
    ||($old['canonical_origin']??'')!==$v['canonical_origin']
    ||!preg_match('/^[a-f0-9]{32}$/D',(string)($old['grant_id']??''))
    ||!preg_match('/^[a-f0-9]{32}$/D',(string)($old['transport_id']??'')))
     return self::fail('REISSUE_EXACT_ORIGINAL_SITE_IDENTITY_REQUIRED');
   $oc='MAD4B_Central_SSH_Command_Intake_V1';
   $oldgrant=get_option($oc::PREFIX.$old['grant_id'],false);
   $oldtransport=get_option($oc::TRANSPORT_PREFIX.$old['transport_id'],false);
   if(!is_array($oldgrant)||!is_array($oldtransport)
    ||($oldgrant['transport_id']??'')!==$old['transport_id']
    ||($oldgrant['company']??'')!==$company
    ||($oldgrant['site_uuid']??'')!==$v['site_uuid']
    ||($oldgrant['operation']??'')!=='company.remote.wpcli.full'
    ||($oldtransport['site_uuid']??'')!==$v['site_uuid'])
     return self::fail('REISSUE_ORIGINAL_GRANT_TRANSPORT_MISMATCH');
  }
  if ($rotating&&!$reissuing){
   // A fresh native broker signature authorizes replacement rotation, never a legacy grant.
   // Only the exact company-replacement history can identify the old slot.
   if (!class_exists('MAD4B_Company_Site_Replacement_V1',false))
    return self::fail('ORIGINAL_REPLACEMENT_REGISTRY_REQUIRED');
   $replacement=MAD4B_Company_Site_Replacement_V1::records()[$company]??null;
   $history=is_array($replacement)?($replacement['history']??[]):[];
   $prior=is_array($history)&&isset($history[0])&&is_array($history[0])?$history[0]:[];
   if(!is_array($old)||($old['company']??null)!==$company
    || ($old['environment']??null)!==$env
    || ($old['site_uuid']??null)!==($prior['site_uuid']??null)
    || ($old['canonical_origin']??null)!==($prior['canonical_origin']??null)
    || ($old['site_uuid']??null)===$v['site_uuid']
    || !in_array($replacement['state']??'', ['PENDING_NATIVE_305','PENDING_RECONCILE'],true)
    || ($replacement['native_site_profile_uuid']??null)!==$v['site_uuid']
    || ($replacement['native_305_verified']??true)!==false)
    return self::fail('ORIGINAL_REPLACEMENT_HISTORY_OR_OLD_SLOT_MISMATCH');
  }
  if (!add_option(self::NONCE.$proof['nonce'],['company'=>$company,'epoch'=>time()],'',false))
   return self::fail('SIGNED_NONCE_REPLAY');
  $tid=bin2hex(random_bytes(16));$gid=bin2hex(random_bytes(16));
  $base=['contract'=>'mad4b.central-ssh-command-intake.v1',
   'company'=>$company,'environment'=>$env,
   'site_uuid'=>$v['site_uuid'],'canonical_origin'=>$v['canonical_origin'],
   'assignment_revision'=>$v['assignment_revision'],
   'central_principal_id'=>$v['actor_user_id'],
   'principal_type'=>'signed_vps_wpcli',
   'principal_id'=>MAD4B_Native_VPS_Principal_V1::PRINCIPAL];
  $t=$base+['transport_id'=>$tid,'mode'=>'persistent_ssh_configuration',
   'host_id'=>$v['host_id'],'host_principal'=>$v['host_principal'],
   'host_key_fingerprint'=>$v['host_key_fingerprint'],
   'credential_ref'=>'vault:registry/local-srv2030908',
   'state'=>'PENDING_INDEPENDENT_SSH_VERIFICATION',
   'capabilities'=>['wpcli.full'],'full_wpcli_grant_id'=>$gid,
   'full_wpcli_pending_native_enrollment'=>true,
   'full_wpcli_active'=>false,'host_authority_verified'=>false,
   'ssh_secret_stored_in_wordpress'=>false];
  $g=$base+['grant_id'=>$gid,'operation'=>'company.remote.wpcli.full',
   'target_host_id'=>$v['host_id'],'target_ssh_principal'=>$v['host_principal'],
   'grant_mode'=>'persistent','transport_id'=>$tid,'expires_epoch'=>null,
   'issued_epoch'=>time(),'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED',
   'requires_fresh_host_auth_for_each_command'=>true,
   'execution_authorized'=>false,'executed'=>false];
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  if (!add_option($c::TRANSPORT_PREFIX.$tid,$t,'',false))return self::fail('TRANSPORT_PERSISTENCE_FAILED');
  if (!add_option($c::PREFIX.$gid,$g,'',false)){
   delete_option($c::TRANSPORT_PREFIX.$tid);
   return self::fail('GRANT_PERSISTENCE_FAILED');
  }
  $slot=['transport_id'=>$tid,'grant_id'=>$gid,'company'=>$company,
    'environment'=>$env,'site_uuid'=>$v['site_uuid'],
    'canonical_origin'=>$v['canonical_origin']];
  if ($rotating){
   global $wpdb;
   $lock='mad4b-305-rotation-'.substr(hash('sha256',$company.':'.$env),0,32);
   if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1){
    delete_option($c::PREFIX.$gid);delete_option($c::TRANSPORT_PREFIX.$tid);
    return self::fail('ORIGINAL_305_ROTATION_LOCK_UNAVAILABLE');
   }
   try {
    if(get_option(self::slot($company,$env),false)!==$old){
     delete_option($c::PREFIX.$gid);delete_option($c::TRANSPORT_PREFIX.$tid);
     return self::fail('ORIGINAL_305_ROTATION_CONCURRENT_CHANGE');
    }
    if(!update_option(self::slot($company,$env),$slot,false)){
     delete_option($c::PREFIX.$gid);delete_option($c::TRANSPORT_PREFIX.$tid);
     return self::fail('ORIGINAL_305_NEW_SITE_SLOT_WRITE_FAILED');
    }
    $r=self::readback($company,$env);
    if(($r['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'){
     update_option(self::slot($company,$env),$old,false);
     delete_option($c::PREFIX.$gid);delete_option($c::TRANSPORT_PREFIX.$tid);
     return self::fail('ORIGINAL_305_ROTATION_READBACK_FAILED');
    }
    // Retain original historical transport/grant rows, but revoke their use.
    $oldgid=(string)($old['grant_id']??'');$oldtid=(string)($old['transport_id']??'');
    $oldg=$oldgid!==''?get_option($c::PREFIX.$oldgid,false):false;
    $oldt=$oldtid!==''?get_option($c::TRANSPORT_PREFIX.$oldtid,false):false;
    $reason=$reissuing?'REVOKED_FOR_NATIVE_ACCESS_GRANT_REISSUE':'REVOKED_FOR_SITE_REPLACEMENT';
    $revoked=true;
    if(is_array($oldg)){
     $oldg['state']='CENTRAL_OPERATION_SCOPE_REVOKED';
     $oldg['execution_authorized']=false;
     if($reissuing)$oldg['revoked_for_reissue_epoch']=time();
     else $oldg['revoked_for_replacement_site_uuid']=$v['site_uuid'];
     update_option($c::PREFIX.$oldgid,$oldg,false);
     $nowg=get_option($c::PREFIX.$oldgid,false);
     $revoked=$revoked&&is_array($nowg)&&($nowg['state']??'')==='CENTRAL_OPERATION_SCOPE_REVOKED';
    }
    if(is_array($oldt)){
     $oldt['state']=$reason;$oldt['full_wpcli_active']=false;
     $oldt['host_authority_verified']=false;
     if($reissuing)$oldt['revoked_for_reissue_epoch']=time();
     else $oldt['revoked_for_replacement_site_uuid']=$v['site_uuid'];
     update_option($c::TRANSPORT_PREFIX.$oldtid,$oldt,false);
     $nowt=get_option($c::TRANSPORT_PREFIX.$oldtid,false);
     $revoked=$revoked&&is_array($nowt)&&($nowt['state']??'')===$reason;
    }
    if(!$revoked)return self::fail('ORIGINAL_LEGACY_SCOPE_REVOCATION_NOT_CONFIRMED');
    $r['grant_issued']=true;$r['legacy_grant_revoked']=true;
    $r['grant_reissued_same_site']=$reissuing;
    $r['legacy_grant_reused']=false;
    return $r;
   }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
  }
  if (!add_option(self::slot($company,$env),$slot,'',false)) {
   delete_option($c::PREFIX.$gid);delete_option($c::TRANSPORT_PREFIX.$tid);
   return self::fail('CONCURRENT_ENROLLMENT_SLOT_ALREADY_EXISTS');
  }
  $r=self::readback($company,$env);
  if (($r['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED')
   return self::fail('ORIGINAL_305_ENROLLMENT_READBACK_UNCERTAIN');
  $r['grant_issued']=true;
  return $r;
 }
 public static function cli($args,$assoc):void {
  if (!defined('WP_CLI')||!WP_CLI||!class_exists('WP_CLI'))return;
  $action=$args[0]??'';
  if ($action==='status' && array_keys($assoc)===['company','environment']) {
   WP_CLI::line(wp_json_encode(self::readback((string)$assoc['company'],
    (string)$assoc['environment']),JSON_UNESCAPED_SLASHES));return;
  }
  if (!in_array($action,['enroll','rotate','reissue'],true)||array_keys($assoc)!==['proof'])
   WP_CLI::error('EXACT_SIGNED_NATIVE_ENROLLMENT_REQUIRED');
  $raw=base64_decode((string)$assoc['proof'],true);
  if(!is_string($raw)||strlen($raw)>4500)WP_CLI::error('SIGNED_PROOF_INVALID');
  $proof=json_decode($raw,true);
  $r=is_array($proof)&&($proof['action']??null)===$action
   ?self::enroll($proof):self::fail('SIGNED_PROOF_INVALID');
  if (($r['state']??'')!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED')
   WP_CLI::error($r['reason']??'ENROLLMENT_DENIED');
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b company-native-wpcli',[__CLASS__,'cli']);
 }
}
MAD4B_Company_Native_WPCLI_Enrollment_V1::boot();
// Original remote family is loaded from this already enrolled authority module.
$remoteOriginal=__DIR__.'/326-mad4b-original-remote-hostinger-305.php';
if(is_file($remoteOriginal))require_once $remoteOriginal;
unset($remoteOriginal);
