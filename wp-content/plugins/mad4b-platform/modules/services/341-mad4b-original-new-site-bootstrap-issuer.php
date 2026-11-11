<?php
/**
 * Plugin Name: MAD4B Original New-Site Bootstrap Issuer
 * Description: Owner-authenticated, exact-site bootstrap grants. Never executes WP-CLI.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Original_New_Site_Bootstrap_Issuer_V1 {
 const CONTRACT='mad4b.original-new-site-bootstrap-grant.v1';
 const STORE='mad4b_original_new_site_bootstrap_grant_v1_';
 const PROOF_CONTRACT='mad4b.new-site-host-bootstrap-proof.v1';
 const PROOF_FIELDS=['contract','company','environment','site_uuid','canonical_origin',
  'container','database','parent_site_uuid','parent_origin','issued','expires','nonce'];
 const NS='mad4b-central/v1';
 const DEFAULT_GRANT_LIFETIME_SECONDS=7200;
 const RENEW_WINDOW_SECONDS=600;
 const HISTORY_PREFIX='mad4b_original_new_site_bootstrap_grant_history_v1_';
 private static function denied(string $code,int $http=403) {
  return new WP_Error('mad4b_new_site_bootstrap_'.$code,$code,['status'=>$http]);
 }
 private static function central():bool {
  return defined('DB_NAME') && DB_NAME==='etg_commerce_staging'
   && wp_get_environment_type()==='staging'
   && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com'
   && class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
   && class_exists('MAD4B_Company_Operation_Admission_V1',false)
   && class_exists('MAD4B_Company_Environment_Registry_V2',false);
 }
 public static function actor(WP_REST_Request $r):bool {
  if(!self::central() || (defined('WP_CLI') && WP_CLI)
   || !is_user_logged_in() || !current_user_can('manage_options')
   || !current_user_can('activate_plugins'))return false;
  $nonce=(string)$r->get_header('X-WP-Nonce');
  return $nonce!=='' ? wp_verify_nonce($nonce,'wp_rest')!==false
   : (class_exists('MAD4B_Company_Manager_Unified_Administrator_Principal',false)
      && MAD4B_Company_Manager_Unified_Administrator_Principal::verified());
 }
 private static function authorized(string $company,int $uid):bool {
  $m=MAD4B_Company_Operation_Admission_V1::manager_id();
  return $m!=='' && $uid>0 && MAD4B_Company_Portfolio_Manager_V1::has_delegation(
    $m,$company,'operations_manage',$uid);
 }
 private static function parent(string $company,string $environment):array {
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
   || !in_array($environment,['staging','production'],true))
    return [];
  $r=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
  if(!is_array($r) || ($r['company_id']??null)!==$company
   || ($r['environment']??null)!==$environment
   || !wp_is_uuid((string)($r['site_uuid']??'')))return [];
  return $r;
 }
 private static function key(string $company,string $environment):string {
  return self::STORE.$company.'_'.$environment;
 }
 private static function mac(array $record):string {
  return hash_hmac('sha256',wp_json_encode($record,JSON_UNESCAPED_SLASHES),
      wp_salt('auth'));
 }
 private static function verify_host_proof($proof,string $company,string $environment,
  string $uuid,string $origin,string $container,string $database,
  string $parentuuid,string $parentorigin):bool {
  if(!is_array($proof)
    || array_keys($proof)!==array_merge(self::PROOF_FIELDS,['signature'])
    || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
    || !function_exists('sodium_crypto_sign_verify_detached'))return false;
  foreach(self::PROOF_FIELDS as $field)if(!is_string($proof[$field])&&!is_int($proof[$field]))return false;
  if($proof['contract']!==self::PROOF_CONTRACT
    || $proof['company']!==$company || $proof['environment']!==$environment
    || $proof['site_uuid']!==$uuid || $proof['canonical_origin']!==$origin
    || $proof['container']!==$container || $proof['database']!==$database
    || $proof['parent_site_uuid']!==$parentuuid || $proof['parent_origin']!==$parentorigin
    || !is_int($proof['issued']) || !is_int($proof['expires'])
    || $proof['issued']<time()-60 || $proof['issued']>time()+10
    || $proof['expires']<time() || $proof['expires']>$proof['issued']+60
    || !preg_match('/^[a-f0-9]{32}$/D',(string)$proof['nonce']))return false;
  $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
  $sig=base64_decode((string)($proof['signature']??''),true);
  if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    || !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)return false;
  $text=implode("\n",array_map(static fn($field)=>(string)$proof[$field],self::PROOF_FIELDS));
  return sodium_crypto_sign_verify_detached($sig,$text,$pub);
 }
 public static function status(string $company,string $environment):array {
  $base=['contract'=>self::CONTRACT,'company'=>$company,'environment'=>$environment,
   'grant_present'=>false,'bootstrap_execution_authorized'=>false,
   'native_executor_certified'=>false,
   'wordpress_install_executed'=>false];
  if(!self::central())return $base+['state'=>'NOT_CENTRAL'];
  // Surface a verified Company Manager reservation without granting authority.
  // Private provisioning reservations are not existing parent identities.
  if(method_exists('MAD4B_Company_Environment_Registry_V2','reserved_target')) {
    $pending=MAD4B_Company_Environment_Registry_V2::reserved_target($company,$environment);
    if(is_array($pending)) {
      $base['pending_reservation']=$pending;
      $base['pending_reservation_is_execution_authority']=false;
    }
  }
  $stored=get_option(self::key($company,$environment),false);
  if(!is_array($stored)||!is_array($stored['grant']??null)||!is_string($stored['mac']??null))
   return $base+['state'=>'NOT_ISSUED'];
  $g=$stored['grant'];
  if(!hash_equals(self::mac($g),$stored['mac'])
   || ($g['contract']??null)!==self::CONTRACT
   || ($g['company']??null)!==$company || ($g['environment']??null)!==$environment)
    return $base+['state'=>'GRANT_INTEGRITY_OR_SCOPE_INVALID'];
  $rev=(int)($g['revision']??1);
  if($rev<1 || $rev>1000) return $base+['state'=>'GRANT_REVISION_INVALID'];
  $lifecycle=['revision'=>$rev,'previous_grant_digest'=>$g['grant_digest']??null,
    'previous_expires_epoch'=>$g['expires_epoch']??null,
    'grant_lifetime_seconds'=>(int)($g['expires_epoch']??0)-(int)($g['created_epoch']??0),
    'renewal_requires_authenticated_owner'=>true,'renewal_eligible'=>false];
  if(($g['state']??'')==='ORIGINAL_NEW_SITE_BOOTSTRAP_REVOKED')
    return array_merge($base,$lifecycle,['state'=>'ORIGINAL_NEW_SITE_BOOTSTRAP_REVOKED']);
  if(($g['state']??'')==='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'
    && is_int($g['expires_epoch']??null) && $g['expires_epoch']<=time()
    && self::authorized($company,(int)($g['owner_user_id']??0))) {
    $parent=self::parent($company,$environment);
    $renewable=$parent && ($parent['site_uuid']??null)===($g['parent_site_uuid']??null)
      && ($parent['canonical_origin']??null)===($g['parent_origin']??null);
    return array_merge($base,$lifecycle,['state'=>'DENIED_OR_EXPIRED',
      'renewal_eligible'=>(bool)$renewable,
      'expiry_behavior'=>'PAUSED_OWNER_RENEWAL_REQUIRED']);
  }
  if(($g['contract']??null)!==self::CONTRACT
   || ($g['company']??null)!==$company || ($g['environment']??null)!==$environment
   || ($g['state']??'')!=='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'
   || !is_int($g['expires_epoch']??null) || $g['expires_epoch']<=time()
   || !is_int($g['owner_user_id']??null)
   || !self::authorized($company,$g['owner_user_id']))return $base+['state'=>'DENIED_OR_EXPIRED'];
  $parent=self::parent($company,$environment);
  if(!$parent || ($parent['site_uuid']??null)!==($g['parent_site_uuid']??null)
     || ($parent['canonical_origin']??null)!==($g['parent_origin']??null))
    return $base+['state'=>'ORIGINAL_PARENT_SITE_REVOKED_OR_CHANGED'];
  return array_merge($base,$lifecycle,[
   'state'=>'ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED',
   'renewal_eligible'=>($g['expires_epoch']-time())<=self::RENEW_WINDOW_SECONDS,
   'renewal_window_seconds'=>self::RENEW_WINDOW_SECONDS,
   'grant_present'=>true,
   'original_issuer_grant_issued'=>true,
   'bootstrap_execution_authorized'=>false,
   'separate_native_executor_admission_required'=>true,
   'new_site_uuid'=>$g['new_site_uuid'],'new_origin'=>$g['new_origin'],
   'target_container'=>$g['target_container'],'target_database'=>$g['target_database'],
   'parent_site_uuid'=>$g['parent_site_uuid'],
   'grant_digest'=>$g['grant_digest'],'expires_epoch'=>$g['expires_epoch'],
   'created_epoch'=>$g['created_epoch'],'owner_originally_authenticated'=>true]);
 }
 public static function issue(WP_REST_Request $request) {
  if(!self::actor($request))return self::denied('original_owner_required');
  // A new tenant must originate from an ORIGINAL sealed live demo seed, never
  // a blank wp core install with later partial SQL/plugin patching. This gate
  // never affects existing installed tenants or grants original authority.
  if(!class_exists('MAD4B_Company_Seed_Admission_V1',false) ||
     !MAD4B_Company_Seed_Admission_V1::eligible())
    return self::denied('original_sealed_demo_seed_required',409);
  $company=sanitize_key((string)$request->get_param('company'));
  $body=$request->get_json_params();
  if(!is_array($body))return self::denied('payload_required',422);
  $env=(string)($body['environment']??'');
  $parent=self::parent($company,$env);
  $owner=get_current_user_id();
  if(!$parent || !self::authorized($company,$owner))
   return self::denied('original_company_delegation_required');
  $uuid=(string)($body['site_uuid']??'');
  $origin=(string)($body['canonical_origin']??'');
  $container=(string)($body['container']??'');
  $database=(string)($body['database']??'');
  $parentuuid=(string)($body['parent_site_uuid']??'');
  $expected=(int)($body['expected_revision']??-1);
  if(!wp_is_uuid($uuid) || $uuid===$parent['site_uuid']
   || $parentuuid!==$parent['site_uuid']
   || !preg_match('~^https://[a-z0-9.-]+$~D',$origin)
   || $origin===$parent['canonical_origin']
   || !preg_match('/^[a-z][a-z0-9-]{4,70}-php$/D',$container)
   || !preg_match('/^[a-z][a-z0-9_]{4,80}$/D',$database)
   || ($body['bootstrap_mode']??null)!=='isolated_candidate_only'
   || ($body['keep_parent_operational']??null)!==true
   || ($body['operation']??null)!=='new-site.wordpress.bootstrap'
   || $expected<0 || $expected>=1000)
   return self::denied('exact_new_site_scope_invalid',422);
  $proof=$body['host_evidence']??null;
  if(!self::verify_host_proof($proof,$company,$env,$uuid,$origin,$container,
      $database,(string)$parent['site_uuid'],(string)$parent['canonical_origin']))
    return self::denied('independent_signed_host_binding_required',403);
  $key=self::key($company,$env);
  $stored=get_option($key,false);
  $prior=null;
  $revision=1;
  if($stored!==false) {
    if(!is_array($stored)||!is_array($stored['grant']??null)
       || !is_string($stored['mac']??null)
       || !hash_equals(self::mac($stored['grant']),$stored['mac']))
      return self::denied('existing_grant_integrity_invalid',409);
    $prior=$stored['grant'];
    $revision=(int)($prior['revision']??1)+1;
    $same_scope=($prior['contract']??'')===self::CONTRACT
       && ($prior['state']??'')==='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'
       && ($prior['company']??'')===$company
       && ($prior['environment']??'')===$env
       && ($prior['new_site_uuid']??'')===$uuid
       && ($prior['new_origin']??'')===$origin
       && ($prior['target_container']??'')===$container
       && ($prior['target_database']??'')===$database
       && ($prior['parent_site_uuid']??'')===$parentuuid
       && ($prior['parent_origin']??'')===$parent['canonical_origin'];
    if(!$same_scope || $revision>1000
       || !is_int($prior['expires_epoch']??null)
       || $prior['expires_epoch']>time()+self::RENEW_WINDOW_SECONDS
       || ($body['renew_previous_digest']??null)!==($prior['grant_digest']??null)
       || $expected!==$revision-1)
       return self::denied('owner_authenticated_exact_renewal_required',409);
  } elseif($expected!==0 || isset($body['renew_previous_digest'])) {
    return self::denied('fresh_grant_revision_required',409);
  }
  $now=time();
  $g=['contract'=>self::CONTRACT,'state'=>'ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED',
   'company'=>$company,'environment'=>$env,'new_site_uuid'=>$uuid,
   'new_origin'=>$origin,'target_container'=>$container,'target_database'=>$database,
   'parent_site_uuid'=>$parentuuid,'parent_origin'=>$parent['canonical_origin'],
   'owner_user_id'=>$owner,'created_epoch'=>$now,
   'expires_epoch'=>$now+self::DEFAULT_GRANT_LIFETIME_SECONDS,
   'revision'=>$revision,'renewed_from_digest'=>$prior['grant_digest']??null,
   'operation'=>'new-site.wordpress.bootstrap','nonce'=>bin2hex(random_bytes(16)),
   'host_proof_sha256'=>hash('sha256',wp_json_encode($proof,JSON_UNESCAPED_SLASHES)),
   'native_executor_authorized'=>false];
  $g['grant_digest']=hash('sha256',wp_json_encode($g,JSON_UNESCAPED_SLASHES));
  $record=['grant'=>$g,'mac'=>self::mac($g)];
  if($prior===null) {
    if(!add_option($key,$record,'',false))
      return self::denied('issuer_persistence_or_conflict',409);
  } else {
    // Preserve the exact old grant as immutable history before replacing it.
    $history=self::HISTORY_PREFIX.$company.'_'.$env.'_'.$prior['grant_digest'];
    $existing=get_option($history,false);
    if($existing===false) {
      if(!add_option($history,$stored,'',false))
        return self::denied('prior_grant_history_not_persisted',503);
    } elseif($existing!==$stored) {
      return self::denied('prior_grant_history_integrity_conflict',409);
    }
    // Compare-and-swap on the serialized original record; concurrent owner
    // tabs cannot silently extend, shorten or replace each other's grant.
    global $wpdb;
    $old_serialized=maybe_serialize($stored);
    $new_serialized=maybe_serialize($record);
    $rows=$wpdb->update($wpdb->options,['option_value'=>$new_serialized],
       ['option_name'=>$key,'option_value'=>$old_serialized],['%s'],['%s','%s']);
    if($rows!==1)return self::denied('grant_renewal_compare_and_swap_conflict',409);
    wp_cache_delete($key,'options');
    wp_cache_delete('alloptions','options');
    wp_cache_delete('notoptions','options');
  }
  $confirmed=self::status($company,$env);
  if(($confirmed['state']??'')!=='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'
   || ($confirmed['grant_digest']??'')!==$g['grant_digest'])
    return self::denied('issuer_readback_uncertain',503);
  $response=rest_ensure_response(['contract'=>self::CONTRACT,
   'state'=>'ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED','company'=>$company,
   'environment'=>$env,'grant_digest'=>$g['grant_digest'],
   'expires_epoch'=>$g['expires_epoch'],'revision'=>$revision,
   'lifetime_seconds'=>self::DEFAULT_GRANT_LIFETIME_SECONDS,
   'renewed_from_digest'=>$prior['grant_digest']??null,
   'job_executed'=>false]);
  $response->header('Cache-Control','no-store, private');
  return $response;
 }
 public static function revoke(WP_REST_Request $request) {
  if(!self::actor($request))return self::denied('original_owner_required');
  $company=sanitize_key((string)$request->get_param('company'));
  $env=(string)$request->get_param('environment');
  $owner=get_current_user_id();
  if(!self::parent($company,$env) || !self::authorized($company,$owner))
    return self::denied('original_company_delegation_required');
  $body=$request->get_json_params();
  if(!is_array($body) || ($body['operation']??null)!=='new-site.wordpress.bootstrap'
     || ($body['expected_state']??null)!=='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'
     || !is_string($body['grant_digest']??null)
     || !preg_match('/^[a-f0-9]{64}$/D',$body['grant_digest']))
     return self::denied('exact_revocation_scope_required',422);
  $key=self::key($company,$env);
  $stored=get_option($key,false);
  if(!is_array($stored)||!is_array($stored['grant']??null)
    || !is_string($stored['mac']??null))return self::denied('grant_missing',404);
  $g=$stored['grant'];
  if(!hash_equals(self::mac($g),$stored['mac']))
    return self::denied('grant_integrity_invalid',409);
  if(($g['state']??'')==='ORIGINAL_NEW_SITE_BOOTSTRAP_REVOKED')
    return self::denied('already_revoked',409);
  if(($g['state']??'')!=='ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'
    || ($g['company']??null)!==$company
    || ($g['environment']??null)!==$env
    || !hash_equals((string)($g['grant_digest']??''),$body['grant_digest']))
    return self::denied('exact_grant_revoke_mismatch',409);
  $g['state']='ORIGINAL_NEW_SITE_BOOTSTRAP_REVOKED';
  $g['revoked_epoch']=time();
  if(!update_option($key,['grant'=>$g,'mac'=>self::mac($g)],false))
    return self::denied('original_revocation_persistence_uncertain',503);
  $check=self::status($company,$env);
  if(($check['state']??'')!=='ORIGINAL_NEW_SITE_BOOTSTRAP_REVOKED')
    return self::denied('revocation_readback_uncertain',503);
  return rest_ensure_response(['contract'=>self::CONTRACT,
   'state'=>'ORIGINAL_NEW_SITE_BOOTSTRAP_REVOKED',
   'company'=>$company,'environment'=>$env,
   'grant_digest'=>$body['grant_digest'],
   'native_executor_execution_claimed'=>false]);
 }
 public static function read(WP_REST_Request $request) {
  if(!self::actor($request))return self::denied('original_owner_required');
  $company=sanitize_key((string)$request->get_param('company'));
  $env=(string)$request->get_param('environment');
  return rest_ensure_response(self::status($company,$env));
 }
 public static function cli(array $args,array $assoc):void {
  if(($args[0]??'')!=='status'
   || array_keys($assoc)!==['company','environment'])
    WP_CLI::error('STATUS_READ_ONLY_ORIGINAL_OWNER_REST_REQUIRED');
  WP_CLI::line(wp_json_encode(self::status((string)$assoc['company'],
      (string)$assoc['environment']),JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
   WP_CLI::add_command('mad4b company-new-site-bootstrap',[__CLASS__,'cli']);
  }
  add_action('rest_api_init',static function(){
   $route='/companies/(?P<company>[a-z][a-z0-9-]{2,47})/new-site-bootstrap';
   register_rest_route(self::NS,$route,[
    ['methods'=>WP_REST_Server::READABLE,'callback'=>[__CLASS__,'read'],
     'permission_callback'=>[__CLASS__,'actor']],
    ['methods'=>WP_REST_Server::CREATABLE,'callback'=>[__CLASS__,'issue'],
     'permission_callback'=>[__CLASS__,'actor']],
    ['methods'=>WP_REST_Server::DELETABLE,'callback'=>[__CLASS__,'revoke'],
     'permission_callback'=>[__CLASS__,'actor']]
   ]);
  });
 }
}
MAD4B_Original_New_Site_Bootstrap_Issuer_V1::boot();
