<?php
/**
 * Plugin Name: MAD4B Central Company Public-Key Pairing
 * Version: 0.1.0
 * Uses local EC P-256 private key and remote P-256 public keys only.
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Central_PK_Pairing_V1 {
 const PRIVATE_OPTION='mad4b_central_pk_private_v1';
 const ENDPOINT='/mad4b-company/v1/identity-v3';
 const TRUST_PREFIX='mad4b_central_pk_remote_public_v1_';
 public static function enabled(): bool {
   return strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))==='tours.dev.mad4b.com';
 }
 public static function trust_name(string $company): string {
   return self::TRUST_PREFIX.str_replace('-','_',$company);
 }
 public static function local_public(): string {
   $private=get_option(self::PRIVATE_OPTION,'');
   $key=is_string($private)?openssl_pkey_get_private($private):false;
   $d=$key?openssl_pkey_get_details($key):false;
   return is_array($d)?(string)($d['key']??''):'';
 }
 public static function init_local_key(): string {
   if(!self::enabled()) throw new RuntimeException('Invalid central WordPress site');
   if(get_option(self::PRIVATE_OPTION,null)!==null)throw new RuntimeException('Central private key already exists');
   $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
   if(!$key)throw new RuntimeException('Cannot initialize local signing key');
   $private='';
   if(!openssl_pkey_export($key,$private))throw new RuntimeException('Cannot store signing key');
   $public=openssl_pkey_get_details($key);
   if(!is_array($public) || empty($public['key']) ||
      !add_option(self::PRIVATE_OPTION,$private,'',false))
     throw new RuntimeException('Central local key write failed');
   return (string)$public['key'];
 }
 public static function register_public(string $id,string $pem): bool {
   if(!self::enabled() || !array_key_exists($id,MAD4B_Central_Company_REST_V1::COMPANIES)) return false;
   $p=openssl_pkey_get_public($pem);
   $d=$p?openssl_pkey_get_details($p):false;
   if(!is_array($d)||($d['type']??null)!==OPENSSL_KEYTYPE_EC||
      ($d['ec']['curve_name']??'')!=='prime256v1')return false;
   $option=self::trust_name($id);
   if(get_option($option,null)!==null)return false;
   return add_option($option,$pem,'',false);
 }
 public static function permission(): bool {return self::enabled() && current_user_can('manage_options');}
 public static function verify(WP_REST_Request $request) {
   $id=(string)$request['company'];
   $known=MAD4B_Central_Company_REST_V1::COMPANIES[$id]??null;
   if(!$known) return new WP_Error('mad4b_company_unknown','Unknown company',['status'=>404]);
   $remotePublic=get_option(self::trust_name($id),'');
   $private=get_option(self::PRIVATE_OPTION,'');
   $key=is_string($private)?openssl_pkey_get_private($private):false;
   $rem=is_string($remotePublic)?openssl_pkey_get_public($remotePublic):false;
   if(!$key||!$rem)return new WP_Error('mad4b_company_public_key_unpaired','Local and remote public keys are not provisioned',['status'=>409]);
   $time=(string)time();
   try{$nonce=bin2hex(random_bytes(16));}
   catch(Throwable $e){return new WP_Error('mad4b_entropy_error','Challenge generation failed',['status'=>503]);}
   $payload="POST\n".self::ENDPOINT."\n".$id."\n".$time."\n".$nonce."\n".$known['host'];
   if(!openssl_sign($payload,$signature,$key,OPENSSL_ALGO_SHA256))
     return new WP_Error('mad4b_sign_error','Could not sign challenge',['status'=>503]);
   $response=wp_safe_remote_post($known['url'].'/wp-json'.self::ENDPOINT,[
    'timeout'=>12,'redirection'=>0,'sslverify'=>true,
    'reject_unsafe_urls'=>true,'limit_response_size'=>32768,
    'headers'=>[
      'X-Mad4b-Company'=>$id,
      'X-Mad4b-Time'=>$time,
      'X-Mad4b-Nonce'=>$nonce,
      'X-Mad4b-Signature'=>base64_encode($signature),
      'Accept'=>'application/json',
    ],
   ]);
   if(is_wp_error($response))return new WP_Error('mad4b_remote_network_failed','Remote network request not verified',['status'=>502]);
   $status=wp_remote_retrieve_response_code($response);
   $data=json_decode(wp_remote_retrieve_body($response),true);
   if($status!==200 || !is_array($data) ||
      ($data['contract']??'')!=='mad4b.company.pk-identity.v1' ||
      ($data['company_id']??'')!==$id ||
      ($data['canonical_host']??'')!==$known['host'] ||
      ($data['time']??'')!==$time ||
      ($data['nonce']??'')!==$nonce ||
      ($data['authenticated_request']??false)!==true ||
      ($data['permission_scope']??'')!=='company_identity.read' ||
      ($data['remote_writes_authorized']??true)!==false ||
      ($data['price_publication_authorized']??true)!==false)
     return new WP_Error('mad4b_remote_identity_invalid','Remote identity mismatch',['status'=>409]);
   $signed=base64_decode((string)($data['signature']??''),true);
   $body="RESPONSE\n".self::ENDPOINT."\n".$id."\n".$time."\n".$nonce."\n".$known['host'];
   if(!is_string($signed) || openssl_verify($body,$signed,$rem,OPENSSL_ALGO_SHA256)!==1)
     return new WP_Error('mad4b_remote_signature_invalid','Remote response signature mismatch',['status'=>409]);
   $registry=get_option(MAD4B_Central_Company_REST_V1::OPTION,[]);
   if(!is_array($registry))$registry=[];
   if(!isset($registry[$id])||!is_array($registry[$id]))$registry[$id]=[];
   $registry[$id]['connection_state']='authenticated_read_only';
   $registry[$id]['last_authenticated_check']=gmdate('c');
   // Distinguish mutual P-256 from older REST/HMAC checks sharing the same
   // connection_state; only this proof qualifies for reference sharing.
   $registry[$id]['identity_protocol']='p256_mutual_signed_v3';
   $registry[$id]['p256_last_verified_at']=$registry[$id]['last_authenticated_check'];
   update_option(MAD4B_Central_Company_REST_V1::OPTION,$registry,false);
   $out=new WP_REST_Response([
    'contract'=>'mad4b.central-companies.public-key-identity-proof.v1',
    'company_id'=>$id,'connection_state'=>'authenticated_read_only',
    'mutual_signatures_verified'=>true,'permission_scope'=>'company_identity.read',
    'media_import_authorized'=>false,'remote_writes_authorized'=>false,
    'nile_catalog_transfer_authorized'=>false,'historical_prices_sale_enabled'=>false,
    'verified_at'=>$registry[$id]['last_authenticated_check']
   ],200);
   $out->header('Cache-Control','no-store, private');
   return $out;
 }
 public static function boot(): void {
   if(!self::enabled()) return;
   add_action('rest_api_init',static function() {
    register_rest_route('mad4b-central/v1','/companies/(?P<company>[a-z0-9-]+)/verify-public-key',[
      'methods'=>WP_REST_Server::CREATABLE,
      'permission_callback'=>[__CLASS__,'permission'],
      'callback'=>[__CLASS__,'verify']
    ]);
   });
 }
}
MAD4B_Central_PK_Pairing_V1::boot();
