<?php
/**
 * Plugin Name: MAD4B Company Bootstrap Host Proof Bridge
 * Description: Dynamic company and environment scoped proof readiness; no signature generation or grant issuance.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Bootstrap_Proof_Bridge_V2 {
 const CONTRACT='mad4b.company-bootstrap-proof-bridge.v2';
 const NS='mad4b-central/v1';
 private static function valid($company,$environment):bool {
  return is_string($company) && preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)===1
   && in_array($environment,['staging','production'],true);
 }
 public static function state($company,$environment):array {
  $base=['contract'=>self::CONTRACT,'company'=>$company,'environment'=>$environment,
    'request_created'=>false,'proof_signed'=>false,'grant_issued'=>false,
    'host_write_authorized'=>false,'production_applied'=>false];
  if(!self::valid($company,$environment))return $base+['state'=>'INVALID_SCOPE'];
  if(!class_exists('MAD4B_Company_Environment_Registry_V2',false) ||
     !class_exists('MAD4B_Original_New_Site_Bootstrap_Issuer_V1',false))
    return $base+['state'=>'ORIGINAL_DEPENDENCIES_MISSING'];
  $parent=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
  $pending=method_exists('MAD4B_Company_Environment_Registry_V2','reserved_target')
   ? MAD4B_Company_Environment_Registry_V2::reserved_target($company,$environment):null;
  $issuer=MAD4B_Original_New_Site_Bootstrap_Issuer_V1::status($company,$environment);
  $parent_valid=is_array($parent) && ($parent['company_id']??'')===$company
    && ($parent['environment']??'')===$environment
    && wp_is_uuid((string)($parent['site_uuid']??''))
    && filter_var((string)($parent['canonical_origin']??''),FILTER_VALIDATE_URL);
  $status=['parent_registered'=>$parent_valid,
    'pending_reservation_present'=>is_array($pending),
    'pending_reservation_authorizes_host_write'=>false,
    'issuer_state'=>(string)($issuer['state']??'UNKNOWN'),
    'issuer_grant_present'=>($issuer['grant_present']??false)===true,
    'signed_host_proof_worker_connected'=>false,
    'native_313_admitted'=>false];
  if($parent_valid) {
   $status['parent_site_uuid']=(string)$parent['site_uuid'];
   $status['parent_canonical_origin']=(string)$parent['canonical_origin'];
  }
  if(is_array($pending)) {
   $status['pending_application_id']=(int)$pending['application_id'];
   $status['reserved_site_uuid']=(string)$pending['site_uuid'];
   $status['reserved_canonical_origin']=(string)$pending['canonical_origin'];
  }
  $status['state']=!$parent_valid?'PARENT_ENVIRONMENT_ENROLLMENT_REQUIRED':
    (($issuer['grant_present']??false)!==true?'OWNER_ISSUER_GRANT_REQUIRED':'SIGNED_HOST_WORKER_REQUIRED');
  return $base+$status;
 }
 private static function rest_auth(WP_REST_Request $request) {
  if(!is_user_logged_in() || !current_user_can('manage_options') ||
     !current_user_can('activate_plugins'))return new WP_Error('mad4b_company_proof_admin_required','Administrator required',['status'=>403]);
  $nonce=(string)$request->get_header('X-WP-Nonce');
  if($nonce==='' || !wp_verify_nonce($nonce,'wp_rest'))return new WP_Error('mad4b_company_proof_nonce_required','REST nonce required',['status'=>403]);
  $company=(string)$request['company'];
  if(!self::valid($company,(string)$request->get_param('environment')))
   return new WP_Error('mad4b_company_proof_scope_invalid','Invalid scope',['status'=>422]);
  if(!class_exists('MAD4B_Company_Operation_Admission_V1',false) ||
     !class_exists('MAD4B_Company_Portfolio_Manager_V1',false))return false;
  $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
  return $manager!=='' && MAD4B_Company_Portfolio_Manager_V1::has_delegation(
   $manager,$company,'operations_manage',get_current_user_id());
 }
 public static function rest_state(WP_REST_Request $request) {
  $result=rest_ensure_response(self::state((string)$request['company'],(string)$request->get_param('environment')));
  $result->header('Cache-Control','no-store, private');
  return $result;
 }
 public static function cli($args,$assoc):void {
  if(($args[0]??'')!=='status' || count($args)!==1 ||
     !isset($assoc['company'],$assoc['environment']) || count($assoc)!==2)
    WP_CLI::error('EXACT_STATUS_COMPANY_ENVIRONMENT_REQUIRED');
  WP_CLI::line(wp_json_encode(self::state((string)$assoc['company'],(string)$assoc['environment']),JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b company-bootstrap-proof',[__CLASS__,'cli']);
  add_action('rest_api_init',static function(){
   register_rest_route(self::NS,'/companies/(?P<company>[a-z][a-z0-9-]{2,47})/bootstrap-proof/status',[
    'methods'=>WP_REST_Server::READABLE,'callback'=>[__CLASS__,'rest_state'],
    'permission_callback'=>[__CLASS__,'rest_auth'],
    'args'=>['environment'=>['required'=>true,'type'=>'string','enum'=>['staging','production']]]
   ]);
  });
 }
}
MAD4B_Company_Bootstrap_Proof_Bridge_V2::boot();
