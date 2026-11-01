<?php
/**
 * Plugin Name: MAD4B Company Public-Key REST Identity
 * Version: 0.1.0
 * Safe: public-key trust + locally held EC P-256 private key. No cross-host secret transfer.
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Company_PK_Identity_V1 {
 const PRIVATE_OPTION='mad4b_company_pk_private_v1';
 const PUBLIC_TRUST_OPTION='mad4b_company_central_pk_trust_v1';
 const ROUTE='/mad4b-company/v1/identity-v3';
 const HOST_COMPANY=[
  'www.allroyalegypt.com'=>'all-royal-egypt',
  'allroyalegypt.com'=>'all-royal-egypt',
  'egypttourgates.com'=>'egypt-tour-gates',
  'tourism.almallahgroup-mg.com'=>'tourism-almallahgroup',
 ];
 public static function host(): string {return strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));}
 public static function identity(): string {return self::HOST_COMPANY[self::host()]??'';}
 public static function public_from_private(string $private): string {
   $key=openssl_pkey_get_private($private);
   $details=$key ? openssl_pkey_get_details($key) : false;
   return is_array($details) ? (string)($details['key']??'') : '';
 }
 public static function init_local_key(): array {
   if(!self::identity() || !function_exists('openssl_pkey_new'))
     throw new RuntimeException('Unregistered company or OpenSSL unavailable');
   if(get_option(self::PRIVATE_OPTION,null)!==null)
     throw new RuntimeException('Company key already present: refuse overwrite');
   $pair=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
   if($pair===false)throw new RuntimeException('Cannot create local EC key');
   $private='';
   if(!openssl_pkey_export($pair,$private)||strlen($private)<80)
     throw new RuntimeException('Failed to export locally generated private key');
   $pub=self::public_from_private($private);
   if(!$pub || !add_option(self::PRIVATE_OPTION,$private,'',false))
     throw new RuntimeException('Could not store local key');
   return ['company_id'=>self::identity(),'public_pem'=>$pub,'private_key_exported'=>false];
 }
 public static function set_central_public(string $pem): bool {
   $key=openssl_pkey_get_public($pem);
   if($key===false)return false;
   $details=openssl_pkey_get_details($key);
   if(!is_array($details)||($details['type']??null)!==OPENSSL_KEYTYPE_EC ||
      ($details['ec']['curve_name']??'')!=='prime256v1')return false;
   if(get_option(self::PUBLIC_TRUST_OPTION,null)!==null) return false;
   return add_option(self::PUBLIC_TRUST_OPTION,$pem,'',false);
 }
 public static function deny($message='Not paired'): WP_Error {
   return new WP_Error('mad4b_public_key_unauthorized',$message,['status'=>403]);
 }
 public static function permission($request) {
   if (!($request instanceof WP_REST_Request) ||
       $request->get_route()!==self::ROUTE || $request->get_method()!=='POST')
     return self::deny();
   $company=self::identity();
   if(!$company || !function_exists('openssl_verify'))return self::deny();
   $private=get_option(self::PRIVATE_OPTION,'');
   $trusted=get_option(self::PUBLIC_TRUST_OPTION,'');
   if(!is_string($private)||!self::public_from_private($private)||!is_string($trusted))
     return self::deny();
   $claimed=(string)$request->get_header('x-mad4b-company');
   $ts=(string)$request->get_header('x-mad4b-time');
   $nonce=(string)$request->get_header('x-mad4b-nonce');
   $sig=(string)$request->get_header('x-mad4b-signature');
   if($claimed!==$company || !preg_match('/^[0-9]{10}$/D',$ts) ||
      abs(time()-(int)$ts)>120 || !preg_match('/^[a-f0-9]{32}$/D',$nonce) ||
      strlen($sig)>180)return self::deny();
   $signature=base64_decode($sig,true);
   if(!is_string($signature) || strlen($signature)<40)return self::deny();
   $message="POST\n".self::ROUTE."\n".$company."\n".$ts."\n".$nonce."\n".self::host();
   $pub=openssl_pkey_get_public($trusted);
   if(!$pub || openssl_verify($message,$signature,$pub,OPENSSL_ALGO_SHA256)!==1)
     return self::deny('Invalid challenge');
   $replay='mad4b_pk_nonce_'.hash('sha256',$company.'|'.$nonce.'|'.$ts);
   // Atomic uniqueness in the SQL table: transients may not persist between requests.
   global $wpdb;
   $insert=$wpdb->query($wpdb->prepare(
       "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,%s)",
       $replay,(string)time(),'no'
   ));
   if($insert!==1)return self::deny('Repeated nonce');
   // Remove old nonces in bounded batches (no user data or content).
   if(random_int(1,40)===1){
       $wpdb->query($wpdb->prepare(
           "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED)<%d LIMIT 120",
           $wpdb->esc_like('mad4b_pk_nonce_').'%',time()-1800
       ));
   }
   return true;
 }
 public static function response(WP_REST_Request $request): WP_REST_Response {
   $company=self::identity();
   $host=self::host();
   $ts=(string)$request->get_header('x-mad4b-time');
   $nonce=(string)$request->get_header('x-mad4b-nonce');
   $payload="RESPONSE\n".self::ROUTE."\n".$company."\n".$ts."\n".$nonce."\n".$host;
   $private=openssl_pkey_get_private((string)get_option(self::PRIVATE_OPTION,''));
   if(!$private || !openssl_sign($payload,$sig,$private,OPENSSL_ALGO_SHA256))
     return new WP_REST_Response(['error'=>'Remote signature unavailable'],503);
   $rest=new WP_REST_Response([
    'contract'=>'mad4b.company.pk-identity.v1',
    'company_id'=>$company,'canonical_host'=>$host,
    'authenticated_request'=>true,'permission_scope'=>'company_identity.read',
    'time'=>$ts,'nonce'=>$nonce,'signature'=>base64_encode($sig),
    'remote_writes_authorized'=>false,'customer_export_authorized'=>false,
    'price_publication_authorized'=>false,'media_transfer_authorized'=>false,
   ],200);
   $rest->header('Cache-Control','no-store, private, max-age=0');
   $rest->header('X-LiteSpeed-Cache-Control','no-cache');
   return $rest;
 }
 public static function boot(): void {
   if(!self::identity())return;
   add_action('rest_api_init',static function(){
    register_rest_route('mad4b-company/v1','/identity-v3',[
     'methods'=>WP_REST_Server::CREATABLE,
     'permission_callback'=>[__CLASS__,'permission'],
     'callback'=>[__CLASS__,'response'],
    ]);
   });
 }
}
MAD4B_Company_PK_Identity_V1::boot();
