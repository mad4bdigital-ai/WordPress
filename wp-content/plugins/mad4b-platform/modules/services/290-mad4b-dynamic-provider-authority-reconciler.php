<?php
defined('ABSPATH')||exit;
/**
 * Dynamic original-authority reconciliation for provider stage abilities.
 * Discovery and planning may be automatic, but no principal is impersonated,
 * no grants/subjects/agents are minted and no approval is silently issued.
 */
final class MAD4B_Dynamic_Provider_Authority_Reconciler {
 const CONTRACT='mad4b.dynamic-provider-authority-reconciler.v1';
 const OPTION='mad4b_dynamic_provider_authority_readiness_v1';
 private static $busy=false;
 public static function boot(){
  add_action('wp_abilities_api_init',[__CLASS__,'register_ability'],94);
  add_action('wp_abilities_api_init',[__CLASS__,'refresh'],1001);
  add_action('admin_init',[__CLASS__,'refresh'],99);
  add_action('mad4b_provider_onboarding_changed',[__CLASS__,'refresh'],30);
  add_action('mad4b_provider_registration_changed',[__CLASS__,'refresh'],35);
  add_action('mad4b_provider_authority_changed',[__CLASS__,'refresh'],40);
  add_action('mad4b_provider_executor_changed',[__CLASS__,'refresh'],40);
 }
 public static function register_ability(){
  if(!function_exists('wp_register_ability')||wp_has_ability('mad4b/provider-authority-reconcile-status'))return;
  wp_register_ability('mad4b/provider-authority-reconcile-status',[
   'label'=>'MAD4B Dynamic Provider Authority Readiness',
   'description'=>'Read original authenticated actor, exact provider stage grants and canonical descriptors. Does not create grants or execute providers.',
   'category'=>'mad4b-read',
   'execute_callback'=>[__CLASS__,'status'],
   'permission_callback'=>static fn()=>current_user_can('manage_options'),
   'input_schema'=>['type'=>'object','additionalProperties'=>false],
   'output_schema'=>['type'=>'object','additionalProperties'=>true],
   'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
     'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
  ]);
 }
 private static function original_actor(){
  if(!class_exists('MAD4B_SCP_Identity_Context',false)
   ||!class_exists('MAD4B_SCP_Agent_Registry',false))
    return ['state'=>'AUTHORITY_REGISTRY_UNAVAILABLE'];
  $identity=MAD4B_SCP_Identity_Context::current();
  if(is_wp_error($identity)||!is_array($identity)||
    empty($identity['authenticated'])||empty($identity['subject_type'])||
    empty($identity['subject_fingerprint'])){
   // Read-only original host identity proof; only succeeds within the exact
   // root-owned CLI execution context and does not attach a signed request.
   if(!class_exists('MAD4B_SCP_Execution_Authority',false))
    return ['state'=>'WAITING_AUTHENTICATED_EXECUTOR'];
   $host=MAD4B_SCP_Execution_Authority::resolve('read');
   if(is_wp_error($host)||!is_array($host)||
    ($host['transport']??'')!=='host_cli'||
    empty($host['registry_bound'])||empty($host['authenticated']))
    return ['state'=>'WAITING_AUTHENTICATED_EXECUTOR'];
   $candidate=['authenticated'=>true,
     'subject_type'=>(string)($host['subject_type']??''),
     'subject_fingerprint'=>(string)($host['subject_fingerprint']??'')];
   $bound=MAD4B_SCP_Agent_Registry::resolve_agent($candidate);
   if(is_wp_error($bound)||!is_array($bound)||
     (int)($bound['wp_user_id']??0)!==(int)($host['wp_user_id']??-1))
    return ['state'=>'HOST_BINDING_CONFLICT'];
   return ['state'=>'VERIFIED_ORIGINAL_HOST_READ_CONTEXT',
     'agent_id'=>(int)$bound['id'],
     'signed_mutation_request'=>false];
  }
  $resolved=MAD4B_SCP_Agent_Registry::resolve_agent($identity);
  if(is_wp_error($resolved))
   return ['state'=>'IDENTITY_NOT_ENROLLED','reason'=>$resolved->get_error_code()];
  return ['state'=>'AUTHENTICATED_AND_ENROLLED',
    'agent_id'=>(int)$resolved['id']];
 }
 private static function enrolled_host_operators(){
  global $wpdb;
  if(!class_exists('MAD4B_SCP_Schema',false)||!MAD4B_SCP_Schema::critical_ready())
   return [];
  $tables=MAD4B_SCP_Schema::tables();
  $sql="SELECT DISTINCT a.id,a.slug FROM {$tables['agents']} a INNER JOIN {$tables['subjects']} s ON s.agent_id=a.id WHERE a.status='enabled' AND s.status='enabled' AND s.subject_type='host_operator' ORDER BY a.id ASC LIMIT 16";
  $rows=$wpdb->get_results($sql,ARRAY_A);
  return is_array($rows)?array_map(static fn($a)=>['agent_id'=>(int)$a['id'],'slug'=>(string)$a['slug']],$rows):[];
 }
 private static function ability_grant($ability,$lane,$actor){
  $server='mad4b-'.$lane;
  $provider=class_exists('MAD4B_SCP_Servers',false)
   ?MAD4B_SCP_Servers::provider_for_capability_descriptor($server,$ability):null;
  if(!is_string($provider)||$provider==='')return [
    'state'=>'ORIGINAL_PROVIDER_MOUNT_MISSING','server_id'=>$server];
  if(($actor['state']??'')==='VERIFIED_ORIGINAL_HOST_READ_CONTEXT'){
   $grant=MAD4B_SCP_Agent_Registry::exact_grant(
      $actor['agent_id'],$server,$ability,$provider);
   if(is_wp_error($grant))
    return ['state'=>'EXACT_GRANT_REQUIRED','server_id'=>$server,
     'provider'=>$provider,'reason'=>$grant->get_error_code()];
   return ['state'=>'HOST_READ_PROOF_AND_EXACT_GRANT_PRESENT_SIGNED_APPLY_REQUIRED',
     'server_id'=>$server,'provider'=>$provider,
     'environment'=>(string)($grant['environment']??''),
     'current_request_authenticated'=>false,
     'execution_authorized'=>false];
  }
  if(($actor['state']??'')!=='AUTHENTICATED_AND_ENROLLED'){
   $operators=self::enrolled_host_operators();
   $eligible=[];
   foreach($operators as $operator){
    $grant=MAD4B_SCP_Agent_Registry::exact_grant($operator['agent_id'],$server,$ability,$provider);
    if(!is_wp_error($grant)&&($grant['effect']??'')==='allow'){
     $eligible[]=['executor_agent_slug'=>$operator['slug'],
       'environment'=>(string)($grant['environment']??'')];
    }
   }
   return ['state'=>$eligible?
      'EXISTING_HOST_OPERATOR_GRANT_PRESENT_AWAITING_SIGNED_REQUEST':
      'WAITING_EXECUTOR_IDENTITY_AND_EXACT_GRANT',
    'server_id'=>$server,'provider'=>$provider,
    'registered_host_operator_candidates'=>count($operators),
    'eligible_existing_executor_routes'=>$eligible,
    'current_request_authenticated'=>false,
    'execution_authorized'=>false];
  }
  $grant=MAD4B_SCP_Agent_Registry::exact_grant($actor['agent_id'],$server,$ability,$provider);
  if(is_wp_error($grant))return ['state'=>'EXACT_GRANT_REQUIRED','server_id'=>$server,
   'provider'=>$provider,'reason'=>$grant->get_error_code()];
  $effects=(string)($grant['effect']??'');
  if($effects!=='allow')return ['state'=>'EXACT_GRANT_REQUIRED','server_id'=>$server,
    'provider'=>$provider,'reason'=>'not_allow'];
  // Exact grant presence is NOT full approval, resource authorization or execution.
  return ['state'=>'EXACT_GRANT_PRESENT_LIVE_AUTH_REQUIRED','server_id'=>$server,
    'provider'=>$provider,'environment'=>(string)($grant['environment']??'')];
 }
 public static function inspect(){
  $base=class_exists('MAD4B_Provider_Onboarding_V1',false)
   ?MAD4B_Provider_Onboarding_V1::inspect():null;
  if(!is_array($base))return new WP_Error('mad4b_provider_onboarding_unavailable');
  $actor=self::original_actor();
  $providers=[];$count=0;
  foreach(($base['providers']??[]) as $providerId=>$provider){
   $stages=[];
   foreach(($provider['stages']??[]) as $stage=>$route){
    $rows=[];
    foreach(($route['admitted_capability_contracts']??[]) as $c){
     $lane=(string)($c['capability_descriptor']['execution_lane']??'none');
     $ability=(string)($c['ability']??'');
     $row=['adapter_id'=>(string)($c['adapter_id']??''),'ability'=>$ability,
       'execution_lane'=>$lane];
     if($lane==='read')$row['authority']=['state'=>'READ_ONLY_STILL_CHECK_LIVE_PERMISSIONS'];
     elseif(in_array($lane,['write','content','admin','developer'],true)){
      $row['authority']=self::ability_grant($ability,$lane,$actor);
     }else $row['authority']=['state'=>'CAPABILITY_DESCRIPTOR_INELIGIBLE'];
     $rows[]=$row;$count++;
    }
    $stages[$stage]=['readiness'=>(string)($route['state']??'unknown'),'abilities'=>$rows];
   }
   $providers[$providerId]=['provider_id'=>$providerId,
    'discovery_readiness'=>(string)($provider['readiness']??'unknown'),
    'stages'=>$stages];
  }
  return ['contract'=>self::CONTRACT,'executor_actor_state'=>$actor['state'],
   'executor_actor_reason'=>$actor['reason']??null,
   'provider_count'=>count($providers),'ability_count'=>$count,'providers'=>$providers,
   'automatic_existing_grant_reuse'=>true,'grants_created'=>0,
   'mutation_performed'=>false,'execution_authorized'=>false,
   'approval_ticket_issued'=>false,'request_live_authorization_required'=>true];
 }
 public static function status($input=[]){
  if(!current_user_can('manage_options'))return new WP_Error('mad4b_authority_read_permission_required');
  return self::inspect();
 }
 public static function refresh(){
  if(self::$busy||!current_user_can('manage_options'))return;
  self::$busy=true;
  try {
   $view=self::inspect();
   if(is_wp_error($view))return;
   // Strip actor-specific outcomes: a snapshot is a diagnostic, never a reusable grant.
   $safe=['contract'=>self::CONTRACT,'provider_count'=>$view['provider_count'],
     'ability_count'=>$view['ability_count'],'actor_binding_revalidated_each_request'=>true,
     'authorizing'=>false,'stage_abilities'=>[]];
   foreach($view['providers'] as $pid=>$p){
    foreach($p['stages'] as $stage=>$data){
     foreach($data['abilities'] as $a){
      $state=(string)($a['authority']['state']??'UNKNOWN');
      $safe['stage_abilities'][]=['provider_id'=>$pid,'stage'=>$stage,
       'ability'=>$a['ability'],'execution_lane'=>$a['execution_lane'],
       'eligible_existing_operator_grant_present'=>
         $state==='EXISTING_HOST_OPERATOR_GRANT_PRESENT_AWAITING_SIGNED_REQUEST',
       'signed_execution_still_required'=>true];
     }
    }
   }
   $checksum=hash('sha256',wp_json_encode($safe));
   $prior=get_option(self::OPTION,[]);
   if(!is_array($prior)||($prior['sha256']??'')!==$checksum){
    update_option(self::OPTION,['sha256'=>$checksum,'updated_at'=>gmdate('c'),
      'diagnostic'=>$safe],false);
   }
  }finally{self::$busy=false;}
 }
}
MAD4B_Dynamic_Provider_Authority_Reconciler::boot();
