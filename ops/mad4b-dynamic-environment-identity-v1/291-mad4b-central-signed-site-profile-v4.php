<?php
/**
 * Plugin Name: MAD4B Central Signed Site Profile V4
 * Description: Staging-only signed site identity reconciliation; no write authority.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Central_Signed_Profile_V4 {
 const ENDPOINT='/mad4b-company/v1/signed-site-profile-v4';
 const OPT='mad4b_tourism_company_site_registry_v1';
 const CONTRACT='mad4b.company.signed-site-profile.v4';
 public static function enabled():bool {
  return wp_get_environment_type()==='staging' && home_url('/')==='https://tours.dev.mad4b.com/';
 }
 public static function payload(array $r):string {
  $keys=['contract','company_id','canonical_origin','site_uuid','profile_uuid',
    'profile_digest','environment','time','nonce','permission_scope'];
  $v=[];
  foreach($keys as $key) {
   if(!isset($r[$key])||!is_string($r[$key])||strpos($r[$key],"\n")!==false||
     strpos($r[$key],"\r")!==false)throw new InvalidArgumentException('invalid_signed_field');
   $v[]=$r[$key];
  }
  return "RESPONSE\n".self::ENDPOINT."\n".implode("\n",$v);
 }
 public static function check(array $r,string $id,array $known,string $time,string $nonce,$remoteKey) {
  if(($r['contract']??'')!==self::CONTRACT || ($r['company_id']??'')!==$id ||
   ($r['canonical_origin']??'')!==$known['url'] ||
   ($r['time']??'')!==$time || ($r['nonce']??'')!==$nonce ||
   ($r['permission_scope']??'')!=='company_identity.read' ||
   ($r['remote_writes_authorized']??true)!==false ||
   ($r['authenticated_request']??false)!==true ||
   !in_array(($r['environment']??''),['staging','production'],true) ||
   !wp_is_uuid((string)($r['site_uuid']??'')) ||
   !wp_is_uuid((string)($r['profile_uuid']??'')) ||
   !preg_match('/^[a-f0-9]{64}$/D',(string)($r['profile_digest']??'')))
    return new WP_Error('mad4b_v4_identity_invalid','Signed site profile fields are incomplete');
  try{$body=self::payload($r);}catch(Throwable $e){
   return new WP_Error('mad4b_v4_payload_invalid','Invalid canonical signature fields');
  }
  $sig=base64_decode((string)($r['signature']??''),true);
  if(!is_string($sig)||openssl_verify($body,$sig,$remoteKey,OPENSSL_ALGO_SHA256)!==1)
   return new WP_Error('mad4b_v4_signature_invalid','Signed site identity verification failed');
  return true;
 }
 public static function verify(string $id) {
  // Staging operations MUST use the original environment-scoped
  // trust store and canonical Site Profile, never Production identity.
  if (!self::enabled() || !current_user_can('manage_options'))
   return new WP_Error('mad4b_v4_permission_denied','Staging admin required');
  if (!class_exists('MAD4B_Environment_Signed_Identity_V4',false) ||
      !class_exists('MAD4B_Company_Environment_Registry_V2',false))
   return new WP_Error('mad4b_v4_environment_resolver_missing','Environment-scoped verifier required');
  $target=MAD4B_Company_Environment_Registry_V2::target($id,'staging');
  if (!is_array($target) || ($target['environment']??'')!=='staging' ||
      !wp_is_uuid((string)($target['site_uuid']??'')) ||
      empty($target['canonical_origin']))
   return new WP_Error('mad4b_v4_staging_target_missing','Exact Staging target required');
  $result=MAD4B_Environment_Signed_Identity_V4::verify($id,'staging');
  if (is_wp_error($result)) return $result;
  if (!is_array($result) ||
      ($result['centrally_verified']??false)!==true ||
      ($result['environment']??'')!=='staging' ||
      ($result['canonical_origin']??'')!==$target['canonical_origin'] ||
      ($result['site_uuid']??'')!==$target['site_uuid'])
   return new WP_Error('mad4b_v4_exact_staging_readback_invalid',
       'Independent environment identity readback failed');
  // No registration in the legacy Production-only Portfolio option.
  // Manager grants and executable transports require separate review.
  return $result;
 }
 public static function boot():void {
  if(!self::enabled())return;
  add_action('rest_api_init',static function(){
   register_rest_route('mad4b-central/v1','/companies/(?P<company>[a-z0-9-]+)/verify-signed-profile',[
    'methods'=>WP_REST_Server::CREATABLE,
    'permission_callback'=>static function(){return current_user_can('manage_options');},
    'callback'=>static function($req){
     $v=self::verify((string)$req['company']);
     return is_wp_error($v)?$v:new WP_REST_Response($v,200);
    }]);
  });
 }
}
MAD4B_Central_Signed_Profile_V4::boot();
