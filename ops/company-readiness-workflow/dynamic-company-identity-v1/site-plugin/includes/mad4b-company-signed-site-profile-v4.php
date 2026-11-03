<?php
/**
 * Plugin Name: MAD4B Company Signed Site Profile V4
 * Description: Read-only authenticated P-256 site identity endpoint.
 * Version: 0.1.0
 * Deploy only through the target site's approved plugin deployment.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Signed_Site_Profile_V4 {
 const ENDPOINT='/mad4b-company/v1/signed-site-profile-v4';
 const CONTRACT='mad4b.company.signed-site-profile.v4';
 public static function material():array {
  // Existing V3 key provider must supply an authorized mapping. Never create keys
  // here or extract them into the central broker/CLI.
  $keys=apply_filters('mad4b_company_p256_existing_material_v4',[]);
  if(!is_array($keys))return [];
  return $keys;
 }
 public static function payload(array $r):string {
  $keys=['contract','company_id','canonical_origin','site_uuid','profile_uuid',
    'profile_digest','environment','time','nonce','permission_scope'];
  $v=[];
  foreach($keys as $k) {
   if(!is_string($r[$k]??null)||preg_match('/[\r\n]/',$r[$k]))return '';
   $v[]=$r[$k];
  }
  return "RESPONSE\n".self::ENDPOINT."\n".implode("\n",$v);
 }
 public static function identity(WP_REST_Request $request) {
  $id=(string)$request->get_header('X-Mad4b-Company');
  $time=(string)$request->get_header('X-Mad4b-Time');
  $nonce=(string)$request->get_header('X-Mad4b-Nonce');
  $signed=base64_decode((string)$request->get_header('X-Mad4b-Signature'),true);
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$id)||
     !ctype_digit($time)||abs(time()-(int)$time)>90||
     !preg_match('/^[a-f0-9]{32}$/D',$nonce)||!is_string($signed))
   return new WP_Error('mad4b_v4_challenge_invalid','Invalid signed identity challenge',['status'=>403]);
  $origin=untrailingslashit(home_url('/'));
  $host=(string)wp_parse_url($origin,PHP_URL_HOST);
  $ident=get_option('mad4b_company_site_identity_v1',[]);
  $profile=get_option('mad4b_scp_site_profile_v2',[]);
  if(!is_array($profile)||!wp_is_uuid((string)($profile['site_uuid']??'')))
   return new WP_Error('mad4b_v4_unbound_site','Unbound site profile',['status'=>409]);
  // Existing company binding is authoritative; never silently override a
  // different company/site. Only a *missing* record may use an exact pinned
  // read-only adapter backed by the immutable Site Profile.
  if(!is_array($ident)||!$ident) {
    $candidate=apply_filters('mad4b_company_exact_site_identity_v4',[],$id);
    if(!is_array($candidate)||
       ($candidate['company_code']??'')!==$id||
       ($candidate['site_uuid']??'')!==$profile['site_uuid']||
       ($candidate['canonical_origin']??'')!==$origin||
       ($candidate['environment']??'')!=='staging'||
       ($candidate['source']??'')!=='exact_pinned_site_profile_provider_v1')
      return new WP_Error('mad4b_v4_unbound_site','Unbound site profile',['status'=>409]);
    $ident=$candidate;
  }
  if(($ident['company_code']??'')!==$id||
     !wp_is_uuid((string)($ident['site_uuid']??''))||
     ($ident['site_uuid']??'')!==$profile['site_uuid'])
   return new WP_Error('mad4b_v4_unbound_site','Unbound site profile',['status'=>409]);
  // A provider must return an exact independently verified Site Profile.
  // Never treat stored option keys alone as proof of deployment binding.
  $status=apply_filters('mad4b_company_exact_site_profile_v4',[]);
  if((!is_array($status)||!$status) && class_exists('MAD4B_SCP_Site_Profile',false)
     && method_exists('MAD4B_SCP_Site_Profile','status')) {
    $status=MAD4B_SCP_Site_Profile::status();
  }
  if(!is_array($status)||($status['origin_match']??false)!==true||
     ($status['environment_match']??false)!==true||
     ($status['canonical_origin']??'')!==$origin||
     ($status['site_uuid']??'')!==$profile['site_uuid']||
     ($status['environment']??'')!==wp_get_environment_type()||
     !preg_match('/^[a-f0-9]{64}$/D',(string)($status['profile_digest']??'')))
   return new WP_Error('mad4b_v4_profile_inconsistent','Site profile is not exact',['status'=>409]);
  $keys=self::material();
  $central=openssl_pkey_get_public((string)($keys['central_public_pem']??''));
  $private=openssl_pkey_get_private((string)($keys['site_private_pem']??''));
  if(!$central||!$private)
   return new WP_Error('mad4b_v4_v3_key_provider_missing','Existing signed trust provider is unavailable',['status'=>409]);
  $challenge="POST\n".self::ENDPOINT."\n".$id."\n".$time."\n".$nonce."\n".$host;
  if(openssl_verify($challenge,$signed,$central,OPENSSL_ALGO_SHA256)!==1)
   return new WP_Error('mad4b_v4_challenge_signature_invalid','Central signature invalid',['status'=>403]);
  $r=['contract'=>self::CONTRACT,'company_id'=>$id,
   'canonical_origin'=>$origin,'site_uuid'=>$ident['site_uuid'],
   'profile_uuid'=>$profile['site_uuid'],
   'profile_digest'=>$status['profile_digest'],
   'environment'=>(string)$status['environment'],
   'time'=>$time,'nonce'=>$nonce,'permission_scope'=>'company_identity.read',
   'remote_writes_authorized'=>false,'authenticated_request'=>true];
  $body=self::payload($r);
  if($body===''||!openssl_sign($body,$signature,$private,OPENSSL_ALGO_SHA256))
   return new WP_Error('mad4b_v4_sign_failed','Could not sign site identity',['status'=>503]);
  $r['signature']=base64_encode($signature);
  $response=new WP_REST_Response($r,200);
  $response->header('Cache-Control','no-store, private');
  return $response;
 }
 public static function boot():void {
  add_action('rest_api_init',static function(){
   register_rest_route('mad4b-company/v1','/signed-site-profile-v4',[
    'methods'=>WP_REST_Server::CREATABLE,'permission_callback'=>'__return_true',
    'callback'=>[__CLASS__,'identity']]);
  });
 }
}
MAD4B_Company_Signed_Site_Profile_V4::boot();
