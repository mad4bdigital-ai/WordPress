<?php
defined('ABSPATH')||exit;
/**
 * Exact signed Staging research capture transport. The original Broker
 * Ed25519 public key authenticates a one-time job+task+URL-bound request.
 * This is a separate narrow native intake, not a WP-CLI identity override.
 */
final class MAD4B_Firecrawl_Signed_Transport_V1 {
 const CONTRACT='mad4b.firecrawl.signed-stage.v1';
 const OPERATION='firecrawl.transport.research';
 const POLICY='mad4b_firecrawl_signed_transport_scope_v1';
 const NONCE='mad4b_firecrawl_signed_nonce_';
 const HEALTH_OPTION='mad4b_firecrawl_external_source_health_v1';
 const HEALTH_HOOK='mad4b_firecrawl_external_source_health_check_v1';
 const FIELDS=['contract','action','host_id','company','site_uuid','canonical_origin',
  'environment','operation','policy_sha256','job_id','task_id','draft_post_id',
  'source_sha256','issued','expires','nonce'];
 public static function boot(){
  add_filter('cron_schedules',[__CLASS__,'monitor_schedule']);
  add_action('init',[__CLASS__,'schedule_monitor'],99);
  add_action(self::HEALTH_HOOK,[__CLASS__,'monitor_source'],10);
  add_action('rest_api_init',static function(){
   register_rest_route('mad4b/v1','/firecrawl-signed-stage',[
    'methods'=>'POST','callback'=>[__CLASS__,'capture'],
    'permission_callback'=>'__return_true']);
  },70);
 }
 public static function monitor_schedule($schedules){
  $schedules['mad4b_firecrawl_source_15m']=[
   'interval'=>900,'display'=>'MAD4B external research source health (15 min)'];
  return $schedules;
 }
 public static function schedule_monitor(){
  if(wp_get_environment_type()!=='staging' ||
    wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return;
  if(!wp_next_scheduled(self::HEALTH_HOOK))
   wp_schedule_event(time()+60,'mad4b_firecrawl_source_15m',self::HEALTH_HOOK);
 }
 public static function monitor_source(){
  if(wp_get_environment_type()!=='staging' ||
     wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return;
  $policy=get_option(self::POLICY,[]);
  if(!is_array($policy)||($policy['job_id']??'')!=='1b6aa5fb-e82d-42bc-9bad-6dcacf8d210f'||
    ($policy['task_id']??'')!=='2e40340f-30af-4482-a3a4-279cea708447'||
    (int)($policy['draft_post_id']??0)!==68948)return;
  $url=(string)get_post_meta(68948,'_mad4b_source_research_url',true);
  if($url==='' || !hash_equals((string)$policy['source_sha256'],hash('sha256',$url)))return;
  $before=get_option(self::HEALTH_OPTION,[]);
  $health=self::source_reachability($url,true);
  $healthy=!empty($health['reachable']) &&
    (int)($health['http_status']??0)>=200 &&
    (int)($health['http_status']??0)<400;
  $status=[
   'contract'=>'mad4b.firecrawl.external-source-health.v1',
   'source_sha256'=>hash('sha256',$url),
   'job_id'=>$policy['job_id'],'task_id'=>$policy['task_id'],
   'state'=>$healthy?'SOURCE_REACHABLE_REQUIRES_SIGNED_RETRY':'WAITING_EXTERNAL_SOURCE',
   'http_status'=>(int)($health['http_status']??0),
   'reason'=>(string)($health['reason']??'unknown'),
   'last_checked_at'=>gmdate('c'),
   'signed_policy_active'=>!empty($policy['enabled']),
   'capture_performed'=>false,
   'job_changed'=>false];
  $changed=!is_array($before)||($before['state']??'')!==$status['state'];
  update_option(self::HEALTH_OPTION,$status,false);
  if($changed){
   do_action('mad4b_firecrawl_source_health_transition',
     $status,(string)($before['state']??'unknown'));
  }
 }
 public static function health_status(){
  return get_option(self::HEALTH_OPTION,[]);
 }
 public static function classify_source($url,$code=0){
  $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
  $government=$host==="gov.eg"||str_ends_with($host,".gov.eg")||$host==="gov.uk"||str_ends_with($host,".gov.uk")||str_ends_with($host,".gov");
  $signal=$code===429?"rate_limited":(($code===401||$code===403)?"access_restricted":($code>=500?"upstream_error":($code>=200&&$code<400?"reachable":"unverified")));
  return ["site_class"=>$government?"government":"general","signal"=>$signal,"protection_confirmed"=>false,"allow_bypass"=>false];
 }
 private static function source_reachability($url,$force=false){
  if(!is_string($url) || strlen($url)>1500 ||
     !wp_http_validate_url($url) || wp_parse_url($url,PHP_URL_SCHEME)!=='https')
   return ['reachable'=>false,'reason'=>'source_url_invalid','checked'=>false];
  $key='mad4b_fc_source_probe_'.substr(hash('sha256',$url),0,36);
  if(!$force){
   $cached=get_transient($key);
   if(is_array($cached))return $cached;
  }
  $response=wp_safe_remote_head($url,['timeout'=>5,'redirection'=>0,
   'sslverify'=>true,'limit_response_size'=>2048,'headers'=>['Accept'=>'text/html']]);
  $success=!is_wp_error($response);
  $http=$success?(int)wp_remote_retrieve_response_code($response):0;
  // 405 means HEAD is unsupported, not that TCP/TLS is unreachable.
  // 401/403 prove network reachability but do NOT authorize content capture.
  $status=['classification'=>self::classify_source($url,$http),
   'reachable'=>$success && $http>0,
   'reason'=>!$success?'network_or_tls_unreachable':
     ($http>=500?'upstream_http_5xx':($http===429?'upstream_rate_limited':
     ($http>=400?'upstream_http_error':'reachable'))),
   'http_status'=>$http,'checked'=>true,'checked_at'=>gmdate('c'),
   'source_sha256'=>hash('sha256',$url)];
  set_transient($key,$status,$status['reachable']?120:300);
  return $status;
 }
 public static function source_readiness($draft_post_id){
  if(!current_user_can('manage_options') ||
    wp_get_environment_type()!=='staging')
    return self::deny('readiness_admin_required');
  $id=absint($draft_post_id);
  if(!$id || get_post_type($id)!=='transportations' || get_post_status($id)!=='draft')
    return self::deny('readiness_source_invalid',422);
  return self::source_reachability((string)get_post_meta($id,'_mad4b_source_research_url',true),true);
 }
 private static function deny($code,$http=403){
  return new WP_Error('mad4b_firecrawl_signed_'.$code,$code,['status'=>$http]);
 }
 private static function verify($proof){
  if(!is_array($proof)||array_keys($proof)!==array_merge(self::FIELDS,['signature']))
   return self::deny('proof_shape',422);
  $now=time();
  foreach(self::FIELDS as $field)
   if(!is_string($proof[$field])&&!is_int($proof[$field]))return self::deny('proof_type',422);
  if($proof['contract']!==self::CONTRACT || $proof['action']!=='capture' ||
   $proof['host_id']!=='srv2030908' || $proof['company']!=='tours-central' ||
   $proof['canonical_origin']!==rtrim(home_url(),'/') ||
   $proof['environment']!=='staging' || $proof['operation']!==self::OPERATION ||
   wp_get_environment_type()!=='staging' ||
   !class_exists('MAD4B_SCP_Site_Profile',false) ||
   $proof['site_uuid']!==(string)MAD4B_SCP_Site_Profile::site_uuid() ||
   !is_int($proof['issued'])||!is_int($proof['expires'])||
   $proof['issued']<$now-60 || $proof['issued']>$now+10 ||
   $proof['expires']<$now||$proof['expires']>$proof['issued']+60 ||
   !preg_match('/^[a-f0-9]{32}$/D',$proof['nonce']) ||
   !preg_match('/^[a-f0-9]{64}$/D',$proof['source_sha256']) ||
   !preg_match('/^[a-f0-9]{64}$/D',$proof['policy_sha256']) ||
   !wp_is_uuid($proof['job_id']) || !wp_is_uuid($proof['task_id']) ||
   !is_int($proof['draft_post_id'])||$proof['draft_post_id']<1)
   return self::deny('exact_scope',403);
  $policy=get_option(self::POLICY,false);
  if(!is_array($policy)||($policy['enabled']??false)!==true ||
   ($policy['contract']??'')!==self::CONTRACT ||
   ($policy['operation']??'')!==self::OPERATION ||
   ($policy['site_uuid']??'')!==$proof['site_uuid'] ||
   ($policy['job_id']??'')!==$proof['job_id'] ||
   ($policy['task_id']??'')!==$proof['task_id'] ||
   (int)($policy['draft_post_id']??0)!==$proof['draft_post_id'] ||
   ($policy['source_sha256']??'')!==$proof['source_sha256'] ||
   ($policy['host_id']??'')!==$proof['host_id'] ||
   (int)($policy['expires_at']??0)<$now)
   return self::deny('scoped_policy_missing');
  if(!class_exists('MAD4B_Native_VPS_Principal_V1',false))
   return self::deny('native_principal_unavailable');
  $slot=MAD4B_Native_VPS_Principal_V1::existing();
  $scope=MAD4B_Native_VPS_Principal_V1::scope();
  if(!is_array($slot)||!is_array($scope)||
   !MAD4B_Native_VPS_Principal_V1::ledger($slot,$scope)||
   !hash_equals((string)($slot['policy_sha256']??''),$proof['policy_sha256']))
   return self::deny('original_host_policy_invalid');
  $signature=base64_decode((string)$proof['signature'],true);
  $public=class_exists('MAD4B_Central_VPS_Broker_V1',false)
    ?base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true):false;
  if(!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES||
   !is_string($public)||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
   return self::deny('original_key_unavailable');
  $canonical=implode("\n",array_map(static fn($k)=>(string)$proof[$k],self::FIELDS));
  if(!sodium_crypto_sign_verify_detached($signature,$canonical,$public))
   return self::deny('original_broker_signature');
  // Existing owner-bound NHI and exact Staging-only Ability grant are mandatory.
  if(!class_exists('MAD4B_SCP_Agent_Registry',false))
   return self::deny('original_agent_registry_missing');
  $agent=MAD4B_SCP_Agent_Registry::get_agent_by_slug('chatgpt-governed-write');
  if(!is_array($agent)||($agent['status']??'')!=='enabled'||
   ($agent['environment']??'')!=='staging'||(int)$agent['wp_user_id']!==1)
   return self::deny('original_agent_mismatch');
  $hosts=MAD4B_SCP_Agent_Registry::subjects_for_agent((int)$agent['id'],'host_operator');
  if(count(array_filter($hosts,static fn($row)=>($row['status']??'')==='enabled'))!==1)
   return self::deny('original_host_not_bound');
  $grant=MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'],'mad4b-write',
    MAD4B_Firecrawl_Research_Bridge_V1::ABILITY,'core');
  if(is_wp_error($grant)||($grant['effect']??'')!=='allow'||
   ($grant['environment']??'')!=='staging')
   return self::deny('exact_write_grant_missing');
  // Re-read the original selected task; never capture arbitrary signed jobs.
  global $wpdb;
  $table=$wpdb->prefix.'mad4b_hub_bulk_tasks';
  $task=$wpdb->get_row($wpdb->prepare(
   "SELECT * FROM $table WHERE task_id=%s AND content_job_id=%s LIMIT 1",
   $proof['task_id'],$proof['job_id']),ARRAY_A);
  if(!is_array($task)||$task['operation']!=='stage_work'||
   $task['stage']!=='SITE_DISCOVERY' ||
   !in_array($task['state'],['QUEUED','WAITING_EXECUTOR'],true))
   return self::deny('original_task_state_changed',409);
  $batch=$wpdb->prefix.'mad4b_hub_bulk_batches';
  $bstate=$wpdb->get_var($wpdb->prepare(
   "SELECT state FROM $batch WHERE batch_id=%s",$task['batch_id']));
  if($bstate!=='ACTIVE')return self::deny('batch_not_active',409);
  $payload=json_decode((string)$task['payload_json'],true);
  $input=$payload['stage_input']??null;
  if(!is_array($input)||($payload['stage_adapter_id']??'')!==
    MAD4B_Firecrawl_Research_Bridge_V1::ID ||
   ($input['job_id']??'')!==$proof['job_id'] ||
   (int)($input['draft_post_id']??0)!==$proof['draft_post_id'] ||
   !is_string($input['source_url']??null) ||
   !hash_equals(hash('sha256',$input['source_url']),$proof['source_sha256']))
   return self::deny('original_task_input_drift',409);
  // Fail fast for externally unreachable sources before consuming the
  // single-use nonce or launching an expensive Firecrawl scrape.
  $reach=self::source_reachability($input['source_url']);
  if(!$reach['reachable'])
   return self::deny('source_unreachable',503);
  if(($reach['http_status']??0)>=400 && ($reach['http_status']??0)!==405)
   return self::deny('source_http_unavailable',503);
  if(!add_option(self::NONCE.$proof['nonce'],gmdate('c'),'',false))
   return self::deny('nonce_replay',409);
  $host_subject=array_values(array_filter($hosts,static fn($row)=>($row['status']??'')==='enabled'))[0];
  return ['input'=>$input,'task_id'=>$task['task_id'],
    'batch_id'=>$task['batch_id'],'job_id'=>$proof['job_id'],
    'owner_user_id'=>(int)$agent['wp_user_id'],
    'host_subject_fingerprint'=>(string)$host_subject['subject_fingerprint']];
 }
 public static function capture($request){
  if(!($request instanceof WP_REST_Request))return self::deny('invalid_request',422);
  $params=$request->get_json_params();
  if(!is_array($params)||array_keys($params)!==['proof'])
   return self::deny('payload_shape',422);
  $verified=self::verify($params['proof']);
  if(is_wp_error($verified))return $verified;
  $input=$verified['input'];
  // Lock exact post/job to prevent duplicate capture from different valid nonces.
  global $wpdb;
  $lock='mad4b_firecrawl_research_'.md5($verified['job_id']);
  $lock_acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));
  if((int)$lock_acquired!==1){
   $lock_owner=$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)',$lock));
   error_log('MAD4B_FIRECRAWL_LOCK_DIAGNOSTIC '.wp_json_encode([
    'result'=>$lock_acquired,'owner'=>$lock_owner,
    'connection'=>$wpdb->get_var('SELECT CONNECTION_ID()'),
    'db_error'=>$wpdb->last_error]));
   return self::deny('capture_locked',409);
  }
  try {
   // The signed host principal is already bound to this exact enrolled owner.
   // Restore WordPress request state in all paths; no persistent login is made.
   $previous_user=get_current_user_id();
   try {
    wp_set_current_user((int)$verified['owner_user_id']);
    $authenticated_host_context=static function($context)use($verified){
     if(!is_array($context))return $context;
     return array_merge($context,[
       'authenticated'=>true,
       'subject_type'=>'host_operator',
       'subject_fingerprint'=>$verified['host_subject_fingerprint'],
       'auth_method'=>'original_broker_ed25519',
       'origin'=>'signed_native_host',
       'wp_user_id'=>(int)$verified['owner_user_id']
     ]);
    };
    add_filter('mad4b_scp_authenticated_subject_context',$authenticated_host_context,99);
    try {
     // The canonical SCP Ability, not a direct Bridge callback, owns the final
     // authorization including agent, grant, resource and approval gates.
     $ability=wp_get_ability(MAD4B_Firecrawl_Research_Bridge_V1::ABILITY);
     if(!$ability)return self::deny('original_ability_missing',503);
     $allowed=$ability->check_permissions($input);
     if(is_wp_error($allowed))return $allowed;
     if($allowed!==true)return self::deny('original_authorization_denied');
     $receipt=MAD4B_Firecrawl_Research_Bridge_V1::with_signed_native_admission(
       $input,static fn()=>$ability->execute($input));
    } finally {
     remove_filter('mad4b_scp_authenticated_subject_context',$authenticated_host_context,99);
    }
   } finally {wp_set_current_user($previous_user);}
   if(is_wp_error($receipt)){
    error_log('MAD4B_FIRECRAWL_CAPTURE_RESULT '.wp_json_encode([
     'state'=>'provider_or_artifact_error',
     'error_code'=>$receipt->get_error_code(),'job_id'=>$verified['job_id']]));
    return $receipt;
   }
   error_log('MAD4B_FIRECRAWL_CAPTURE_RESULT '.wp_json_encode([
    'state'=>'receipt_returned','job_id'=>$verified['job_id'],
    'has_artifact'=>is_array($receipt)&&!empty($receipt['artifact_id'])]));
   if(!is_array($receipt)||($receipt['state']??'')!=='CAPTURED_FOR_REVIEW'||
      empty($receipt['artifact_id']))return self::deny('receipt_invalid',500);
   $artifact=MAD4B_SCP_Artifacts::get_artifact(['artifact_id'=>$receipt['artifact_id']]);
   if(is_wp_error($artifact)||($artifact['artifact']['job_id']??'')!==$verified['job_id'])
    return self::deny('independent_artifact_readback_failed',500);
   return [
    'contract'=>self::CONTRACT,'state'=>'CAPTURED_FOR_REVIEW',
    'task_id'=>$verified['task_id'],'job_id'=>$verified['job_id'],
    'artifact_id'=>$receipt['artifact_id'],'source_sha256'=>$receipt['source_sha256'],
    'receipt_sha256'=>hash('sha256',wp_json_encode($receipt)),
    'independent_artifact_readback'=>true,'publish_authorized'=>false,
    'queue_completion_claimed'=>false,'signed_request_verified'=>true];
  } finally {
   $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
  }
 }
}
MAD4B_Firecrawl_Signed_Transport_V1::boot();
