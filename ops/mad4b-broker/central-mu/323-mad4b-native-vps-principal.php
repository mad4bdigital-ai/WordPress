<?php
/**
 * Plugin Name: MAD4B Exact Native VPS Client Principal
 * Description: Separately authenticated local host principal for exact central staging transport and command intake; no WordPress user impersonation.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Native_VPS_Principal_V1 {
 const CONTRACT='mad4b.native-vps-client-principal.v1';
 const HOST='srv2030908';
 const COMPANY='tours-central';
 const PRINCIPAL='vps-native:srv2030908';
 const ORIGIN='https://tours.dev.mad4b.com';
 const NONCE='mad4b_native_vps_principal_nonce_v1_';
 const SLOT='mad4b_native_vps_principal_standing_v1';
 const FIELDS=['contract','action','host_id','company','site_uuid',
  'canonical_origin','environment','operation','host_key_fingerprint',
  'policy_sha256','command_sha256','correlation_id','issued','expires','nonce'];
 private static function deny($code,$status=403) {
  return new WP_Error('mad4b_native_vps_'.$code,$code,
   ['status'=>$status,'contract'=>self::CONTRACT]);
 }
 public static function scope():array {
  if (!class_exists('MAD4B_Central_Host_Scope_V1',false))return [];
  $s=MAD4B_Central_Host_Scope_V1::inspect(self::COMPANY);
  return ($s['state']??'')==='CENTRAL_HOST_IDENTITY_VERIFIED'
   && ($s['scope_kind']??'')==='central_native_host'
   && ($s['host_id']??'')===self::HOST
   && ($s['canonical_origin']??'')===self::ORIGIN
   && ($s['environment']??'')==='staging' ? $s : [];
 }
 public static function signed_text(array $proof) {
  if (array_keys($proof)!==array_merge(self::FIELDS,['signature']))return false;
  $values=[];
  foreach(self::FIELDS as $name) {
   $v=$proof[$name];
   if (!is_string($v) && !is_int($v))return false;
   $values[]=(string)$v;
  }
  return implode("\n",$values);
 }
 public static function attest(array $p,string $action) {
  $s=self::scope();$body=self::signed_text($p);$now=time();
  if (!$s || !is_string($body) || strlen($body)>2000
   || !in_array($action,['enroll','submit'],true)
   || $p['contract']!==self::CONTRACT || $p['action']!==$action
   || $p['host_id']!==self::HOST || $p['company']!==self::COMPANY
   || $p['site_uuid']!==$s['site_uuid']
   || $p['canonical_origin']!==self::ORIGIN || $p['environment']!=='staging'
   || $p['operation']!=='maintenance.shell'
   || !preg_match('/^SHA256:[A-Za-z0-9+\/]{43}=?$/D',$p['host_key_fingerprint'])
   || !preg_match('/^[a-f0-9]{64}$/D',$p['policy_sha256'])
   || !preg_match('/^[a-f0-9]{64}$/D',$p['command_sha256'])
   || !preg_match('/^[a-f0-9]{32}$/D',$p['nonce'])
   || !is_int($p['issued']) || !is_int($p['expires'])
   || $p['issued']<$now-60 || $p['issued']>$now+10
   || $p['expires']<$now || $p['expires']>$p['issued']+60
   || ($action==='enroll' && ($p['command_sha256']!==str_repeat('0',64)
        || $p['correlation_id']!=='enrollment'))
   || ($action==='submit' && !preg_match('/^[a-zA-Z0-9_.-]{8,100}$/D',$p['correlation_id'])))
     return self::deny('scope_or_expiry_invalid');
  $signature=base64_decode($p['signature'],true);
  $pub=class_exists('MAD4B_Central_VPS_Broker_V1',false)
   ?base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true):false;
  if (!is_string($pub) || strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
   || !is_string($signature) || strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES
   || !function_exists('sodium_crypto_sign_verify_detached')
   || !sodium_crypto_sign_verify_detached($signature,$body,$pub))
    return self::deny('signed_original_vps_client_required');
  return $s;
 }
 private static function take_nonce(array $p):bool {
  // Consumption occurs AFTER cryptographic validation. A nonce is never
  // accepted for a second operation, even with identical signed bytes.
  return add_option(self::NONCE.$p['nonce'],
   ['action'=>$p['action'],'at'=>time(),'principal'=>self::PRINCIPAL],'',false);
 }
 public static function existing() {
  $r=get_option(self::SLOT,false);
  if (!is_array($r) || ($r['principal']??'')!==self::PRINCIPAL
   || !preg_match('/^[a-f0-9]{32}$/D',(string)($r['transport_id']??''))
   || !preg_match('/^[a-f0-9]{32}$/D',(string)($r['grant_id']??'')))return false;
  return $r;
 }
 public static function ledger(array $r,array $s):bool {
  if (($r['principal']??'')!==self::PRINCIPAL
   || ($r['host_id']??'')!==self::HOST
   || ($r['site_uuid']??'')!==$s['site_uuid']
   || ($r['policy_sha256']??'')==='')return false;
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  $t=get_option($c::TRANSPORT_PREFIX.$r['transport_id'],false);
  $g=get_option($c::PREFIX.$r['grant_id'],false);
  return is_array($t) && is_array($g)
   && ($t['state']??'')==='PENDING_INDEPENDENT_SSH_VERIFICATION'
   && ($g['state']??'')==='CENTRAL_OPERATION_SCOPE_GRANTED'
   && ($t['principal_type']??'')==='vps_native'
   && ($g['principal_type']??'')==='vps_native'
   && ($t['principal_id']??'')===self::PRINCIPAL
   && ($g['principal_id']??'')===self::PRINCIPAL
   && ($t['host_policy_sha256']??'')===$r['policy_sha256']
   && ($g['host_policy_sha256']??'')===$r['policy_sha256']
   && ($t['host_key_fingerprint']??'')===$r['host_key_fingerprint']
   && ($t['host_id']??'')===self::HOST
   && ($t['host_principal']??'')==='root'
   && ($t['credential_ref']??'')==='vault:registry/local-srv2030908'
   && ($g['target_host_id']??'')===self::HOST
   && ($g['target_ssh_principal']??'')==='root'
   && ($g['company']??'')===self::COMPANY
   && ($t['company']??'')===self::COMPANY
   && ($t['site_uuid']??'')===$s['site_uuid']
   && ($g['site_uuid']??'')===$s['site_uuid']
   && ($t['canonical_origin']??'')===self::ORIGIN
   && ($g['canonical_origin']??'')===self::ORIGIN
   && ($t['environment']??'')==='staging'
   && ($g['environment']??'')==='staging'
   && ($g['grant_mode']??'')==='persistent'
   && array_key_exists('expires_epoch',$g) && $g['expires_epoch']===null
   && ($g['operation']??'')==='maintenance.shell'
   && ($g['transport_id']??'')===$r['transport_id']
   && ($g['grant_id']??'')===$r['grant_id'];
 }
 public static function enroll(array $p) {
  $scope=self::attest($p,'enroll');
  if (is_wp_error($scope))return $scope;
  $existing=self::existing();
  if ($existing) {
   if (!self::ledger($existing,$scope))return self::deny('enrollment_revoked_or_changed',409);
   if ($existing['policy_sha256']!==$p['policy_sha256']
    || $existing['host_key_fingerprint']!==$p['host_key_fingerprint'])
    return self::deny('host_policy_or_key_changed_requires_reenrollment',409);
   return ['contract'=>self::CONTRACT,'state'=>'STANDING_ENROLLMENT_EXISTS',
    'transport_id'=>$existing['transport_id'],'grant_id'=>$existing['grant_id'],
    'principal_id'=>self::PRINCIPAL,'grant_issued'=>false,'execution_performed'=>false];
  }
  if (!self::take_nonce($p))return self::deny('signed_nonce_replay',409);
  $tid=bin2hex(random_bytes(16));$gid=bin2hex(random_bytes(16));
  $base=['contract'=>'mad4b.central-ssh-command-intake.v1',
   'company'=>self::COMPANY,'site_uuid'=>$scope['site_uuid'],
   'canonical_origin'=>self::ORIGIN,'environment'=>'staging',
   'assignment_revision'=>0,'central_principal_id'=>0,
   'principal_type'=>'vps_native','principal_id'=>self::PRINCIPAL,
   'host_policy_sha256'=>$p['policy_sha256']];
  $transport=$base+['transport_id'=>$tid,'host_id'=>self::HOST,
   'host_principal'=>'root','host_key_fingerprint'=>$p['host_key_fingerprint'],
   'credential_ref'=>'vault:registry/local-srv2030908',
   'state'=>'PENDING_INDEPENDENT_SSH_VERIFICATION',
   'mode'=>'persistent_ssh_configuration','capabilities'=>[],
   'ssh_secret_stored_in_wordpress'=>false,
   'host_authority_verified'=>false];
  $grant=$base+['grant_id'=>$gid,'operation'=>'maintenance.shell',
   'target_host_id'=>self::HOST,'target_ssh_principal'=>'root',
   'grant_mode'=>'persistent','transport_id'=>$tid,'expires_epoch'=>null,
   'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED',
   'execution_authorized'=>false,'executed'=>false];
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  if (!add_option($c::TRANSPORT_PREFIX.$tid,$transport,'',false))
   return self::deny('transport_persistence_failed',503);
  if (!add_option($c::PREFIX.$gid,$grant,'',false)) {
   delete_option($c::TRANSPORT_PREFIX.$tid);return self::deny('grant_persistence_failed',503);
  }
  $slot=['principal'=>self::PRINCIPAL,'host_id'=>self::HOST,
   'site_uuid'=>$scope['site_uuid'],'policy_sha256'=>$p['policy_sha256'],
   'host_key_fingerprint'=>$p['host_key_fingerprint'],
   'transport_id'=>$tid,'grant_id'=>$gid];
  if (!add_option(self::SLOT,$slot,'',false)) {
   delete_option($c::PREFIX.$gid);delete_option($c::TRANSPORT_PREFIX.$tid);
   return self::deny('enrollment_slot_already_claimed',409);
  }
  if (!self::ledger($slot,$scope))
   return self::deny('enrollment_readback_failed',503);
  return ['contract'=>self::CONTRACT,'state'=>'STANDING_TRANSPORT_AND_GRANT_REGISTERED',
   'principal_id'=>self::PRINCIPAL,'transport_id'=>$tid,'grant_id'=>$gid,
   'execution_authorized'=>false,'execution_performed'=>false];
 }
 public static function submit(array $p,string $command) {
  $scope=self::attest($p,'submit');
  if (is_wp_error($scope))return $scope;
  if ($command==='' || strlen($command)>4096 || strpos($command,"\0")!==false
   || !hash_equals($p['command_sha256'],hash('sha256',$command)))
   return self::deny('command_bytes_or_digest_invalid',422);
  $slot=self::existing();
  if (!$slot || !self::ledger($slot,$scope)
   || $slot['policy_sha256']!==$p['policy_sha256']
   || $slot['host_key_fingerprint']!==$p['host_key_fingerprint'])
   return self::deny('active_original_native_grant_required',403);
  if (!self::take_nonce($p))return self::deny('signed_nonce_replay',409);
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  $claim=$c::CLAIM_PREFIX.$slot['grant_id'].'_'.hash('sha256',$p['correlation_id']);
  $id=bin2hex(random_bytes(16));
  if (!add_option($claim,$id,'',false))return self::deny('correlation_replay',409);
  $request=[
   'contract'=>$c::CONTRACT,'request_id'=>$id,'grant_id'=>$slot['grant_id'],
   'company'=>self::COMPANY,'operation'=>'maintenance.shell',
   'site_uuid'=>$scope['site_uuid'],'canonical_origin'=>self::ORIGIN,
   'environment'=>'staging','central_principal_id'=>0,
   'principal_type'=>'vps_native','principal_id'=>self::PRINCIPAL,
   'host_policy_sha256'=>$p['policy_sha256'],
   'target_host_id'=>self::HOST,'target_ssh_principal'=>'root',
   'target_scope'=>'host','correlation_id'=>$p['correlation_id'],
   'command_sha256'=>$p['command_sha256'],
   'command_format'=>'maintenance.shell.text.v1',
   'command_byte_length'=>strlen($command),'grant_mode'=>'persistent',
   'transport_id'=>$slot['transport_id'],
   'command_bytes_persisted'=>false,'created_epoch'=>time(),
   'expires_epoch'=>time()+300,
   'status'=>'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
   'host_ssh_grant_verified'=>false,'transport_registered'=>false,
   'execution_performed'=>false];
  if (!add_option($c::REQUEST_PREFIX.$id,$request,'',false)) {
   delete_option($claim);return self::deny('request_persistence_failed',503);
  }
  if (!class_exists('MAD4B_Central_VPS_Broker_V1',false))
   return self::deny('broker_missing_after_intake',503);
  return MAD4B_Central_VPS_Broker_V1::dispatch_native($id);
 }
 public static function valid_job_chain(array $job,array $grant,array $transport,array $request):bool {
  $s=self::scope();$slot=self::existing();
  if (!$s || !$slot || !self::ledger($slot,$s))return false;
  foreach([$job,$grant,$transport,$request] as $row) {
   if (($row['principal_type']??'')!=='vps_native'
    || ($row['principal_id']??'')!==self::PRINCIPAL
    || ($row['host_policy_sha256']??'')!==$slot['policy_sha256']
    || ($row['company']??'')!==self::COMPANY
    || ($row['site_uuid']??'')!==$s['site_uuid']
    || ($row['environment']??'')!=='staging')return false;
  }
  return ($job['target_host_id']??'')===self::HOST
   && ($job['target_scope']??'')==='host'
   && ($job['operation']??'')==='maintenance.shell'
   && ($grant['grant_id']??'')===$slot['grant_id']
   && ($request['grant_id']??'')===$slot['grant_id']
   && ($transport['transport_id']??'')===$slot['transport_id']
   && ($request['transport_id']??'')===$slot['transport_id'];
 }
 public static function route_permission():bool {return true;} // Proof checked inside handler.
 public static function routes():void {
  if (!self::scope())return;
  register_rest_route('mad4b/v1','/native-vps-client/(?P<action>enroll|submit)',[
   'methods'=>'POST','permission_callback'=>[__CLASS__,'route_permission'],
   'callback'=>static function(WP_REST_Request $r) {
    $payload=$r->get_json_params();
    if (!is_array($payload) || array_keys($payload)!==['proof','command']
     || !is_array($payload['proof']) || !is_string($payload['command'])
     || strlen($payload['command'])>4096)
      return self::deny('envelope_invalid',422);
    $action=(string)$r['action'];
    if ($action==='enroll' && $payload['command']!=='')
     return self::deny('enroll_command_must_be_empty',422);
    $response=$action==='enroll'
      ?self::enroll($payload['proof']):self::submit($payload['proof'],$payload['command']);
    return is_wp_error($response)?$response:rest_ensure_response($response);
   }
  ]);
 }
 public static function boot():void {
  add_action('rest_api_init',[__CLASS__,'routes'],95);
 }
}
MAD4B_Native_VPS_Principal_V1::boot();
