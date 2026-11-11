<?php
defined('ABSPATH') || exit;
/**
 * MAD4B universal provider onboarding, v1.
 * Registration -> capability inspection -> exact executable-contract check
 * -> readiness receipt. Discovery never grants execution authority.
 */
final class MAD4B_Provider_Onboarding_V1 {
 const CONTRACT='mad4b.provider-onboarding.v1';
 const OPTION='mad4b_provider_onboarding_registry_v1';
 public static function boot(){
  add_action('wp_abilities_api_init',[__CLASS__,'register_ability'],90);
  add_action('wp_abilities_api_init',[__CLASS__,'refresh_if_authorized'],999);
  add_action('admin_init',[__CLASS__,'refresh_if_authorized'],95);
  add_action('mad4b_provider_registration_changed',[__CLASS__,'refresh_if_authorized'],20);
  add_action('admin_notices',[__CLASS__,'admin_notice']);
 }
 public static function register_ability(){
  if(!function_exists('wp_register_ability')||wp_has_ability('mad4b/provider-onboarding-status'))return;
  wp_register_ability('mad4b/provider-onboarding-status',[
   'label'=>'MAD4B Provider Onboarding and Stage Capability Status',
   'description'=>'Inspect provider readiness, exact Stage Adapters, contracts and missing registration requirements. No provider call or capability grant.',
   'category'=>'mad4b-read',
   'execute_callback'=>[__CLASS__,'status'],
   'permission_callback'=>static fn()=>current_user_can('manage_options'),
   'input_schema'=>['type'=>'object','additionalProperties'=>false],
   'output_schema'=>['type'=>'object','additionalProperties'=>true],
   'meta'=>['public'=>false,'show_in_rest'=>false,
     'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
     'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
  ]);
 }
 private static function safe_id($id){
  return is_string($id)&&preg_match('/^[a-z][a-z0-9._-]{1,79}$/D',$id);
 }
 private static function declarations(){
  $out=[];
  // Providers expose descriptive manifests, not executable privileges.
  $declared=apply_filters('mad4b_provider_capability_manifests',[]);
  if(is_array($declared))foreach($declared as $d){
   if(!is_array($d)||!self::safe_id($d['provider_id']??null))continue;
   $id=$d['provider_id'];
   $out[$id]=['provider_id'=>$id,'source'=>'provider_manifest',
     'stages'=>array_values(array_filter((array)($d['stages']??[]),'is_string')),
     'capabilities'=>array_values(array_filter((array)($d['capabilities']??[]),'is_string')),
     'configured'=>(bool)($d['configured']??false),
     'connection_verified'=>(bool)($d['connection_verified']??false)];
  }
  // An installed Ability is a discovery signal, not execution authority.
  foreach((array)(function_exists('wp_get_abilities')?wp_get_abilities():[]) as $name=>$ability){
   if(!is_string($name)||!str_contains($name,'/')||!is_object($ability)||!method_exists($ability,'get_meta'))continue;
   $meta=(array)$ability->get_meta();
   $vps=(array)($meta['mad4b_vps']??($meta['mcp']['mad4b_vps']??[]));
   $stages=(array)($vps['stages']??($meta['mad4b_hub_stages']??[]));
   if(!$stages)continue;
   $provider=explode('/',$name,2)[0];
   if(!self::safe_id($provider))continue;
   if(!isset($out[$provider]))$out[$provider]=[
     'provider_id'=>$provider,'source'=>'ability_metadata','stages'=>[],
     'capabilities'=>[],'configured'=>true,'connection_verified'=>false];
   $out[$provider]['stages']=array_values(array_unique(array_merge($out[$provider]['stages'],$stages)));
   $out[$provider]['capabilities'][]=$name;
  }
  // Existing connection becomes eligible for inspection from registration.
  if(class_exists('MAD4B_SCP_Firecrawl_Provider',false)){
   $probe=MAD4B_SCP_Firecrawl_Provider::probe();
   $test=MAD4B_SCP_Firecrawl_Provider::test_status();
   $id='firecrawl';
   if(!isset($out[$id]))$out[$id]=['provider_id'=>$id,'source'=>'native_connection',
     'stages'=>['SITE_DISCOVERY','SOURCE_DISCOVERY','SCRAPING'],
     'capabilities'=>['scrape_url','extract_markdown'],
     'configured'=>false,'connection_verified'=>false];
   $out[$id]['configured']=($probe['ready']??false)===true;
   $out[$id]['connection_verified']=($test['state']??'')==='reachable';
   $out[$id]['connection_state']=(string)($test['state']??'untested');
   $out[$id]['last_connection_test_at']=(string)($test['at']??'');
  }
  ksort($out);
  return $out;
 }
 private static function gate($ability){
  if(!is_string($ability)||$ability===''||
    !class_exists('MAD4B_SCP_Ability_Contract_Inspector',false))
    return ['execution_lane'=>'none','execution_blocker'=>'descriptor_unavailable'];
  $row=MAD4B_SCP_Ability_Contract_Inspector::inspect($ability);
  if(is_wp_error($row))
    return ['execution_lane'=>'none','execution_blocker'=>$row->get_error_code()];
  return ['execution_lane'=>(string)($row['execution_lane']??'none'),
    'execution_blocker'=>(string)($row['execution_blocker']??'unknown'),
    'classification'=>(string)($row['classification']??'unknown')];
 }
 public static function inspect(){
  $providers=self::declarations();
  $contracts=class_exists('MAD4B_Hub_Universal_VPS_V1',false)
    ?MAD4B_Hub_Universal_VPS_V1::contracts():[];
  $manifests=apply_filters('mad4b_hub_dynamic_executor_manifests',[]);
  $rows=[];
  foreach($providers as $id=>$d){
   $routes=[];
   foreach(array_values(array_unique($d['stages'])) as $stage){
    if(!is_string($stage)||strlen($stage)>70)continue;
    $matched=[];
    foreach((array)$contracts as $contract){
     if(!in_array($stage,$contract['stages']??[],true))continue;
     if(($contract['owner']??'')===$id || str_starts_with((string)($contract['ability']??''),$id.'/'))
      $matched[]=['adapter_id'=>$contract['id'],'ability'=>$contract['ability'],'source'=>$contract['registration_source']];
    }
    foreach((array)$manifests as $m){
     if(!is_array($m)||($m['ready']??false)!==true||
       !in_array($stage,$m['stages']??[],true))continue;
     if(str_starts_with((string)($m['id']??''),'provider.'.$id.'.'))
      $matched[]=['adapter_id'=>$m['id'],'ability'=>$m['ability']??'','source'=>'provider_stage_driver'];
    }
    $admitted=[];$blocked=[];
    foreach($matched as $candidate){
      $gate=self::gate((string)($candidate['ability']??''));
      $candidate['capability_descriptor']=$gate;
      if(($gate['execution_lane']??'none')==='none')$blocked[]=$candidate;
      else $admitted[]=$candidate;
    }
    $requiresActor=false;
    foreach($admitted as $candidate){
      if(($candidate['capability_descriptor']['execution_lane']??'read')!=='read')
        $requiresActor=true;
    }
    $routes[$stage]=[
      'registered_executable_contracts'=>$matched,
      'admitted_capability_contracts'=>$admitted,
      'blocked_capability_contracts'=>$blocked,
      'original_actor_and_grant_required'=>$requiresActor,
      'original_authorization_verified'=>false,
      'state'=>!$matched?'executor_contract_missing':
        (!$admitted?'canonical_ability_admission_pending':
        ($requiresActor?'original_actor_authorization_required':'registered_read_contract'))];
   }
   $missing=[];
   if(!$d['configured'])$missing[]='provider_configuration';
   if(!$d['connection_verified'])$missing[]='connection_probe_or_test';
   if(!$routes)$missing[]='stage_capability_declaration';
   foreach($routes as $stage=>$route){
    if(!$route['registered_executable_contracts'])$missing[]='stage_adapter:'.$stage;
    elseif(!$route['admitted_capability_contracts'])
      $missing[]='canonical_ability_admission:'.$stage;
   }
   $rows[$id]=[
     'provider_id'=>$id,'registration_source'=>$d['source'],
     'configured'=>$d['configured'],'connection_verified'=>$d['connection_verified'],
     'stages'=>$routes,'capabilities'=>$d['capabilities'],
     'missing_requirements'=>array_values(array_unique($missing)),
     'readiness'=>$missing?'SETUP_REQUIRED':
       (count(array_filter($routes,static fn($route)=>!empty($route['original_actor_and_grant_required'])))?
        'CONTRACT_READY_ORIGINAL_AUTHORIZATION_REQUIRED':'REGISTERED_READ_CONTRACTS_PRESENT')];
  }
  return ['contract'=>self::CONTRACT,'providers'=>$rows,
   'provider_count'=>count($rows),'mutation_performed'=>false,
   'discovery_never_grants_execution'=>true,
   'contracts_do_not_authorize_provider_calls'=>true];
 }
 public static function status($input=[]){
  if(!current_user_can('manage_options'))return new WP_Error('mad4b_provider_onboarding_permission');
  return self::inspect();
 }
 public static function refresh_if_authorized(){
  if(!current_user_can('manage_options'))return;
  $view=self::inspect();
  $digest=hash('sha256',wp_json_encode($view['providers']));
  $prior=get_option(self::OPTION,[]);
  if(is_array($prior)&&($prior['digest']??'')===$digest)return;
  $snapshot=['contract'=>self::CONTRACT,'digest'=>$digest,
    'provider_count'=>$view['provider_count'],
    'updated_at'=>gmdate('c'),'providers'=>$view['providers']];
  update_option(self::OPTION,$snapshot,false);
  do_action('mad4b_provider_onboarding_changed',$snapshot,$prior);
 }
 public static function admin_notice(){
  if(!current_user_can('manage_options')||!isset($_GET['page'])||
    !str_contains(sanitize_key((string)$_GET['page']),'provider'))return;
  $view=self::inspect();$missing=[];
  foreach($view['providers'] as $id=>$p)
   if($p['missing_requirements'])$missing[]=$id.': '.implode(', ',$p['missing_requirements']);
  if(!$missing)return;
  echo '<div class="notice notice-warning"><p><strong>MAD4B Provider setup:</strong> '.
    esc_html(implode(' | ',array_slice($missing,0,5))).'</p></div>';
 }
}
MAD4B_Provider_Onboarding_V1::boot();
