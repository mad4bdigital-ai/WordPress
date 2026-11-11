<?php
/**
 * Plugin Name: MAD4B Dynamic Bulk Runtime Resolver
 * Description: Live ability/operation discovery, stage-neutral intake, scoped executor opt-in and client handoff.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Dynamic_Bulk_Runtime_V1 {
 const CONTRACT='mad4b.hub.dynamic-bulk-runtime.v1';
 const OPTION='mad4b_hub_dynamic_runtime_optins_v1';
 const MAX_OPTINS=250;
 public static function site():bool{
  return wp_get_environment_type()==='staging' &&
   strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))==='tours.dev.mad4b.com';
 }
 public static function readable():bool {
  return self::site() && class_exists('MAD4B_Hub_Bulk_Jobs_Queue') &&
   MAD4B_Hub_Bulk_Jobs_Queue::readable();
 }
 public static function writable():bool {
  return self::readable() && MAD4B_Hub_Bulk_Jobs_Queue::writable();
 }
 private static function err($code,$status=409) {
  return new WP_Error('mad4b_runtime_'.$code,'Dynamic runtime request rejected',
   ['status'=>$status,'contract'=>self::CONTRACT]);
 }
 private static function valid_scope($value,$kind):bool {
  if(!is_string($value))return false;
  if($kind==='company')return (bool)preg_match('/^[a-z][a-z0-9-]{2,69}$/D',$value);
  if($kind==='target')return (bool)preg_match('/^[a-z0-9][a-zA-Z0-9._:\/-]{2,190}$/D',$value);
  if($kind==='operation')return (bool)preg_match('/^[a-z][a-z0-9._:-]{2,127}$/D',$value);
  return false;
 }
 private static function input($r) {
  if(!is_array($r) || array_diff(array_keys($r),[
   'company_id','target_ref','operation','stage','ability_name','intent',
   'phase','executor','provider_id','batch_key','task_key','note','opt_in','payload']))
   return self::err('invalid_fields',400);
  $company=(string)($r['company_id']??'');
  $target=(string)($r['target_ref']??'');
  $operation=(string)($r['operation']??'');
  $stage=strtoupper((string)($r['stage']??''));
  $ability=(string)($r['ability_name']??'');
  $intent=(string)($r['intent']??'read');
  $phase=(string)($r['phase']??'plan');
  $executor=(string)($r['executor']??'');
  $provider=(string)($r['provider_id']??'');
  if(!self::valid_scope($company,'company')||
   !self::valid_scope($target,'target')||
   !self::valid_scope($operation,'operation') ||
   ($stage!==''&&!in_array($stage,MAD4B_Hub_Bulk_Jobs_Queue::stages(),true)) ||
   ($ability!==''&&!preg_match('/^[a-z0-9._+\/-]{3,191}$/D',$ability)) ||
   !in_array($intent,['read','write','auto'],true)||
   !in_array($phase,['plan','execute'],true)||
   !in_array($executor,['','mcp_client','vps_runner'],true) ||
   ($provider!=='' && (!preg_match('/^[a-z][a-z0-9._-]{1,79}$/D',$provider) ||
    $ability==='' || !str_starts_with($ability,$provider.'/'))))
   return self::err('invalid_scope',400);
  // Source company is intrinsic. Tenant companies require an enrolled bound site.
  if($company!=='tourism-almallahgroup') {
   $registry=get_option('mad4b_tourism_company_site_registry_v1',[]);
   $bound=is_array($registry)?($registry[$company]??null):null;
   if(!is_array($bound)||($bound['company_code']??'')!==$company ||
      empty($bound['host_binding_verified']))
    return self::err('company_scope_unverified',403);
  }
  $payload=$r['payload']??[];
  if(!is_array($payload)||strlen(wp_json_encode($payload))>12000)
   return self::err('payload_size',400);
  // No client-provided credentials or work-result claims enter a queue payload.
  foreach(['credential','password','api_key','token','lease_token','success','result'] as $field)
    if(array_key_exists($field,$payload))return self::err('sensitive_or_effect_field',400);
  return ['company_id'=>$company,'target_ref'=>$target,'operation'=>$operation,
   'stage'=>$stage,'ability_name'=>$ability,'intent'=>$intent,'phase'=>$phase,
   'executor'=>$executor,'provider_id'=>$provider,'payload'=>$payload];
 }
 private static function optin_key($r) {
  return hash('sha256',implode('|',[$r['company_id'],$r['target_ref'],$r['operation']]));
 }
 private static function optin_record($r) {
  $all=get_option(self::OPTION,[]);
  if(!is_array($all))return null;
  $item=$all[self::optin_key($r)]??null;
  return is_array($item) &&
    $item['company_id']===$r['company_id'] &&
    $item['target_ref']===$r['target_ref'] &&
    $item['operation']===$r['operation'] &&
    $item['stage']===$r['stage']?$item:null;
 }
 private static function vps_allowed($operation):bool {
  return in_array($operation,['source_sync_verify','canonical_gap_scan'],true);
 }
 private static function dynamic_adapters($r):array {
  $builtins=[
   ['id'=>'mcp_client','kind'=>'mcp_client','priority'=>100,
    'capability'=>'registered_wordpress_ability','requires_optin'=>false,
    'ready'=>true,'transport'=>'connected_mcp_or_verified_wpcli_fallback'],
   ['id'=>'vps_runner','kind'=>'vps_runner','priority'=>40,
    'capability'=>'registered_host_specialist','requires_optin'=>true,
    'ready'=>self::vps_allowed($r['operation']),'transport'=>'vps_ssh_wpcli']
  ];
  // Custom provider adapters must be registered by trusted installed PHP,
  // not by remote JSON or a client-defined endpoint.
  $extra=apply_filters('mad4b_hub_dynamic_runtime_adapters',[],$r);
  if(is_array($extra))foreach($extra as $candidate) {
   if(!is_array($candidate))continue;
   $id=(string)($candidate['id']??'');
   if(!preg_match('/^[a-z][a-z0-9._-]{2,47}$/D',$id) ||
     in_array($id,['mcp_client','vps_runner'],true))continue;
   $builtins[]=[
    'id'=>$id,'kind'=>'custom_provider','priority'=>max(0,min(90,(int)($candidate['priority']??20))),
    'capability'=>substr((string)($candidate['capability']??''),0,80),
    'requires_optin'=>true,'ready'=>($candidate['ready']??false)===true,
    'transport'=>'trusted_plugin_registration'
   ];
  }
  usort($builtins,static fn($a,$b)=>$b['priority']<=>$a['priority']);
  return array_slice($builtins,0,25);
 }
 public static function resolve($request=[]) {
  if(!self::readable())return self::err('permission',403);
  $r=self::input($request);
  if(is_wp_error($r))return $r;
  $registered=$r['ability_name']!=='' &&
   function_exists('wp_has_ability') && wp_has_ability($r['ability_name']);
  $ability=$registered?wp_get_ability($r['ability_name']):null;
  $abilityMeta=$ability?$ability->get_meta():[];
  $readOnly=isset($abilityMeta['annotations']['readonly']) &&
    $abilityMeta['annotations']['readonly']===true;
  $intentAligned=$ability && (
   ($r['intent']==='read' && $readOnly) ||
   ($r['intent']==='write' && !$readOnly) ||
   ($r['intent']==='auto' && $readOnly)
  );
  $abilityAuthorized=$ability && $ability->check_permissions([])===true;
  $route=null;$routeError=null;
  if(class_exists('MAD4B_SCP_Adaptive_Operation_Router')){
   $query=['intent'=>$r['intent'],'phase'=>'plan'];
   if($r['ability_name']!=='')$query['ability_name']=$r['ability_name'];
   elseif(preg_match('/^[a-z0-9._-]{1,64}$/D',$r['operation']))
    $query['operation']=$r['operation'];
   else $query['task']=$r['operation'];
   $plan=MAD4B_SCP_Adaptive_Operation_Router::plan($query);
   if(is_wp_error($plan))$routeError=$plan->get_error_code();
   elseif(is_array($plan)){
    // Avoid returning overly broad introspection containing provider secrets.
    $route=[
     'state'=>(string)($plan['state']??'unknown'),
     'selected'=>$plan['selected']['ability_name']??
       ($plan['selected']['name']??null),
     'candidate_count'=>is_array($plan['candidates']??null)?count($plan['candidates']):null,
     'execution_performed'=>false
    ];
   }
  }
  $optin=self::optin_record($r);
  $providerBound=$r['provider_id']!=='' && $optin && ($optin['active']??false)===true
   && ($optin['provider_id']??'')===$r['provider_id']
   && ($optin['ability_name']??'')===$r['ability_name']
   && ($optin['executor']??'')==='mcp_client';
  $selected='mcp_client';
  $explicit=$r['executor']!==''?$r['executor']:null;
  if($explicit!==null && $explicit!=='mcp_client'){
   if(!$optin || ($optin['executor']??'')!==$explicit ||
      ($optin['active']??false)!==true)
    $selected='opt_in_required';
   elseif($explicit==='vps_runner' && !self::vps_allowed($r['operation']))
    $selected='executor_not_supported';
   else $selected=$explicit;
  }elseif($optin && ($optin['active']??false)===true &&
    $r['executor']==='' && $optin['executor']==='vps_runner' &&
    self::vps_allowed($r['operation']))
    $selected='vps_runner';
  $stage=$r['stage'];
  $ready=$stage!=='' && ($selected==='vps_runner' && self::vps_allowed($r['operation'])
    || $selected==='mcp_client' && $registered && $intentAligned && $abilityAuthorized);
  $blockers=[];
  if($stage==='')$blockers[]='stage_required';
  if($r['provider_id']!=='' && !$providerBound)$blockers[]='provider_opt_in_required';
  if($selected==='mcp_client' && !$registered)$blockers[]='registered_ability_required';
  if($selected==='mcp_client' && $registered && !$intentAligned)$blockers[]='ability_intent_mismatch';
  if($selected==='mcp_client' && $registered && !$abilityAuthorized)$blockers[]='ability_permission_denied';
  if($selected==='opt_in_required')$blockers[]='executor_opt_in_required';
  if($selected==='executor_not_supported')$blockers[]='executor_unsupported_for_operation';
  if($r['provider_id']!=='' && !$providerBound)$ready=false;
  $adapters=self::dynamic_adapters($r);
  return [
   'contract'=>self::CONTRACT,'state'=>$ready?'READY_FOR_HANDOFF':'INTAKE_INCOMPLETE',
   'company_id'=>$r['company_id'],'target_ref'=>$r['target_ref'],
   'operation'=>$r['operation'],'stage'=>$stage,
   'intent'=>$r['intent'],'requested_executor'=>$explicit,
   'provider_id'=>$r['provider_id'], 'provider_opt_in_active'=>(bool)$providerBound,
   'selected_executor'=>$selected,'default_executor'=>'mcp_client',
   'registered_ability'=>$registered?$r['ability_name']:null,
   'registered_ability_readonly'=>$registered?$readOnly:null,
   'ability_permission_verified'=>$abilityAuthorized,
   'capability_discovery'=>$route,'discovery_error'=>$routeError,
   'candidate_executors'=>$adapters,'blocking_requirements'=>$blockers,
   'opt_in_active'=>!empty($optin['active']),
   'can_enqueue'=>$ready,'execution_performed'=>false,
   'client_handoff_required'=>$selected==='mcp_client',
   'provider_credentials_exposed'=>false,
  ];
 }
 public static function optin($request=[]) {
  if(!self::writable())return self::err('permission',403);
  $r=self::input($request);
  if(is_wp_error($r))return $r;
  if($r['stage']==='')return self::err('stage_required',400);
  $executor=$r['executor'];
  $isProvider=$r['provider_id']!=='';
  if($isProvider){
   if($executor!=='mcp_client' || !function_exists('wp_get_ability'))
    return self::err('provider_requires_mcp_client',403);
   // Revoke a previous binding even when its plugin/Ability has been disabled.
   // Permission and intent checks are mandatory when enabling or re-enabling.
   if(($request['opt_in']??null)!==false){
    $ability=wp_get_ability($r['ability_name']);
    if(!$ability || !method_exists($ability,'check_permissions') ||
       $ability->check_permissions([])!==true)
     return self::err('provider_ability_not_authorized',403);
    $meta=$ability->get_meta();
    $readonly=(($meta['annotations']['readonly']??false)===true);
    if((($r['intent']==='read' || $r['intent']==='auto') && !$readonly) ||
       ($r['intent']==='write' && $readonly))
     return self::err('provider_intent_mismatch',403);
   }
  }elseif($executor!=='vps_runner' || !self::vps_allowed($r['operation']))
   return self::err('only_registered_specialist_opt_in',403);
  if(!array_key_exists('opt_in',$request)||!is_bool($request['opt_in']))
   return self::err('explicit_opt_in_toggle_required',400);
  $note=(string)($request['note']??'');
  if(strlen($note)>400)return self::err('note_too_long',400);
  $key=self::optin_key($r);$all=get_option(self::OPTION,[]);
  if($isProvider && $request['opt_in']===false){
   $old=is_array($all)?($all[$key]??null):null;
   if(!is_array($old) || ($old['provider_id']??'')!==$r['provider_id'] ||
      ($old['ability_name']??'')!==$r['ability_name'])
    return self::err('provider_opt_out_scope_mismatch',409);
  }
  if(!is_array($all))$all=[];
  if(!isset($all[$key]) && count($all)>=self::MAX_OPTINS)return self::err('opt_in_registry_full');
  $entry=[
   'company_id'=>$r['company_id'],'target_ref'=>$r['target_ref'],
   'operation'=>$r['operation'],'stage'=>$r['stage'],
   'executor'=>$executor,'provider_id'=>$r['provider_id'],
   'ability_name'=>$isProvider?$r['ability_name']:'',
   'active'=>$request['opt_in'],
   'actor_id'=>get_current_user_id(),
   'note'=>sanitize_text_field($note),
   'updated_utc'=>gmdate('c')
  ];
  $all[$key]=$entry;
  if(!update_option(self::OPTION,$all,false) &&
    get_option(self::OPTION)!==$all)return self::err('opt_in_persist_failed',503);
  $readback=get_option(self::OPTION,[]);
  if(($readback[$key]['active']??null)!==$request['opt_in'])
   return self::err('opt_in_readback',503);
  return ['contract'=>self::CONTRACT,'scope_sha256'=>$key,
   'executor'=>$executor,'provider_id'=>$r['provider_id'],
   'ability_name'=>$isProvider?$r['ability_name']:null,
   'opt_in_active'=>$request['opt_in'],
   'operation'=>$r['operation'],'actor_id'=>get_current_user_id(),
   'execution_performed'=>false,'no_authorization_bypass'=>true];
 }
 public static function intake($request=[]) {
  if(!self::writable())return self::err('permission',403);
  if(!is_array($request))return self::err('input_invalid',400);
  $r=self::input($request);
  if(is_wp_error($r))return $r;
  $plan=self::resolve($request);
  if(is_wp_error($plan))return $plan;
  if(!$plan['can_enqueue'])return $plan+['intake_saved'=>false];
  $batch=(string)($request['batch_key']??'');
  $task=(string)($request['task_key']??'');
  if(!preg_match('/^[a-zA-Z0-9._:-]{8,120}$/D',$batch) ||
     !preg_match('/^[a-zA-Z0-9._:-]{1,120}$/D',$task))
   return self::err('idempotency_keys_required',400);
  $owner=$plan['selected_executor'];
  if($owner==='vps_runner'){
   if(!str_starts_with($batch,'vps.'))return self::err('vps_batch_namespace_required',400);
   // Delegate only through the existing scoped VPS integration contract.
   if(!class_exists('MAD4B_Bulk_MCP_Dispatcher_V1'))
    return self::err('vps_dispatcher_unavailable',503);
   return MAD4B_Bulk_MCP_Dispatcher_V1::plan_vps(
     ['batch_key'=>$batch,'operation'=>$r['operation']],true);
  }
  $payload=array_merge($r['payload'],[
   'assigned_executor'=>'mcp_client','ability_name'=>$r['ability_name'],
   'source_company'=>$r['company_id'],
   'runtime_contract'=>self::CONTRACT,
   'runtime_scope_sha256'=>self::optin_key($r),
   'provider_id'=>$r['provider_id'],
   'handoff_required'=>true,'requires_authenticated_ability_execution'=>true,
   'requires_post_execution_readback'=>true
  ]);
  $response=MAD4B_Hub_Bulk_Jobs_Queue::enqueue([
   'batch_key'=>$batch,'max_concurrency'=>1,'items'=>[[
    'task_key'=>$task,'stage'=>$r['stage'],
    'operation'=>$r['operation'],'target_ref'=>$r['target_ref'],
    'priority'=>7,'max_attempts'=>3,
    'depends_on'=>[],'payload'=>$payload
   ]]
  ]);
  if(is_wp_error($response))return $response;
  return ['contract'=>self::CONTRACT,'intake_saved'=>true,
   'batch_id'=>$response['batches'][0]['batch_id']??null,
   'batch_key'=>$batch,'task_key'=>$task,
   'selected_executor'=>'mcp_client','replayed'=>!empty($response['replayed']),
   'auto_execute'=>false,'ability_execution_performed'=>false];
 }
 public static function handoff($request=[]) {
  if(!self::readable())return self::err('permission',403);
  if(!is_array($request)||array_diff(array_keys($request),['batch_id','task_key']))
   return self::err('handoff_fields',400);
  $bid=(string)($request['batch_id']??'');
  $key=(string)($request['task_key']??'');
  if(!preg_match('/^[a-f0-9-]{36}$/D',$bid) ||
    !preg_match('/^[a-zA-Z0-9._:-]{1,120}$/D',$key))
   return self::err('handoff_scope',400);
  $queue=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(['batch_id'=>$bid]);
  if(is_wp_error($queue))return $queue;
  $batch=$queue['batches'][0]??null;
  if(!$batch)return self::err('batch_missing',404);
  $task=null;
  foreach($batch['tasks'] as $row)if($row['task_key']===$key)$task=$row;
  if(!$task)return self::err('task_missing',404);
  global $wpdb;
  $table=$wpdb->prefix.'mad4b_hub_bulk_tasks';
  $raw=$wpdb->get_row($wpdb->prepare(
    "SELECT payload_json FROM $table WHERE task_id=%s",$task['task_id']),ARRAY_A);
  $payload=json_decode($raw['payload_json']??'',true);
  if(!is_array($payload))return self::err('payload_corrupt',503);
  $executor=(string)($payload['assigned_executor']??'mcp_client');
  $ability=(string)($payload['ability_name']??'');
  $registered=$ability!=='' && function_exists('wp_has_ability') && wp_has_ability($ability);
  return [
   'contract'=>self::CONTRACT,'batch_id'=>$bid,'task_id'=>$task['task_id'],
   'task_key'=>$key,'stage'=>$task['stage'],'operation'=>$task['operation'],
   'target_ref'=>$task['target_ref'],'state'=>$task['state'],
   'executor'=>$executor,
   'next_action'=>$executor==='mcp_client'
     ?($registered?'claim_then_execute_exact_ability':'discover_and_bind_exact_ability')
     :'delegate_to_registered_specialist_runner',
   'ability_name'=>$registered?$ability:null,
   'claim_ability'=>'mad4b/hub-bulk-claim',
   'finish_ability'=>'mad4b/hub-bulk-finish',
   'heartbeat_ability'=>function_exists('wp_has_ability') &&
     wp_has_ability('mad4b/hub-bulk-heartbeat')?'mad4b/hub-bulk-heartbeat':null,
   'readback_required'=>true,
   'claim_performed'=>false,'execution_performed'=>false,
   'delegation_performed'=>false,'opt_in_bypassed'=>false,
   'no_fabricated_mcp_client_heartbeat'=>true
  ];
 }
 public static function status() {
  if(!self::readable())return self::err('permission',403);
  $optins=get_option(self::OPTION,[]);
  return ['contract'=>self::CONTRACT,'default_executor'=>'mcp_client',
   'registered_runtime_source'=>'mad4b/operation-route-plan',
   'intake_enabled'=>true,'runtime_resolution_enabled'=>true,
   'opt_in_supported'=>true,'handoff_supported'=>true,
   'opt_in_records'=>is_array($optins)?count($optins):0,
   'registered_adapters_filter'=>'mad4b_hub_dynamic_runtime_adapters',
   'vps_specialist_allowlist'=>['source_sync_verify','canonical_gap_scan'],
   'server_background_mcp_llm_client_registered'=>false,
   'unattended_mcp_execution_certified'=>false,
   'publication_performed'=>false];
 }
 public static function rest(){
  if(!self::site())return;
  foreach([
   '/bulk-runtime/status'=>['GET','status',false],
   '/bulk-runtime/resolve'=>['POST','resolve',false],
   '/bulk-runtime/intake'=>['POST','intake',true],
   '/bulk-runtime/opt-in'=>['POST','optin',true],
   '/bulk-runtime/handoff'=>['GET','handoff',false],
  ] as $path=>$v){
   register_rest_route('mad4b-central/v1',$path,[
    'methods'=>$v[0],
    'permission_callback'=>[__CLASS__,$v[2]?'writable':'readable'],
    'callback'=>static function($req)use($v){
      $args=$req->get_params();
      $result=call_user_func([__CLASS__,$v[1]],$args);
      return is_wp_error($result)?$result:rest_ensure_response($result);
    }
   ]);
  }
 }
 public static function abilities(){
  if(!self::site()||!function_exists('wp_register_ability'))return;
  foreach([
   'mad4b/bulk-runtime-status'=>['status',false],
   'mad4b/bulk-runtime-resolve'=>['resolve',false],
   'mad4b/bulk-runtime-intake'=>['intake',true],
   'mad4b/bulk-runtime-opt-in'=>['optin',true],
   'mad4b/bulk-runtime-handoff'=>['handoff',false],
  ] as $name=>$item){
   if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
   wp_register_ability($name,[
    'label'=>str_replace('mad4b/','MAD4B ',$name),
    'description'=>'Dynamically discover and hand off scoped runtime operations. No automatic execution.',
    'category'=>$item[1]?'mad4b-write':'mad4b-read',
    'execute_callback'=>[__CLASS__,$item[0]],
    'permission_callback'=>[__CLASS__,$item[1]?'writable':'readable'],
    'input_schema'=>['type'=>'object','properties'=>[
      'company_id'=>['type'=>'string'],'target_ref'=>['type'=>'string'],
      'operation'=>['type'=>'string'],'stage'=>['type'=>'string'],
      'provider_id'=>['type'=>'string'],
      'ability_name'=>['type'=>'string'],'intent'=>['type'=>'string'],
      'phase'=>['type'=>'string'],'executor'=>['type'=>'string'],
      'batch_key'=>['type'=>'string'],'task_key'=>['type'=>'string'],
      'opt_in'=>['type'=>'boolean'],'note'=>['type'=>'string'],
      'batch_id'=>['type'=>'string'],'payload'=>['type'=>'object'],
    ],'additionalProperties'=>false],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'meta'=>['public'=>false,'show_in_rest'=>false,
      'mcp'=>['public'=>false,'type'=>'tool','surface'=>$item[1]?'write':'read'],
      'annotations'=>['readonly'=>!$item[1],'destructive'=>false,'idempotent'=>true]]
   ]);
  }
 }
 public static function menu(){
  if(!self::site())return;
  add_submenu_page('mad4b-shared-content-hub','Dynamic Runtime Resolver',
   'Dynamic Runtime Resolver','manage_options','mad4b-dynamic-bulk-runtime',[__CLASS__,'page']);
 }
 public static function page(){
  if(!self::readable())wp_die('Forbidden');
  echo '<div class="wrap"><h1>MAD4B Dynamic Bulk Runtime Resolver</h1>';
  echo '<p>Intake → Live Discovery → Opt-in (optional specialist) → Handoff → Authenticated Execution → Readback.</p>';
  echo '<pre>'.esc_html(wp_json_encode(self::status(),JSON_PRETTY_PRINT)).'</pre></div>';
 }
 public static function boot(){
  add_action('rest_api_init',[__CLASS__,'rest'],50);
  add_action('wp_abilities_api_init',[__CLASS__,'abilities'],50);
  add_action('admin_menu',[__CLASS__,'menu'],35);
 }
}
MAD4B_Dynamic_Bulk_Runtime_V1::boot();

