<?php
/**
 * Plugin Name: MAD4B Original New-Site Native Admission — Wayfa
 * Description: Independent signed admission for one exact new-site bootstrap. Does not install WordPress.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Original_New_Site_Native_Admission_V1 {
 const CONTRACT='mad4b.original-new-site-native-executor-readback.v1';
 const PROOF_CONTRACT='mad4b.original-new-site-execution-admission.v1';
 const OPTION='mad4b_original_new_site_native_admission_v2_';
 private static array $scope=[];
 private static function value(string $name):string {return (string)(self::$scope[$name]??'');}
 private static function set_scope(string $company,string $env):void {
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company) || !in_array($env,['staging','production'],true))self::deny('INVALID_SCOPE');
  self::$scope=['company'=>$company,'environment'=>$env];
 }
 private static function populate(array $grant):bool {
  if(($grant['state']??'')!=='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED' || ($grant['grant_present']??false)!==true)return false;
  $mapping=['site_uuid'=>'new_site_uuid','canonical_origin'=>'new_origin','container'=>'target_container','database'=>'target_database'];
  foreach($mapping as $to=>$from)if(!is_string($grant[$from]??null) || $grant[$from]==='')return false;
  if(!wp_is_uuid($grant['new_site_uuid']) || !preg_match('~^https://[a-z0-9.-]+$~D',$grant['new_origin']))return false;
  foreach($mapping as $to=>$from)self::$scope[$to]=$grant[$from];
  return true;
 }
 const OP='new-site.wordpress.bootstrap';
 const SIGNED_FIELDS=['contract','operation','company','environment',
  'site_uuid','canonical_origin','container','database','parent_site_uuid',
  'grant_digest','backup_sha256','backup_created_epoch','issued','expires','nonce'];
 const FRESH_SITE_FIELDS=['contract','operation','company','environment',
  'site_uuid','canonical_origin','container','database','parent_site_uuid',
  'grant_digest','baseline_kind','baseline_sha256','baseline_created_epoch',
  'issued','expires','nonce'];
 private static function deny(string $reason):void {
  WP_CLI::error('NATIVE_NEW_SITE_ADMISSION_DENIED_'.$reason);
 }
 private static function issuer():array {
  if(!class_exists('MAD4B_Original_New_Site_Bootstrap_Issuer_V1',false))
   return ['state'=>'ORIGINAL_ISSUER_MISSING'];
  return MAD4B_Original_New_Site_Bootstrap_Issuer_V1::status(self::value('company'),self::value('environment'));
 }
 private static function base():array {
  return ['contract'=>self::CONTRACT,'operation'=>self::OP,
   'company'=>self::value('company'),'environment'=>self::value('environment'),
   'site_uuid'=>self::value('site_uuid'),'canonical_origin'=>self::value('canonical_origin'),
   'container'=>self::value('container'),'database'=>self::value('database'),
   'original_312_authority_verified'=>false,
   'independent_host_identity_verified'=>false,
   'pre_install_backup_verified'=>false,
   'fresh_site_baseline_verified'=>false,
   'replay_safe_execution_reservation'=>false,
   'execution_performed'=>false,
   'native_install_performed'=>false];
 }
 private static function admission_key(array $issuer):string {
   $digest=(string)($issuer['grant_digest']??'');
   if(!preg_match('/^[a-f0-9]{64}$/D',$digest))return '';
   return self::OPTION.hash('sha256',self::value('company').'|'.self::value('environment').'|'.self::value('site_uuid')).'_'.$digest;
 }
 private static function mac(array $data):string {
  return hash_hmac('sha256',wp_json_encode($data,JSON_UNESCAPED_SLASHES),wp_salt('auth'));
 }
 private static function verify_signature(array $proof,bool $fresh=true):bool {
  $baseline=array_keys($proof)===array_merge(self::FRESH_SITE_FIELDS,['signature']);
  $backup=array_keys($proof)===array_merge(self::SIGNED_FIELDS,['signature']);
  if(!$baseline && !$backup)return false;
  $fields=$baseline?self::FRESH_SITE_FIELDS:self::SIGNED_FIELDS;
  foreach($fields as $key)
   if(!is_int($proof[$key]) && !is_string($proof[$key]))return false;
  if(($proof['contract']??'')!==self::PROOF_CONTRACT
    || ($proof['operation']??'')!==self::OP
    || ($proof['company']??'')!==self::value('company')
    || ($proof['environment']??'')!==self::value('environment')
    || ($proof['site_uuid']??'')!==self::value('site_uuid')
    || ($proof['canonical_origin']??'')!==self::value('canonical_origin')
    || ($proof['container']??'')!==self::value('container')
    || ($proof['database']??'')!==self::value('database')
    || !preg_match('/^[a-f0-9]{64}$/D',(string)$proof['grant_digest'])
    || !preg_match('/^[a-f0-9]{32}$/D',(string)$proof['nonce'])
    || !is_int($proof['issued']) || !is_int($proof['expires'])
    || $proof['expires']<$proof['issued']
    || $proof['expires']>$proof['issued']+90
    || ($fresh && ($proof['issued']<time()-75
      || $proof['issued']>time()+10 || $proof['expires']<time()+2))
    || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
    || !function_exists('sodium_crypto_sign_verify_detached'))return false;
  $source_time=$baseline?'baseline_created_epoch':'backup_created_epoch';
  $source_digest=$baseline?'baseline_sha256':'backup_sha256';
  if(!preg_match('/^[a-f0-9]{64}$/D',(string)($proof[$source_digest]??''))
     || !is_int($proof[$source_time])
     || $proof[$source_time]>$proof['issued']+10
     || $proof[$source_time]<$proof['issued']-1800)return false;
  if($baseline && ($proof['baseline_kind']??'')!=='fresh_site_empty_db')
    return false;
  $raw=get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,false);
  if(!is_string($raw))return false;
  $pub=base64_decode($raw,true);
  $sig=base64_decode((string)$proof['signature'],true);
  if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
      || !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)return false;
  $message=implode("\n",array_map(static fn($k)=>(string)$proof[$k],$fields));
  return sodium_crypto_sign_verify_detached($sig,$message,$pub);
 }
 public static function status(string $company='',string $environment=''):array {
  if($company!=='' || $environment!=='')self::set_scope($company,$environment);
  $base=self::base();
  $issuer=self::issuer();
  if(!self::populate($issuer))return $base+['state'=>'ORIGINAL_OWNER_GRANT_NOT_CURRENT'];
  if(($issuer['state']??'')!=='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'
   || $issuer['grant_present']!==true)
   return $base+['state'=>'ORIGINAL_OWNER_GRANT_NOT_CURRENT'];
  $base['parent_site_uuid']=$issuer['parent_site_uuid'];
  $base['grant_digest']=$issuer['grant_digest'];
  $key=self::admission_key($issuer);
  if($key==='')return $base+['state'=>'ORIGINAL_GRANT_DIGEST_NOT_VALID'];
  $record=get_option($key,false);
  // Legacy original record remains immutable after a renewal; only read it
  // if its signed 312 proof refers to this exact active grant digest.
  if(!is_array($record) || !is_array($record['proof']??null)
    || !is_string($record['mac']??null))
    return $base+['state'=>'ORIGINAL_NATIVE_BOOTSTRAP_312_NOT_ADMITTED'];
  $proof=$record['proof'];
  if(!is_int($record['admitted_epoch']??null)
    || $record['admitted_epoch']<$proof['issued']-10
    || $record['admitted_epoch']>$proof['expires']
    || !hash_equals(self::mac(['proof'=>$proof,'admitted_epoch'=>$record['admitted_epoch']]),$record['mac'])
    || !self::verify_signature($proof,false)
    || $proof['parent_site_uuid']!==$issuer['parent_site_uuid']
    || $proof['grant_digest']!==$issuer['grant_digest']
    || $proof['expires']>(int)($issuer['expires_epoch']??0))
    return $base+['state'=>'NATIVE_ADMISSION_EXPIRED_OR_INVALID'];
  return array_merge($base,[
    'state'=>'EXACT_NEW_SITE_NATIVE_BOOTSTRAP_ADMITTED',
    'original_312_authority_verified'=>true,
    'independent_host_identity_verified'=>true,
    'pre_install_backup_verified'=>isset($proof['backup_sha256']),
    'fresh_site_baseline_verified'=>isset($proof['baseline_sha256']),
    'pre_install_rollback_basis'=>isset($proof['baseline_sha256'])
      ?'FRESH_SITE_EMPTY_DATABASE_BASELINE':'FULL_BACKUP',
    'replay_safe_execution_reservation'=>true,
    'baseline_sha256'=>$proof['baseline_sha256']??null,
    'backup_sha256'=>$proof['backup_sha256']??null,
    'admission_expires_epoch'=>$proof['expires'],
    'admission_proof_digest'=>hash('sha256',wp_json_encode($proof,JSON_UNESCAPED_SLASHES))]);
 }
 public static function admit():void {
  $issuer=self::issuer();
  if(!self::populate($issuer))self::deny('ORIGINAL_GRANT_SCOPE_REQUIRED');
  if(($issuer['state']??'')!=='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'
     || $issuer['grant_present']!==true)self::deny('ORIGINAL_GRANT_NOT_ACTIVE');
  $key=self::admission_key($issuer);
  if($key==='' || get_option($key,false)!==false)
    self::deny('EXACT_GRANT_RESERVATION_ALREADY_EXISTS');
  $text=stream_get_contents(STDIN);
  if(!is_string($text)||strlen($text)<80||strlen($text)>8000)
    self::deny('SIGNED_EVIDENCE_STDIN_REQUIRED');
  $proof=json_decode($text,true);
  if(!is_array($proof) || !self::verify_signature($proof))
    self::deny('INDEPENDENT_SIGNATURE_REQUIRED');
  if($proof['parent_site_uuid']!==($issuer['parent_site_uuid']??'')
    || $proof['grant_digest']!==($issuer['grant_digest']??'')
    || $proof['expires']>(int)($issuer['expires_epoch']??0))
    self::deny('EXACT_OWNER_GRANT_SCOPE_MISMATCH');
  $admitted_epoch=time();
  $record=['proof'=>$proof,'admitted_epoch'=>$admitted_epoch,
    'mac'=>self::mac(['proof'=>$proof,'admitted_epoch'=>$admitted_epoch])];
  if(!add_option($key,$record,'',false))
    self::deny('ATOMIC_RESERVATION_FAILED');
  $status=self::status();
  if(($status['state']??'')!=='EXACT_NEW_SITE_NATIVE_BOOTSTRAP_ADMITTED')
    self::deny('INDEPENDENT_ADMISSION_READBACK_UNCERTAIN');
  WP_CLI::line(wp_json_encode($status,JSON_UNESCAPED_SLASHES));
 }
 public static function cli(array $args,array $assoc):void {
  $action=(string)($args[0]??'');
  if(array_keys($assoc)!==['company','environment'])self::deny('EXACT_SCOPE_REQUIRED');
  self::set_scope((string)$assoc['company'],(string)$assoc['environment']);
  if($action==='status') {
    WP_CLI::line(wp_json_encode(self::status(),JSON_UNESCAPED_SLASHES));
    return;
  }
  if($action==='admit' && count($args)===1) {
    self::admit();return;
  }
  self::deny('UNKNOWN_NATIVE_OPERATION');
 }
 public static function boot():void {
  if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b company-new-site-executor',[__CLASS__,'cli']);
 }
}
MAD4B_Original_New_Site_Native_Admission_V1::boot();
