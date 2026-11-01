<?php
declare(strict_types=1);
define('ABSPATH','/isolated-test/');
class WP_Error {
 private string $code;
 public function __construct(string $code, string $message='',array $data=[]) {$this->code=$code;}
 public function get_error_code():string {return $this->code;}
}
function is_wp_error($x):bool {return $x instanceof WP_Error;}
function wp_get_environment_type(){return 'staging';}
function home_url($path=''){return 'https://tours.dev.mad4b.com'.$path;}
function current_user_can($right){return $right==='manage_options';}
function add_action($event,$handler){return true;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function untrailingslashit($url){return rtrim($url,'/');}
function wp_is_uuid($value){return is_string($value)&&preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$value)===1;}
function get_option($key,$fallback=false){return $GLOBALS['options'][$key]??$fallback;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function must(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}
class MAD4B_Central_Company_REST_V1 {
 public static function directory():array {return $GLOBALS['directory'];}
}
class MAD4B_Central_PK_Pairing_V1 {
 public const PRIVATE_OPTION='test_central_pk_private';
}
class MAD4B_Company_Environment_Registry_V2 {
 public static function target($company,$environment) {
  if($environment!=='staging')return null;
  $row=$GLOBALS['options']['mad4b_portfolio_environment_sites_v1'][$company.':staging']??null;
  if(!$row)return null;
  return ['environment'=>'staging','canonical_origin'=>$row['canonical_origin'],'site_uuid'=>$row['site_uuid']];
 }
}
require dirname(__DIR__).'/291-mad4b-central-signed-site-profile-v4.php';
require dirname(__DIR__).'/299-mad4b-environment-signed-identity-v4.php';
$site='49c562d1-8f2f-456f-b454-26816c6ba4cb';
$central=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
openssl_pkey_export($central,$centralPrivate);
$centralPublic=openssl_pkey_get_details($central)['key'];
$staging=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
openssl_pkey_export($staging,$stagingPrivate);
$stagingPublic=openssl_pkey_get_details($staging)['key'];
$production=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
$productionPublic=openssl_pkey_get_details($production)['key'];
$company='all-royal-egypt';$stagingOrigin='https://staging.allroyalegypt.com';
$GLOBALS['directory']=[$company=>['url'=>'https://www.allroyalegypt.com','host'=>'www.allroyalegypt.com'],
'second-company'=>['url'=>'https://www.second-example.test','host'=>'www.second-example.test']];
$GLOBALS['options']=[
 'test_central_pk_private'=>$centralPrivate,
 'mad4b_central_pk_remote_public_v1_all_royal_egypt'=>$productionPublic,
 'mad4b_portfolio_environment_sites_v1'=>[
   $company.':staging'=>[
       'company_id'=>$company,'environment'=>'staging','canonical_origin'=>$stagingOrigin,
       'site_uuid'=>$site,'native_profile_digest'=>str_repeat('d',64)
   ]
 ],
 MAD4B_Environment_Signed_Identity_V4::trust_option($company,'staging')=>$stagingPublic,
];
$GLOBALS['request_count']=0;
$GLOBALS['remote_signature_invalid']=false;
function wp_safe_remote_post($url,$args){
 $GLOBALS['request_count']++;
 $company='all-royal-egypt';$origin='https://staging.allroyalegypt.com';
 must($url===$origin.'/wp-json'.MAD4B_Environment_Signed_Identity_V4::TARGET_PATH,'never contact Production');
 must(($args['redirection']??null)===0 && ($args['sslverify']??null)===true,'TLS and no redirects');
 $h=$args['headers'];$now=$h['X-Mad4b-Time'];$nonce=$h['X-Mad4b-Nonce'];
 $message="POST\n".MAD4B_Environment_Signed_Identity_V4::TARGET_PATH."\n".$company."\n".$now."\n".$nonce."\nstaging.allroyalegypt.com";
 must(openssl_verify($message,base64_decode($h['X-Mad4b-Signature']),openssl_pkey_get_public(openssl_pkey_get_details(openssl_pkey_get_private(get_option('test_central_pk_private')))['key']),OPENSSL_ALGO_SHA256)===1,'central challenge signed');
 $row=['contract'=>MAD4B_Central_Signed_Profile_V4::CONTRACT,'company_id'=>$company,
       'canonical_origin'=>$origin,'site_uuid'=>$GLOBALS['options']['mad4b_portfolio_environment_sites_v1'][$company.':staging']['site_uuid'],
       'profile_uuid'=>$GLOBALS['options']['mad4b_portfolio_environment_sites_v1'][$company.':staging']['site_uuid'],
       'profile_digest'=>str_repeat('d',64),'environment'=>'staging',
       'time'=>$now,'nonce'=>$nonce,'permission_scope'=>'company_identity.read',
       'remote_writes_authorized'=>false,'authenticated_request'=>true];
 $key=openssl_pkey_get_private($GLOBALS['remote_signature_invalid']?get_option('test_central_pk_private'):$GLOBALS['staging_private']);
 openssl_sign(MAD4B_Central_Signed_Profile_V4::payload($row),$sig,$key,OPENSSL_ALGO_SHA256);
 $row['signature']=base64_encode($sig);
 return ['code'=>200,'body'=>json_encode($row)];
}
$GLOBALS['staging_private']=$stagingPrivate;
must(MAD4B_Central_Signed_Profile_V4::enabled(),'legacy entrypoint enabled');
$ok=MAD4B_Central_Signed_Profile_V4::verify($company);
must(is_array($ok)&&($ok['state']??'')==='SIGNED_V4_CENTRAL_PROOF_CURRENT','routed through enrolled Staging verifier');
must($GLOBALS['request_count']===1,'one exact Staging challenge');
must(!isset($GLOBALS['options']['mad4b_tourism_company_site_registry_v1']),'no write into legacy Production registry');
must(MAD4B_Environment_Signed_Identity_V4::status($company,'staging')['centrally_verified'],'fresh proof persists in scoped store');
$prior=$GLOBALS['options'][MAD4B_Environment_Signed_Identity_V4::PROOFS];
unset($GLOBALS['options'][MAD4B_Environment_Signed_Identity_V4::trust_option($company,'staging')]);
$deny=MAD4B_Central_Signed_Profile_V4::verify($company);
must(is_wp_error($deny)&&$deny->get_error_code()==='mad4b_environment_p256_trust_missing','do not substitute Production key');
$GLOBALS['options'][MAD4B_Environment_Signed_Identity_V4::trust_option($company,'staging')]=$stagingPublic;
$GLOBALS['remote_signature_invalid']=true;
$bad=MAD4B_Central_Signed_Profile_V4::verify($company);
must(is_wp_error($bad)&&$bad->get_error_code()==='mad4b_v4_signature_invalid','wrong response signer rejected');
must($GLOBALS['options'][MAD4B_Environment_Signed_Identity_V4::PROOFS]===$prior,'invalid proof cannot mutate ledger');
$GLOBALS['remote_signature_invalid']=false;
$GLOBALS['options']['mad4b_portfolio_environment_sites_v1'][$company.':staging']['canonical_origin']='https://www.allroyalegypt.com';
$bad=MAD4B_Central_Signed_Profile_V4::verify($company);
must(is_wp_error($bad),'Production origin cannot become Staging');
must(MAD4B_Environment_Signed_Identity_V4::trust_option('second-company','staging')!==MAD4B_Environment_Signed_Identity_V4::trust_option($company,'staging'),'multiple companies have separate trust');
echo "DYNAMIC_ENVIRONMENT_SIGNED_V4_HERMETIC_PASS\n";
