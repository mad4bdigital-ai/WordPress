<?php
/**
 * Plugin Name: MAD4B Company Native 313 Bootstrap Admission
 * Description: Tenant-scoped signed host dispatch reservation and immutable installation receipt readback.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Native_Bootstrap_313_V2 {
 const CONTRACT='mad4b.company.native-wordpress-bootstrap-313.v2';
 const PROOF='mad4b.original-native-wordpress-bootstrap-dispatch-proof.v1';
 const RECEIPT='mad4b.original-native-wordpress-bootstrap-313-receipt.v1';
 const OP='new-site.wordpress.bootstrap';
 const PREFIX='mad4b_original_native_313_v2_';
 private static array $scope=[];
 private static function value(string $name):string {return (string)(self::$scope[$name]??'');}
 private static function set_scope(string $company,string $env):void {
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)||!in_array($env,['staging','production'],true))self::bad('INVALID_SCOPE');
  self::$scope=['company'=>$company,'environment'=>$env];
 }
 private static function populate(array $grant):bool {
  if(($grant['state']??'')!=='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'||($grant['grant_present']??false)!==true)return false;
  foreach(['site_uuid'=>'new_site_uuid','canonical_origin'=>'new_origin','container'=>'target_container','database'=>'target_database'] as $to=>$from){
    if(!is_string($grant[$from]??null)||$grant[$from]==='')return false;
    self::$scope[$to]=$grant[$from];
  }
  return wp_is_uuid(self::value('site_uuid'));
 }
 const FIELDS=['contract','operation','company','environment','site_uuid','canonical_origin',
   'container','database','parent_site_uuid','grant_digest','baseline_sha256',
   'install_request_sha256','issued','expires','nonce'];
 const RECEIPT_FIELDS=['contract','operation','company','environment','site_uuid','canonical_origin',
   'grant_digest','baseline_sha256','install_request_sha256','siteurl','home',
   'admin_email','admin_user','notification_policy','tables','verified_epoch','nonce'];

 private static function bad($code):void { WP_CLI::error('ORIGINAL_NATIVE_313_'.$code); }
 private static function key($digest):string {return self::PREFIX.hash('sha256',self::value('company').'|'.self::value('environment').'|'.self::value('site_uuid')).'_'.$digest;}
 private static function mac($r):string {
  return hash_hmac('sha256',wp_json_encode($r,JSON_UNESCAPED_SLASHES),wp_salt('auth'));
 }
 private static function verify($proof,$fields,$contract):bool {
  if(!is_array($proof) || array_keys($proof)!==array_merge($fields,['signature'])
    || ($proof['contract']??'')!==$contract
    || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
    || !function_exists('sodium_crypto_sign_verify_detached'))return false;
  foreach($fields as $f) if(!is_int($proof[$f]) && !is_string($proof[$f]))return false;
  if(($proof['operation']??'')!==self::OP || ($proof['company']??'')!==self::value('company')
    || ($proof['environment']??'')!==self::value('environment') || ($proof['site_uuid']??'')!==self::value('site_uuid')
    || ($proof['canonical_origin']??'')!==self::value('canonical_origin'))return false;
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $signature=base64_decode((string)$proof['signature'],true);
  if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    || !is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES)return false;
  $msg=implode("\n",array_map(static fn($name)=>(string)$proof[$name],$fields));
  return sodium_crypto_sign_verify_detached($signature,$msg,$pub);
 }
 private static function authority():array {
  if(!class_exists('MAD4B_Original_New_Site_Bootstrap_Issuer_V1',false)
    || !class_exists('MAD4B_Original_New_Site_Native_Admission_V1',false))return [];
  $g=MAD4B_Original_New_Site_Bootstrap_Issuer_V1::status(self::value('company'),self::value('environment'));
  if(!self::populate($g))return [];
  $a=MAD4B_Original_New_Site_Native_Admission_V1::status(self::value('company'),self::value('environment'));
  if(($g['state']??'')!=='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'
     || ($g['grant_present']??false)!==true
     || ($g['new_site_uuid']??'')!==self::value('site_uuid')
     || ($g['new_origin']??'')!==self::value('canonical_origin')
     || ($g['target_container']??'')!==self::value('container')
     || ($g['target_database']??'')!==self::value('database')
     || ($a['state']??'')!=='EXACT_NEW_SITE_NATIVE_BOOTSTRAP_ADMITTED'
     || ($a['original_312_authority_verified']??false)!==true
     || ($a['fresh_site_baseline_verified']??false)!==true
     || ($a['replay_safe_execution_reservation']??false)!==true
     || ($a['grant_digest']??'')!==($g['grant_digest']??'')
     || !preg_match('/^[a-f0-9]{64}$/D',(string)($a['baseline_sha256']??'')))
     return [];
  return ['grant'=>$g,'native'=>$a];
 }
 private static function stored($digest):array {
  $r=get_option(self::key($digest),false);
  if($r===false)return [];
  if(!is_array($r) || !is_array($r['entry']??null)
      || !is_string($r['mac']??null)
      || !hash_equals(self::mac($r['entry']),$r['mac']))
    return ['invalid'=>true];
  return $r['entry'];
 }
 public static function status():array {
  $base=['contract'=>self::CONTRACT,'operation'=>self::OP,
    'company'=>self::value('company'),'environment'=>self::value('environment'),'site_uuid'=>self::value('site_uuid'),
    'canonical_origin'=>self::value('canonical_origin'),'execution_performed'=>false];
  $auth=self::authority();
  $base['site_uuid']=self::value('site_uuid');
  $base['canonical_origin']=self::value('canonical_origin');
  if(!$auth)return $base+['state'=>'ORIGINAL_GRANT_AND_312_REQUIRED'];
  $g=$auth['grant'];$a=$auth['native'];$digest=$g['grant_digest'];
  $base['grant_digest']=$digest;
  $base['baseline_sha256']=$a['baseline_sha256'];
  $entry=self::stored($digest);
  if(!$entry)return $base+['state'=>'ORIGINAL_NATIVE_313_NOT_RESERVED'];
  if(!empty($entry['invalid']))return $base+['state'=>'ORIGINAL_NATIVE_313_INTEGRITY_INVALID'];
  $p=$entry['proof']??null;
  if(!self::verify($p,self::FIELDS,self::PROOF)
     || ($p['grant_digest']??'')!==$digest
     || ($p['baseline_sha256']??'')!==$a['baseline_sha256']
     || ($p['parent_site_uuid']??'')!==$g['parent_site_uuid']
     || ($entry['admitted_epoch']??0)<($p['issued']??0)-10
     || ($entry['admitted_epoch']??0)>($p['expires']??0))
    return $base+['state'=>'ORIGINAL_NATIVE_313_SIGNATURE_OR_SCOPE_INVALID'];
  if(($entry['state']??'')==='RECEIPT_VERIFIED') {
    $receipt=$entry['receipt']??null;
    if(!self::verify($receipt,self::RECEIPT_FIELDS,self::RECEIPT)
       || ($receipt['grant_digest']??'')!==$digest
       || ($receipt['baseline_sha256']??'')!==$a['baseline_sha256']
       || ($receipt['install_request_sha256']??'')!==$p['install_request_sha256']
       || ($receipt['siteurl']??'')!==self::value('canonical_origin')
       || ($receipt['home']??'')!==self::value('canonical_origin')
       || !is_email((string)($receipt['admin_email']??''))
       || !validate_username((string)($receipt['admin_user']??''))
       || ($receipt['notification_policy']??'')!=='send_once_after_original_313_receipt'
       || !is_int($receipt['tables']??null) || $receipt['tables']<8)
       return $base+['state'=>'ORIGINAL_NATIVE_313_RECEIPT_INVALID'];
    return array_merge($base,['state'=>'ORIGINAL_NATIVE_WORDPRESS_313_VERIFIED',
      'execution_performed'=>true,'native_install_performed'=>true,
      'install_request_sha256'=>$p['install_request_sha256'],
      'db_table_count'=>$receipt['tables'],
      'receipt_sha256'=>hash('sha256',wp_json_encode($receipt,JSON_UNESCAPED_SLASHES))]);
  }
  if(($entry['state']??'')!=='RESERVED')
    return $base+['state'=>'ORIGINAL_NATIVE_313_UNCERTAIN_STATE'];
  return array_merge($base,['state'=>'ORIGINAL_NATIVE_313_RESERVED',
    'install_request_sha256'=>$p['install_request_sha256'],
    'admitted_epoch'=>$entry['admitted_epoch'],
    'proof_expires_epoch'=>$p['expires']]);
 }
 private static function parse_stdin():array {
  $raw=stream_get_contents(STDIN);
  if(!is_string($raw)||strlen($raw)<100||strlen($raw)>8192)
    self::bad('SIGNED_STDIN_REQUIRED');
  $v=json_decode($raw,true);
  if(!is_array($v))self::bad('SIGNED_JSON_INVALID');
  return $v;
 }
 public static function admit():void {
  $auth=self::authority();
  if(!$auth)self::bad('ORIGINAL_GRANT_AND_312_REQUIRED');
  $g=$auth['grant'];$a=$auth['native'];$now=time();
  if(($g['expires_epoch']??0)<$now+120)self::bad('GRANT_EXPIRY_TOO_CLOSE');
  $p=self::parse_stdin();
  if(!self::verify($p,self::FIELDS,self::PROOF)
    || ($p['container']??'')!==self::value('container') || ($p['database']??'')!==self::value('database')
    || ($p['parent_site_uuid']??'')!==$g['parent_site_uuid']
    || ($p['grant_digest']??'')!==$g['grant_digest']
    || ($p['baseline_sha256']??'')!==$a['baseline_sha256']
    || !preg_match('/^[a-f0-9]{64}$/D',(string)($p['install_request_sha256']??''))
    || !preg_match('/^[a-f0-9]{32}$/D',(string)($p['nonce']??''))
    || !is_int($p['issued']??null)||!is_int($p['expires']??null)
    || $p['issued']<$now-45||$p['issued']>$now+8
    || $p['expires']<$now+5||$p['expires']>$p['issued']+120
    || $p['expires']>$g['expires_epoch'])
    self::bad('EXACT_SIGNED_DISPATCH_PROOF_REQUIRED');
  $key=self::key($g['grant_digest']);
  if(get_option($key,false)!==false)
    self::bad('EXISTING_RESERVATION_RECONCILIATION_REQUIRED');
  $entry=['state'=>'RESERVED','admitted_epoch'=>$now,'proof'=>$p];
  if(!add_option($key,['entry'=>$entry,'mac'=>self::mac($entry)],'',false))
    self::bad('ATOMIC_RESERVATION_FAILED');
  $status=self::status();
  if($status['state']!=='ORIGINAL_NATIVE_313_RESERVED')
    self::bad('RESERVATION_READBACK_UNCERTAIN');
  WP_CLI::line(wp_json_encode($status,JSON_UNESCAPED_SLASHES));
 }
 public static function complete():void {
  global $wpdb;
  $auth=self::authority();
  if(!$auth)self::bad('ORIGINAL_GRANT_AND_312_REQUIRED');
  $g=$auth['grant'];$a=$auth['native'];
  $key=self::key($g['grant_digest']);
  $old=get_option($key,false);
  if(!is_array($old)||!is_array($old['entry']??null)||!is_string($old['mac']??null)
    || !hash_equals(self::mac($old['entry']),$old['mac'])
    || ($old['entry']['state']??'')!=='RESERVED')
    self::bad('EXACT_RESERVATION_REQUIRED');
  $p=$old['entry']['proof'];
  $receipt=self::parse_stdin();$now=time();
  if(!self::verify($receipt,self::RECEIPT_FIELDS,self::RECEIPT)
     || ($receipt['grant_digest']??'')!==$g['grant_digest']
     || ($receipt['baseline_sha256']??'')!==$a['baseline_sha256']
     || ($receipt['install_request_sha256']??'')!==$p['install_request_sha256']
     || ($receipt['siteurl']??'')!==self::value('canonical_origin')
     || ($receipt['home']??'')!==self::value('canonical_origin')
     || !is_email((string)($receipt['admin_email']??''))
     || !validate_username((string)($receipt['admin_user']??''))
     || ($receipt['notification_policy']??'')!=='send_once_after_original_313_receipt'
     || !is_int($receipt['tables']??null) || $receipt['tables']<8
     || !is_int($receipt['verified_epoch']??null)
     || $receipt['verified_epoch']<$now-90||$receipt['verified_epoch']>$now+10
     || !preg_match('/^[a-f0-9]{32}$/D',(string)($receipt['nonce']??'')))
    self::bad('INDEPENDENT_SIGNED_INSTALL_READBACK_REQUIRED');
  $entry=$old['entry'];$entry['state']='RECEIPT_VERIFIED';$entry['receipt']=$receipt;
  $new=['entry'=>$entry,'mac'=>self::mac($entry)];
  $rows=$wpdb->update($wpdb->options,
    ['option_value'=>maybe_serialize($new)],
    ['option_name'=>$key,'option_value'=>maybe_serialize($old)],['%s'],['%s','%s']);
  if($rows!==1)self::bad('RECEIPT_COMMIT_CONFLICT');
  wp_cache_delete($key,'options');
  wp_cache_delete('alloptions','options');
  wp_cache_delete('notoptions','options');
  $status=self::status();
  if(($status['state']??'')!=='ORIGINAL_NATIVE_WORDPRESS_313_VERIFIED')
    self::bad('INDEPENDENT_313_READBACK_UNCERTAIN');
  WP_CLI::line(wp_json_encode($status,JSON_UNESCAPED_SLASHES));
 }
 public static function cli($args,$assoc):void {
  if(array_keys($assoc)!==['company','environment'])self::bad('EXACT_SCOPE_REQUIRED');
  self::set_scope((string)$assoc['company'],(string)$assoc['environment']);
  $action=$args[0]??'';
  if(count($args)!==1)self::bad('EXACT_ACTION_REQUIRED');
  if($action==='status'){WP_CLI::line(wp_json_encode(self::status(),JSON_UNESCAPED_SLASHES));return;}
  if($action==='admit'){self::admit();return;}
  if($action==='complete'){self::complete();return;}
  self::bad('UNKNOWN_OPERATION');
 }
 public static function boot():void {
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b company-new-site-install',[__CLASS__,'cli']);
 }
}
MAD4B_Company_Native_Bootstrap_313_V2::boot();

// Legacy class name remains available for existing integrations.
if (!class_exists('MAD4B_Wayfa_Original_Native_313_V1',false)) {
 class_alias('MAD4B_Company_Native_Bootstrap_313_V2','MAD4B_Wayfa_Original_Native_313_V1');
}
