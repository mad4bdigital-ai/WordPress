<?php
/**
 * Plugin Name: MAD4B Company Three-Tier Recovery Governance (Central)
 * Description: Native WordPress -> container -> VPS/SSH governed routing, including one-time SQL/shell maintenance planning; no implicit escalation.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Recovery_Ladder_V1 {
 const CONTRACT='mad4b.company.three-tier-recovery.v1';
 const ROUTE='mad4b/v1';
 const STAGES=['wordpress','container','vps_ssh'];
 const TRANSPORT_FAILURES=[
  'transport_timeout','connection_refused','endpoint_unreachable',
  'runtime_unavailable','provider_not_installed','container_runtime_unavailable',
  'executor_not_registered','unsupported_technical_method','mcp_internal_failure'];
 const DENIALS=['security_denied','safety_denied','permission_denied','authorization_denied',
  'authentication_denied','policy_denied','tenant_mismatch','company_identity_mismatch',
  'invalid_signature','invalid_scope','invalid_input','unknown_failure'];
 const OPS=[
  'identity.inspect'=>['read','identity.inspect'],
  'environment.inspect'=>['read','environment.inspect'],
  'capability.inspect'=>['read','capability.inspect'],
  'content.read'=>['read','content.read'],
  'content.write'=>['write','content.write'],
  'strategy.write'=>['write','strategy.write'],
  'operations.write'=>['write','operations.write'],
  'commerce.write'=>['write','commerce.write'],
  'maintenance.one_time_apply'=>['write','operations.write'],
  'maintenance.raw_sql'=>['write','operations.write'],
  'maintenance.shell'=>['write','operations.write'],
 ];
 static function authorized():bool {
  return class_exists('MAD4B_Company_Operation_Admission_V1') &&
   class_exists('MAD4B_Manager_Auto_Write_V1') &&
   MAD4B_Company_Operation_Admission_V1::actor();
 }
 private static function error($reason,$http=403){
  return new WP_Error('mad4b_ladder_'.$reason,'Company recovery routing rejected: '.$reason,
   ['status'=>$http,'contract'=>self::CONTRACT]);
 }
 static function classify($failure):string {
  if($failure===null||$failure==='')return 'initial';
  if(in_array($failure,self::TRANSPORT_FAILURES,true))return 'technical_failure';
  if(in_array($failure,self::DENIALS,true))return 'hard_denial';
  // Unknown failures must not be treated as transport errors.
  return 'hard_denial';
 }
 static function policy():array {
  return ['contract'=>self::CONTRACT,
   'default_order'=>self::STAGES,
   'wordpress_methods'=>['native_rest','governed_mcp','authorized_wp_cli'],
   'container_methods'=>['enrolled_coolify_service','enrolled_docker_container'],
   'vps_methods'=>['enrolled_ssh_host_operator'],
   'maintenance_modes'=>['one_time_apply','raw_sql','shell'],
   'automatic_failover_on'=>self::TRANSPORT_FAILURES,
   'never_auto_failover_on'=>self::DENIALS,
   'one_time_apply'=>'operation_scoped_and_idempotent',
   'raw_sql'=>'parameterized_or_reviewed_exact_statements_only',
   'shell'=>'authenticated_host_operator_exact_command_digest',
   'no_company_staging_required'=>true,
   'production_allowed_when_exact_company_is_production'=>true,
   'host_safety_enforcement_untouched'=>true,
   'no_implicit_privilege_elevation'=>true,
   'execution_capable'=>false];
 }
 static function plan(string $company,string $operation,
    string $failed_stage='',?string $failure=null,array $evidence=[]) {
  if(!self::authorized())return self::error('central_administrator_required');
  if(!isset(self::OPS[$operation]))return self::error('operation_unsupported',422);
  if($failed_stage!==''&&!in_array($failed_stage,self::STAGES,true))
   return self::error('stage_unknown',422);
  $kind=self::OPS[$operation][0];
  $grant_intent=self::OPS[$operation][1];
  $grant=MAD4B_Company_Operation_Admission_V1::assess($company,$grant_intent);
  if(is_wp_error($grant))return $grant;
  $target=MAD4B_Manager_Auto_Write_V1::company_target($company);
  if(($target['state']??'')!=='REGISTERED')return self::error('company_target_not_enrolled');
  if(($grant['company_site_uuid']??null)!==($target['site_uuid']??null))
   return self::error('target_binding_mismatch');
  $permitted=$kind==='read'
   ? (!empty($grant['inspection_admitted']) || !empty($grant['policy_delegation_admitted']))
   : !empty($grant['policy_delegation_admitted']);
  if(!$permitted)return self::error('delegation_or_native_authority_missing');
  $classification=self::classify($failure);
  if($failed_stage==='' && $classification!=='initial')
   return self::error('failure_stage_required',422);
  if($failed_stage!=='' && $classification==='initial')
   return self::error('failure_reason_required',422);
  $summary=[
   'contract'=>self::CONTRACT,'company'=>$company,
   'operation'=>$operation,'kind'=>$kind,'grant_intent'=>$grant_intent,
   'environment'=>$target['environment'],'canonical_origin'=>$target['canonical_origin'],
   'site_uuid'=>$target['site_uuid'],
   'administrator_id'=>get_current_user_id(),
   'authorization'=>'existing_exact_company_manager_delegation',
   'last_failed_stage'=>$failed_stage?:null,'last_failure'=>$failure,
   'failure_class'=>$classification,
   'remote_execution_performed'=>false,'production_mutation_performed'=>false,
   'one_time_apply_performed'=>false,'sql_executed'=>false,'shell_executed'=>false,
   'next_stage'=>null,'state'=>'','requirements'=>[],
  ];
  if($classification==='hard_denial') {
   $summary['state']='STOP_AUTHORIZATION_OR_SECURITY_DENIAL';
   $summary['requirements']=['resolve_original_denial_at_authority_source'];
   return $summary;
  }
  $next=0;
  if($failed_stage!=='')$next=array_search($failed_stage,self::STAGES,true)+1;
  if($next>=count(self::STAGES)) {
   $summary['state']='NO_FURTHER_TRANSPORT_TIER';
   return $summary;
  }
  $stage=self::STAGES[$next];
  $summary['next_stage']=$stage;
  $summary['state']='TRANSPORT_CANDIDATE_REQUIRES_INDEPENDENT_ADMISSION';
  $summary['requirements']=[
    'same_site_uuid_origin_company_and_environment',
    'same_administrator_and_delegation_revision',
    'independently_authenticated_transport_and_native_permissions',
    'separate_transport_registration_and_credential_binding',
    'audit_correlation_id_and_idempotency_key',
    'original_provider_result_plus_independent_readback',
  ];
  if($kind==='write') {
   $summary['requirements'][]='restorable_backup_and_operation_specific_rollback';
   $summary['requirements'][]='native_write_capability_and_exact_operation_scope';
  }
  if($stage==='container'){
   $summary['requirements'][]='verified_live_coolify_or_docker_container_id';
   $summary['requirements'][]='container_ownership_and_environment_binding';
   $summary['requirements'][]='never_promote_inactive_coolify_draft_over_live_site';
  }
  if($stage==='vps_ssh') {
   $summary['requirements'][]='independently_authorized_ssh_principal_and_host_fingerprint';
   $summary['requirements'][]='host_bound_operation_scope_and_command_sha256';
   $summary['requirements'][]='no_untrusted_queue_payload_or_raw_shell_in_semantic_executor';
  }
  if(str_starts_with($operation,'maintenance.')){
   $summary['requirements'][]='explicit_one_time_maintenance_operation';
   $summary['requirements'][]='transaction_or_precise_backup_restore_strategy';
   $summary['requirements'][]='no_sql_write_to_live_database_without_native_db_backup_and_readback';
  }
  // This API is a central routing plan, not a signed execution ticket and
  // never accepts a caller-supplied "authenticated" or "ready" bool as proof.
  $summary['provided_transport_claims_ignored']=count($evidence)>0;
  return $summary;
 }
 static function rest():void {
  if(!class_exists('MAD4B_Company_Operation_Admission_V1')||
     !MAD4B_Company_Operation_Admission_V1::central())return;
  register_rest_route(self::ROUTE,'/company-recovery-policy',[
   'methods'=>'GET','permission_callback'=>[__CLASS__,'authorized'],
   'callback'=>static fn()=>rest_ensure_response(self::policy())]);
  register_rest_route(self::ROUTE,'/company-recovery-plan/(?P<company>[a-z][a-z0-9-]{2,47})',[
   'methods'=>'GET','permission_callback'=>[__CLASS__,'authorized'],
   'callback'=>static function(WP_REST_Request $r) {
    $result=self::plan((string)$r['company'],
     (string)$r->get_param('operation'),
     (string)($r->get_param('failed_stage')?:''),
     $r->get_param('failure')?:null);
    return is_wp_error($result)?$result:rest_ensure_response($result);
   }]);
 }
 static function boot():void {add_action('rest_api_init',[__CLASS__,'rest'],80);}
}
MAD4B_Company_Recovery_Ladder_V1::boot();
