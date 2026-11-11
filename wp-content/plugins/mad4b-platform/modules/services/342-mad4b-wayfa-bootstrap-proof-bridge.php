<?php
/**
 * Plugin Name: MAD4B Wayfa Original Bootstrap Host Proof Bridge
 * Description: Authenticated request/readback queue only. Host signatures remain root-only on VPS.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Wayfa_Bootstrap_Proof_Bridge_V1 {
 const CONTRACT='mad4b.wayfa-bootstrap-host-proof-bridge.v1';
 const REQ='mad4b_wayfa_bootstrap_proof_request_v1';
 const RES='mad4b_wayfa_bootstrap_proof_response_v1';
 const ROUTE='mad4b-central/v1';
 const COMPANY='wayfatours';
 const ENV='production';
 const UUID='a3a7cc36-db7e-4a14-8456-b41857def05c';
 const ORIGIN='https://wayfatours.com';
 private static function deny($code,$http=403) {
  return new WP_Error('mad4b_bootstrap_proof_'.$code,$code,['status'=>$http]);
 }
 public static function actor(WP_REST_Request $req) {
  if((defined('WP_CLI')&&WP_CLI) || !is_user_logged_in()
    || !current_user_can('manage_options') || !current_user_can('activate_plugins')
    || !class_exists('MAD4B_Original_New_Site_Bootstrap_Issuer_V1',false)
    || !class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
    || !class_exists('MAD4B_Company_Operation_Admission_V1',false)
    || !class_exists('MAD4B_SCP_Site_Profile',false)
    || !MAD4B_Company_Portfolio_Manager_V1::staging()
    || MAD4B_SCP_Site_Profile::current_environment()!=='staging'
    || !MAD4B_SCP_Site_Profile::origin_enrolled()
    || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
    return self::deny('original_owner_browser_required',401);
  $nonce=(string)$req->get_header('X-WP-Nonce');
  if($nonce==='' || wp_verify_nonce($nonce,'wp_rest')===false)
    return self::deny('rest_nonce_required',403);
  $uid=get_current_user_id();
  $users=MAD4B_SCP_Site_Profile::oauth_user_ids();
  if($uid<1 || !is_array($users) || count($users)!==1 || (int)$users[0]!==$uid
    || !defined('LOGGED_IN_COOKIE') || !isset($_COOKIE[LOGGED_IN_COOKIE])
    || (int)wp_validate_auth_cookie((string)$_COOKIE[LOGGED_IN_COOKIE],'logged_in')!==$uid
    || !function_exists('wp_get_session_token') || wp_get_session_token()==='')
    return self::deny('enrolled_owner_browser_required');
  $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
  if($manager==='' || !MAD4B_Company_Portfolio_Manager_V1::has_delegation(
      $manager,self::COMPANY,'operations_manage',$uid))
    return self::deny('exact_company_delegation_required');
  return true;
 }
 private static function parent() {
  if(!class_exists('MAD4B_Company_Environment_Registry_V2',false))return false;
  $p=MAD4B_Company_Environment_Registry_V2::target(self::COMPANY,self::ENV);
  if(!is_array($p) || ($p['company_id']??'')!==self::COMPANY
   || ($p['environment']??'')!==self::ENV
   || !wp_is_uuid((string)($p['site_uuid']??''))
   || !preg_match('~^https://[a-z0-9.-]+$~D',(string)($p['canonical_origin']??'')))return false;
  return $p;
 }
 public static function prepare(WP_REST_Request $req) {
  $body=$req->get_json_params();
  if(!is_array($body) || ($body['operation']??'')!=='new-site.wordpress.bootstrap'
    || ($body['confirm_keep_parent']??null)!==true
    || ($body['site_uuid']??'')!==self::UUID
    || ($body['canonical_origin']??'')!==self::ORIGIN)
    return self::deny('exact_scope_and_parent_confirmation_required',422);
  $parent=self::parent();
  if(!$parent)return self::deny('original_parent_registry_invalid',409);
  $status=MAD4B_Original_New_Site_Bootstrap_Issuer_V1::status(self::COMPANY,self::ENV);
  if(!in_array(($status['state']??''),['NOT_ISSUED','DENIED_OR_EXPIRED',
       'ORIGINAL_NEW_SITE_BOOTSTRAP_ISSUED'],true)
    || ( ($status['state']??'')!=='NOT_ISSUED'
         && ($status['renewal_eligible']??null)!==true))
    return self::deny('original_issuer_not_eligible_for_owner_renewal',409);
  $old=get_option(self::REQ,false);
  if(is_array($old) && ($old['contract']??'')===self::CONTRACT
      && (int)($old['user_id']??0)===get_current_user_id()
      && (int)($old['created_epoch']??0)>time()-30
      && ($old['state']??'')==='PENDING'
      && preg_match('/^[a-f0-9]{32}$/D',(string)($old['request_id']??''))) {
    return rest_ensure_response([
      'state'=>'PENDING','request_id'=>$old['request_id'],'reused'=>true,
      'expected_revision'=>(int)($old['expected_revision']??0)]);
  }
  $id=bin2hex(random_bytes(16));
  $row=['contract'=>self::CONTRACT,'state'=>'PENDING','request_id'=>$id,
    'company'=>self::COMPANY,'environment'=>self::ENV,'site_uuid'=>self::UUID,
    'canonical_origin'=>self::ORIGIN,
    'parent_site_uuid'=>(string)$parent['site_uuid'],
    'parent_origin'=>(string)$parent['canonical_origin'],
    'user_id'=>get_current_user_id(),'created_epoch'=>time(),
    'expected_revision'=>(int)($status['revision']??0),
    'previous_grant_digest'=>$status['previous_grant_digest']??null];
  delete_option(self::RES);
  update_option(self::REQ,$row,false);
  $saved=get_option(self::REQ,false);
  if(!is_array($saved) || ($saved['request_id']??'')!==$id)
    return self::deny('request_persistence_failed',503);
  return rest_ensure_response(['state'=>'PENDING','request_id'=>$id,'reused'=>false,
   'expected_revision'=>$row['expected_revision']]);
 }
 public static function result(WP_REST_Request $req) {
  $id=(string)$req->get_param('request_id');
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))return self::deny('request_id_invalid',422);
  $current=get_option(self::REQ,false);
  if(!is_array($current) || ($current['contract']??'')!==self::CONTRACT
    || ($current['request_id']??'')!==$id
    || (int)($current['user_id']??0)!==get_current_user_id()
    || (int)($current['created_epoch']??0)<time()-120)
    return self::deny('request_expired_or_unrecognized',404);
  $issuer=MAD4B_Original_New_Site_Bootstrap_Issuer_V1::status(self::COMPANY,self::ENV);
  if((int)($issuer['revision']??0)!==(int)($current['expected_revision']??0)
    || ($issuer['previous_grant_digest']??null)!==($current['previous_grant_digest']??null))
    return self::deny('grant_revision_changed_retry_new_proof',409);
  $row=get_option(self::RES,false);
  if(!is_array($row) || ($row['request_id']??'')!==$id)
    return rest_ensure_response(['state'=>'PENDING','request_id'=>$id]);
  if(($row['state']??'')==='FAILED')
    return rest_ensure_response(['state'=>'FAILED','reason_code'=>(string)($row['reason_code']??'HOST_PROOF_NOT_AVAILABLE')]);
  if(($row['state']??'')!=='SIGNED' || !is_array($row['proof']??null))
    return self::deny('invalid_worker_response',503);
  $proof=$row['proof'];
  if(($proof['contract']??'')!=='mad4b.new-site-host-bootstrap-proof.v1'
   || ($proof['company']??'')!==self::COMPANY
   || ($proof['environment']??'')!==self::ENV
   || ($proof['site_uuid']??'')!==self::UUID
   || ($proof['canonical_origin']??'')!==self::ORIGIN
   || ($proof['parent_site_uuid']??'')!==$current['parent_site_uuid']
   || ($proof['parent_origin']??'')!==$current['parent_origin']
   || !is_int($proof['expires']??null) || $proof['expires']<=time()+2)
    return self::deny('signed_proof_scope_or_expiration_invalid',409);
  $response=rest_ensure_response(['state'=>'SIGNED','request_id'=>$id,
    'proof'=>$proof]);
  $response->header('Cache-Control','no-store, private');
  return $response;
 }
 public static function boot() {
  add_action('rest_api_init',static function(){
    $route='/companies/wayfatours/new-site-bootstrap/prepare-proof';
    register_rest_route(self::ROUTE,$route,[
      ['methods'=>WP_REST_Server::CREATABLE,
       'callback'=>[__CLASS__,'prepare'],'permission_callback'=>[__CLASS__,'actor']],
      ['methods'=>WP_REST_Server::READABLE,
       'callback'=>[__CLASS__,'result'],'permission_callback'=>[__CLASS__,'actor']]
    ]);
  });
 }
}
MAD4B_Wayfa_Bootstrap_Proof_Bridge_V1::boot();

// Load the generic read-only bridge alongside the legacy proof worker route.
require_once __DIR__ . '/342-mad4b-company-bootstrap-proof-bridge.php';
