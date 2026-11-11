<?php
/**
 * On-demand exact-provider readiness for Company Reconcile and Start Company.
 * Does not issue a job, grant an executor, or change target WordPress state.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Company_Reconcile_Operation_Readiness_V1',false)){
final class MAD4B_Company_Reconcile_Operation_Readiness_V1 {
 const CONTRACT='mad4b.company-reconcile.operation-readiness.v1';
 const OPERATIONS=['content.write','operations.write','strategy.write','commerce.write','plugin.install'];
 public static function inspect(string $company,string $environment,string $expectedSite='') {
  if(!class_exists('MAD4B_Company_Portfolio_Manager_V1',false)||
   !MAD4B_Company_Portfolio_Manager_V1::staging())
   return new WP_Error('central_source_not_verified','Central source identity is not verified',['status'=>403]);
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)||
   !in_array($environment,['production','staging'],true)||
   ($expectedSite!==''&&!wp_is_uuid($expectedSite)))
   return new WP_Error('invalid_company_scope','Invalid company scope',['status'=>422]);
  if(!class_exists('MAD4B_Company_Environment_Registry_V2',false)||
   !class_exists('MAD4B_Company_Native_Write_Executor_V1',false))
   return new WP_Error('original_provider_unavailable','Original registry or provider is unavailable',['status'=>503]);
  $site=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
  if(!is_array($site)||($site['company_id']??'')!==$company||
   ($site['environment']??'')!==$environment||
   !wp_is_uuid((string)($site['site_uuid']??'')))
   return new WP_Error('target_binding_unverified','Target registry binding not verified',['status'=>409]);
  $uuid=(string)$site['site_uuid'];
  if($expectedSite!==''&&!hash_equals($uuid,$expectedSite))
   return new WP_Error('target_uuid_mismatch','Exact Site UUID does not match',['status'=>409]);
  $result=[];$ready=0;
  foreach(self::OPERATIONS as $op){
   $status=MAD4B_Company_Native_Write_Executor_V1::preflight_status($company,$environment,$op);
   if(is_wp_error($status))$status=['state'=>'PROVIDER_PREFLIGHT_ERROR',
     'reason'=>$status->get_error_code(),'next_stage'=>'RECHECK_PROVIDER_REGISTRATION'];
   elseif(!is_array($status))$status=['state'=>'PROVIDER_PREFLIGHT_UNAVAILABLE',
     'reason'=>'non_structured_preflight','next_stage'=>'RECHECK_PROVIDER_REGISTRATION'];
   $matched=($status['site_uuid']??null)===$uuid&&
    ($status['canonical_origin']??null)===($site['canonical_origin']??null);
   $provider=$matched&&($status['operation_provider_ready']??false)===true;
   $transport=$matched&&($status['transport_provider_ready']??false)===true;
   $actor=$matched&&($status['target_wordpress_actor_capability_verified']??false)===true;
   $admitted=$provider&&$transport&&$actor&&
    ($status['scope_preflight_passed']??false)===true&&
    ($status['state']??'')==='AUTHORITY_AND_EXECUTOR_READY_FOR_EXACT_JOB';
   $ready+=(int)$admitted;
   $providerDiagnostics=null;
   if($op==='plugin.install'&&
      class_exists('MAD4B_Company_Native_Plugin_Install_Handler_V1',false)&&
      class_exists('MAD4B_Company_Native_Plugin_Install_Provider_V1',false)){
    // Distinguish historic installation from a usable, fresh 312 capability.
    $health=MAD4B_Company_Native_Plugin_Install_Handler_V1::capability_status();
    $resource=MAD4B_Company_Native_Plugin_Install_Provider_V1::readback();
    if(is_wp_error($health))$health=['handler_ready'=>false,'reason'=>$health->get_error_code()];
    elseif(!is_array($health))$health=['handler_ready'=>false,'reason'=>'handler_status_unavailable'];
    if(is_wp_error($resource))$resource=['state'=>'UNVERIFIED','reason'=>$resource->get_error_code()];
    elseif(!is_array($resource))$resource=['state'=>'UNVERIFIED'];
    $providerDiagnostics=[
     'exact_handler_registered'=>($status['operation_provider_registered']??false)===true,
     'capability_heartbeat_accepted'=>($health['handler_ready']??false)===true,
     'capability_health_reason'=>$health['reason']??null,
     'historic_resource_state'=>$resource['state']??'UNKNOWN',
     'historic_resource_does_not_authorize_new_job'=>true,
     'signed_312_heartbeat_must_be_fresh_and_bound'=>true,
    ];
   }
   $result[]=[
    'operation'=>$op,'state'=>$admitted?'READY_FOR_EXACT_JOB':'REPAIR_PROVIDER_OR_CAPABILITY',
    'original_preflight_state'=>$matched?($status['state']??'UNKNOWN'):'TARGET_IDENTITY_MISMATCH',
    'transport_ready'=>$transport,'operation_provider_ready'=>$provider,
    'target_actor_verified'=>$actor,
    'provider_reason'=>$matched?($status['reason']??null):'TARGET_IDENTITY_MISMATCH',
    'provider_diagnostics'=>$providerDiagnostics,
    'next_step'=>!$admitted&&$op==='plugin.install'&&
      !empty($providerDiagnostics['exact_handler_registered'])
       ?'VERIFY_SIGNED_312_HEARTBEAT_FRESHNESS_AND_EXACT_SITE_SCOPE'
       :($matched?($status['next_stage']??'COLLECT_NATIVE_EVIDENCE'):'REVALIDATE_TARGET_REGISTRY'),
    'can_submit_governed_exact_job'=>$admitted,
    'prior_exact_313_observed'=>($status['last_original_313_job_verified']??false)===true,
    'new_job_submitted'=>false,
    'new_job_313_verified'=>false,
   ];
  }
  return [
   'contract'=>self::CONTRACT,'company_code'=>$company,
   'site_uuid'=>$uuid,'environment'=>$environment,
   'canonical_origin'=>$site['canonical_origin']??null,
   'collected_at_utc'=>gmdate('c'),
   'operations'=>$result,'ready_operation_count'=>$ready,
   'operation_count'=>count(self::OPERATIONS),
   'state'=>$ready===count(self::OPERATIONS)?'ALL_OPERATIONS_PREFLIGHT_READY':'PARTIAL_OPERATION_READINESS',
   'one_blocked_provider_does_not_block_other_ready_operations'=>true,
   'execution_authorized_by_this_report'=>false,
   'target_provider_receipts_verified'=>false,
   'company_publication_authorized'=>false,
   'mutation_performed'=>false,
  ];
 }
 public static function routes():void {
  if(!class_exists('MAD4B_Company_Portfolio_Manager_V1',false)||
   !MAD4B_Company_Portfolio_Manager_V1::staging())return;
  register_rest_route('mad4b/v1',
   '/company-brand-readiness/companies/(?P<company>[a-z][a-z0-9-]{2,47})/reconcile-operations',[
    'methods'=>'GET',
    'permission_callback'=>['MAD4B_Company_Readiness_Governance','guard'],
    'callback'=>static function(WP_REST_Request $request){
     $result=self::inspect((string)$request->get_param('company'),
      (string)$request->get_param('environment'),(string)$request->get_param('expected_site_uuid'));
     if(is_wp_error($result))return $result;
     $response=rest_ensure_response($result);
     $response->header('Cache-Control','private, no-store');
     return $response;
    }
   ]);
 }
 public static function boot():void {
  add_action('rest_api_init',[__CLASS__,'routes'],95);
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b company-reconcile-operations',static function($args){
    $r=self::inspect((string)($args[0]??''),(string)($args[1]??''),(string)($args[2]??''));
    if(is_wp_error($r))WP_CLI::error($r->get_error_code());
    WP_CLI::line(wp_json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
   });
 }
}
}
MAD4B_Company_Reconcile_Operation_Readiness_V1::boot();
