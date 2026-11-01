<?php
/**
 * Plugin Name: MAD4B Company Operation Admission Policy (Central Staging)
 * Description: Separate authorized inspection and plan admission from native company write execution.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Operation_Admission_V1 {
 const CONTRACT='mad4b.company-operation-admission.v1';
 const INSPECT=['identity.inspect','environment.inspect','capability.inspect','provider.inspect'];
 const READ=['content.read'=>'view_company','strategy.read'=>'strategy_read',
  'operations.read'=>'operations_read','commerce.read'=>'view_company'];
 const WRITE=['content.write','strategy.write','operations.write','commerce.write'];
 const ROUTE='mad4b/v1';
 static function central():bool {
  return wp_get_environment_type()==='staging'
    && defined('DB_NAME') && DB_NAME==='etg_commerce_staging'
    && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com'
    && class_exists('MAD4B_Company_Portfolio_Manager_V1')
    && class_exists('MAD4B_Manager_Auto_Write_V1')
    && class_exists('MAD4B_SCP_Site_Profile');
 }
 static function actor():bool {
  $id=get_current_user_id();
  return self::central() && $id>0
   && current_user_can('manage_options') && current_user_can('activate_plugins')
   && MAD4B_SCP_Site_Profile::user_is_enrolled($id)
   && MAD4B_SCP_Site_Profile::origin_enrolled()
   && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
 }
 static function deny(string $why,int $http=403):WP_Error {
  return new WP_Error('mad4b_admission_'.$why,'Company operation preflight denied: '.$why,
    ['status'=>$http,'contract'=>self::CONTRACT]);
 }
 static function manager_id(string $code='wo-vacation'):string {
  $data=get_option(MAD4B_Company_Portfolio_Manager_V1::MANAGERS,[]);
  foreach(is_array($data)?$data:[] as $id=>$row)
   if(is_array($row)&&($row['manager_code']??'')===$code&&($row['state']??'')==='registered'
      && wp_is_uuid($id))return $id;
  return '';
 }
 static function assess(string $company,string $operation) {
  if(!self::actor())return self::deny('central_administrator_required');
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company))return self::deny('invalid_company',422);
  if(!in_array($operation,self::INSPECT,true)
   && !array_key_exists($operation,self::READ)
   && !in_array($operation,self::WRITE,true))return self::deny('unknown_operation',422);
  // Resolve the exact environment BEFORE consulting the company-only legacy registry.
  $target=MAD4B_Manager_Auto_Write_V1::company_target($company);
  if(($target['environment']??'')==='staging') {
   $binding=class_exists('MAD4B_Staging_Manager_Admission_Bridge_V1',false)
     ? MAD4B_Staging_Manager_Admission_Bridge_V1::binding($company,'staging')
     : self::deny('staging_manager_bridge_required');
  } else {
   $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($company);
  }
  if(is_wp_error($binding))return self::deny('verified_company_binding_required');
  if(($target['state']??'')!=='REGISTERED')
    return ['contract'=>self::CONTRACT,'company'=>$company,'operation'=>$operation,
      'policy_class'=>'preflight','state'=>'WAITING_EXACT_COMPANY_ENVIRONMENT',
      'inspection_admitted'=>false,'write_execution_authorized'=>false,
      'blockers'=>$target['blockers']??['target_missing']];
  // Inspect operations have no company mutations and cannot be denied merely
  // because the company only has Production or lacks an enrolled write executor.
  $base=[
   'contract'=>self::CONTRACT,'company'=>$company,'operation'=>$operation,
   'principal_user_id'=>get_current_user_id(),
   'company_site_uuid'=>$target['site_uuid'],
   'environment'=>$target['environment'],
   'canonical_origin'=>$target['canonical_origin'],
   'company_staging_required'=>false,
   'inspection_admitted'=>false,
   'policy_delegation_admitted'=>false,
   'write_execution_authorized'=>false,
   'execution_performed'=>false,
   'remote_write_granted'=>false,
   'source'=>'enrolled_central_administrator_exact_company',
   'blockers'=>[]
  ];
  if(($target['site_uuid']??'')!==$binding['company_site_uuid'])
   return self::deny('company_target_uuid_mismatch');
  if(in_array($operation,self::INSPECT,true)) {
   $base['policy_class']='read_only_local_inspection';
   $base['state']='ALLOW_READ_ONLY_INSPECTION';
   $base['inspection_admitted']=true;
   return $base;
  }
  $id=self::manager_id();
  if(!$id)return self::deny('manager_missing');
  if(array_key_exists($operation,self::READ)) {
   $right=self::READ[$operation];
   $allowed=(($target['environment']??'')==='staging')
     ? (class_exists('MAD4B_Staging_Manager_Admission_Bridge_V1',false)
       && MAD4B_Staging_Manager_Admission_Bridge_V1::has_delegation(
          $id,$company,$right,get_current_user_id()))
     : MAD4B_Company_Portfolio_Manager_V1::has_delegation(
          $id,$company,$right,get_current_user_id());
   $base['policy_class']='delegated_read';
   $base['policy_delegation_admitted']=$allowed;
   $base['state']=$allowed?'ALLOW_DELEGATED_READ_PREFLIGHT':'DENY_MISSING_READ_DELEGATION';
   if(!$allowed)$base['blockers'][]='manager_read_right_not_active';
   // Remote read still depends on the relevant provider transport.
   return $base;
  }
  $assessment=MAD4B_Manager_Auto_Write_V1::entitlement(
    $id,$company,$operation,get_current_user_id());
  $base['policy_class']='delegated_write';
  $base['policy_delegation_admitted']=!empty($assessment['assignment_authorized']);
  $base['write_execution_authorized']=!empty($assessment['remote_write_ready']);
  $base['blockers']=$assessment['blockers']??[];
  $base['state']=!$base['policy_delegation_admitted']
    ?'DENY_MISSING_MANAGER_DELEGATION'
    :($base['write_execution_authorized']?'READY_FOR_NATIVE_EXECUTOR':'ADMITTED_WAITING_NATIVE_EXECUTOR');
  $base['native_plan_state']=$assessment['native_plan_state']??'UNKNOWN';
  // This evaluator never executes a write, approves a grant, or issues a ticket.
  return $base;
 }
 static function status() {
  if(!self::actor())return self::deny('central_administrator_required');
  $rows=[];$registry=MAD4B_Manager_Auto_Write_V1::read_opt(MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS);
  foreach($registry as $r) {
   $company=(string)($r['company_code']??'');
   if($company===''||isset($rows[$company]))continue;
   foreach(array_merge(self::INSPECT,array_keys(self::READ),self::WRITE) as $operation) {
    $entry=self::assess($company,$operation);
    if(!is_wp_error($entry))$rows[$company][]=$entry;
   }
  }
  return ['contract'=>self::CONTRACT,'rule'=>'inspections_allowed_writes_need_native_executor',
    'company_staging_required'=>false,'no_secondary_owner_approval'=>true,
    'external_host_tool_safety_not_modified'=>true,'rows'=>$rows];
 }
 static function rest():void {
  if(!self::central())return;
  register_rest_route(self::ROUTE,'/company-operation-admission',[
   'methods'=>'GET','permission_callback'=>[__CLASS__,'actor'],
   'callback'=>static fn()=>rest_ensure_response(self::status())]);
  register_rest_route(self::ROUTE,
   '/company-operation-admission/(?P<company>[a-z][a-z0-9-]{2,47})',[
   'methods'=>'GET','permission_callback'=>[__CLASS__,'actor'],
   'callback'=>static function(WP_REST_Request $r) {
    $result=self::assess((string)$r['company'],(string)$r->get_param('operation'));
    return is_wp_error($result)?$result:rest_ensure_response($result);
   }]);
 }
 static function boot():void { add_action('rest_api_init',[__CLASS__,'rest'],70); }
}
MAD4B_Company_Operation_Admission_V1::boot();
