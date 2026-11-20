<?php
/**
 * Plugin Name: MAD4B General WP-CLI Central Grant Preflight
 * Description: Signed, read-only exact-grant verification of original central SSH intake records for general WP-CLI.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_General_WPCLI_Grant_V1 {
 const CONTRACT = 'mad4b.wpcli-exact-central-grant.v1';
 const HOST = 'srv2030908';
 const NONCE = 'mad4b_general_wpcli_signed_nonce_v1_';
 static function deny($reason) { return ['contract'=>self::CONTRACT,'eligible'=>false,'verified'=>false,
   'reason'=>$reason,'shell_executed'=>false,'grant_created'=>false]; }
 static function evaluate($id,$target,$digest) {
  if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false) ||
      !MAD4B_Central_Dual_Lane_Registry_V1::central() ||
      !class_exists('MAD4B_Central_SSH_Command_Intake_V1',false) ||
      !class_exists('MAD4B_Manager_Auto_Write_V1',false) ||
      !class_exists('MAD4B_Company_Operation_Admission_V1',false) ||
      !class_exists('MAD4B_Company_Portfolio_Manager_V1',false))
      return self::deny('CENTRAL_DEPENDENCY_UNAVAILABLE');
  if (!preg_match('/^[a-f0-9]{32}$/D',$id) ||
      !preg_match('/^[a-f0-9]{64}$/D',$digest) ||
      !in_array($target,['srv2030908','hosting-2'],true))
      return self::deny('EXACT_REQUEST_INVALID');
  $intake='MAD4B_Central_SSH_Command_Intake_V1';
  $req=get_option($intake::REQUEST_PREFIX.$id,false);
  // Separate, server-signed host channel. Never fabricate a 305 owner identity.
  // It can only admit an exact, nonmutating staging siteurl canary.
  if (!is_array($req) && class_exists('MAD4B_Native_Host_Exact_Grant_V1',false))
    return MAD4B_Native_Host_Exact_Grant_V1::verify($id,$target,$digest);
  if (!is_array($req) || ($req['request_id']??'')!==$id ||
      ($req['status']??'')!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY' ||
      ($req['target_scope']??'')!=='wordpress' ||
      ($req['command_sha256']??'')!==$digest ||
      ($req['target_host_id']??'')!==$target ||
      ($req['operation']??'')!=='maintenance.shell' ||
      ($req['execution_performed']??null)!==false ||
      (int)($req['expires_epoch']??0)<=time())
      return self::deny('ORIGINAL_EXACT_REQUEST_NOT_ELIGIBLE');
  $grant=get_option($intake::PREFIX.(string)($req['grant_id']??''),false);
  if (!is_array($grant) || ($grant['contract']??'')!==$intake::CONTRACT ||
      ($grant['state']??'')!=='CENTRAL_OPERATION_SCOPE_GRANTED' ||
      ($grant['operation']??'')!=='maintenance.shell' ||
      ($grant['target_host_id']??'')!==$target ||
      ($grant['grant_id']??'')!==($req['grant_id']??'') ||
      ($grant['target_ssh_principal']??'')!==($req['target_ssh_principal']??'') ||
      (int)($grant['central_principal_id']??0)<1 ||
      (int)($grant['central_principal_id']??0)!==(int)($req['central_principal_id']??-1))
      return self::deny('ORIGINAL_ACTIVE_GRANT_MISSING');
  $mode=(string)($grant['grant_mode']??'');
  if (!in_array($mode,['persistent','one_time'],true) ||
      $mode!==($req['grant_mode']??'') ||
      ($mode==='one_time' && (int)($grant['expires_epoch']??0)<=time()))
      return self::deny('GRANT_MODE_OR_EXPIRY_INVALID');
  foreach (['company','site_uuid','canonical_origin','environment'] as $field)
      if (($grant[$field]??null)!==($req[$field]??null))
          return self::deny('EXACT_COMPANY_SITE_ENVIRONMENT_MISMATCH');
  $company=(string)($grant['company']??'');
  $current=MAD4B_Manager_Auto_Write_V1::company_target($company);
  if (($current['state']??'')!=='REGISTERED' ||
      ($current['site_uuid']??null)!==($grant['site_uuid']??null) ||
      ($current['canonical_origin']??null)!==($grant['canonical_origin']??null) ||
      ($current['environment']??null)!==($grant['environment']??null))
      return self::deny('ORIGINAL_COMPANY_BINDING_CHANGED');
  $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
  if (!$manager || !MAD4B_Company_Portfolio_Manager_V1::has_delegation(
      $manager,$company,'operations_manage',(int)$grant['central_principal_id']))
      return self::deny('COMPANY_DELEGATION_NOT_ACTIVE');
  $rights=MAD4B_Manager_Auto_Write_V1::entitlement(
      $manager,$company,'operations.write',(int)$grant['central_principal_id']);
  if (empty($rights['assignment_authorized']) ||
      (int)($rights['assignment_revision']??-1)!==(int)($grant['assignment_revision']??-2))
      return self::deny('ASSIGNMENT_REVOKED_OR_CHANGED');
  if ($mode==='persistent') {
      $tid=(string)($grant['transport_id']??'');
      $transport=get_option($intake::TRANSPORT_PREFIX.$tid,false);
      if (!is_array($transport) ||
          ($transport['state']??'')!=='PENDING_INDEPENDENT_SSH_VERIFICATION' ||
          ($transport['transport_id']??'')!==$tid ||
          ($req['transport_id']??'')!==$tid ||
          ($transport['host_id']??'')!==$target ||
          ($transport['host_principal']??'')!==($grant['target_ssh_principal']??'') ||
          ($transport['central_principal_id']??0)!==$grant['central_principal_id'] ||
          (int)($transport['assignment_revision']??-1)!==(int)($grant['assignment_revision']??-2) ||
          ($transport['company']??'')!==$company ||
          ($transport['site_uuid']??'')!==$grant['site_uuid'] ||
          ($transport['canonical_origin']??'')!==$grant['canonical_origin'] ||
          ($transport['environment']??'')!==$grant['environment'])
          return self::deny('PERSISTENT_TRANSPORT_REVOKED_OR_CHANGED');
  }
  return [
   'contract'=>self::CONTRACT,'verified'=>true,'eligible'=>true,
   'reason'=>'ORIGINAL_CENTRAL_GRANT_VERIFIED_INDEPENDENT_HOST_PROOF_REQUIRED',
   'request_id'=>$id,'target_id'=>$target,'command_sha256'=>$digest,
   'company'=>$company,'site_uuid'=>$grant['site_uuid'],
   'canonical_origin'=>$grant['canonical_origin'],'environment'=>$grant['environment'],
   'principal_id'=>(string)$grant['central_principal_id'],
   'host_principal'=>$grant['target_ssh_principal'],'grant_id'=>$grant['grant_id'],
   'grant_mode'=>$mode,'expires_epoch'=>(int)$req['expires_epoch'],
   'central_scope_only'=>true,'shell_executed'=>false,'grant_created'=>false
  ];
 }
 static function signature_ok($args) {
  if (!class_exists('MAD4B_Central_VPS_Broker_V1',false)) return false;
  $issued=(int)($args['issued']??0);
  $expiry=(int)($args['expires']??0);
  $nonce=(string)($args['nonce']??'');
  if (!preg_match('/^[a-f0-9]{32}$/D',$nonce) ||
      $issued<time()-60 || $issued>time()+10 ||
      $expiry<$issued || $expiry>$issued+60 || $expiry<time()) return false;
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $signature=base64_decode((string)($args['signature']??''),true);
  if (!is_string($pub) || strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
      !is_string($signature) || strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES) return false;
  $msg=implode("\n",[self::CONTRACT,'verify',self::HOST,
    (string)($args['request']??''),(string)($args['target']??''),
    (string)($args['command_sha256']??''),$nonce,(string)$issued,(string)$expiry]);
  return sodium_crypto_sign_verify_detached($signature,$msg,$pub);
 }
 static function cli($args,$assoc) {
  if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI') ||
      get_current_user_id()<1 || !current_user_can('manage_options') ||
      !class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false) ||
      !MAD4B_Central_Dual_Lane_Registry_V1::central())
      WP_CLI::error('CENTRAL_WPCLI_HOST_REQUIRED');
  $action=(string)($args[0]??'');
  if ($action==='status') {
      WP_CLI::line(wp_json_encode(['contract'=>self::CONTRACT,'verifier_registered'=>true,
         'original_grant_source'=>'mad4b.central-ssh-command-intake.v1',
         'signed_host_required'=>true,'shell_executed'=>false,'grant_created'=>false]));
      return;
  }
  if ($action!=='verify') WP_CLI::error('status_or_verify_required');
  if (!self::signature_ok($assoc)) WP_CLI::error('SIGNED_HOST_PREFLIGHT_REQUIRED');
  if (!add_option(self::NONCE.(string)$assoc['nonce'],time(),'',false))
      WP_CLI::error('SIGNED_PREFLIGHT_REPLAY');
  WP_CLI::line(wp_json_encode(self::evaluate(
      (string)($assoc['request']??''),(string)($assoc['target']??''),
      (string)($assoc['command_sha256']??'')),JSON_UNESCAPED_SLASHES));
 }
 static function boot() {
  if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
      WP_CLI::add_command('mad4b wpcli-grant',[__CLASS__,'cli']);
 }
}
MAD4B_General_WPCLI_Grant_V1::boot();
