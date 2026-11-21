<?php
/**
 * Plugin Name: MAD4B Central Scoped V4 Site Trust
 * Description: Staging-scoped P-256 signed site profile proof; isolated from company Production trust.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Central_Scoped_V4_Trust {
 const TRUST='mad4b_central_scoped_v4_trust_v1';
 const STATE='mad4b_central_scoped_v4_identity_v1';
 const SITE=[
  'all-royal-egypt:staging'=>[
   'company'=>'all-royal-egypt',
   'environment'=>'staging',
   'origin'=>'https://staging.allroyalegypt.com',
   'uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
  ],
 ];
 static function enabled():bool {
  return wp_get_environment_type()==='staging'
   && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
 }
 static function permitted():bool {return self::enabled() && current_user_can('manage_options');}
 static function target(string $id):array {
  if(!isset(self::SITE[$id]))throw new RuntimeException('staging_site_not_enrolled');
  $s=self::SITE[$id];
  $directory=MAD4B_Central_Company_REST_V1::directory();
  if(empty($directory[$s['company']]['provisioning_verified']))
   throw new RuntimeException('company_provisioning_unverified');
  return $s;
 }
 static function register(string $target,string $pem,string $expected_sha):array {
  if(!self::permitted())throw new RuntimeException('staging_admin_required');
  $s=self::target($target);
  $key=openssl_pkey_get_public($pem);
  $details=$key?openssl_pkey_get_details($key):false;
  if(!is_array($details)||($details['type']??null)!==OPENSSL_KEYTYPE_EC||
     ($details['ec']['curve_name']??'')!=='prime256v1')
   throw new RuntimeException('not_p256_public_key');
  $canonical=(string)$details['key'];
  $sha=hash('sha256',$canonical);
  if(!preg_match('/^[a-f0-9]{64}$/D',$expected_sha)||!hash_equals($sha,$expected_sha))
   throw new RuntimeException('public_key_digest_mismatch');
  $trust=get_option(self::TRUST,[]);
  if(!is_array($trust))throw new RuntimeException('trust_registry_invalid');
  if(isset($trust[$target])) {
   $prior=$trust[$target];
   if(!is_array($prior)||($prior['sha256']??'')!==$sha||
      ($prior['site_uuid']??'')!==$s['uuid']||
      ($prior['origin']??'')!==$s['origin'])
    throw new RuntimeException('trust_rotation_requires_explicit_reconciliation');
   return ['state'=>'SCOPED_TRUST_ALREADY_MATCHED','target'=>$target,'sha256'=>$sha];
  }
  $trust[$target]=['pem'=>$canonical,'sha256'=>$sha,
    'site_uuid'=>$s['uuid'],'origin'=>$s['origin'],
    'registered_at'=>gmdate('c')];
  if(!update_option(self::TRUST,$trust,false) && get_option(self::TRUST,[])!==$trust)
   throw new RuntimeException('trust_save_failed');
  return ['state'=>'SCOPED_TRUST_REGISTERED','target'=>$target,'sha256'=>$sha];
 }
 static function verify(string $target) {
  if(!self::permitted())return new WP_Error('mad4b_v4_scope_admin_required','Staging admin required');
  try{$s=self::target($target);}catch(Throwable $e){
   return new WP_Error('mad4b_v4_scope_not_enrolled',$e->getMessage());
  }
  $trust=get_option(self::TRUST,[]);
  $row=is_array($trust)?($trust[$target]??null):null;
  if(!is_array($row)||($row['site_uuid']??'')!==$s['uuid']||
     ($row['origin']??'')!==$s['origin'])
   return new WP_Error('mad4b_v4_staging_trust_missing','Exact scoped trust absent');
  $pem=(string)($row['pem']??'');
  $remote=openssl_pkey_get_public($pem);
  $details=$remote?openssl_pkey_get_details($remote):false;
  if(!is_array($details)||!hash_equals((string)$row['sha256'],
                                      hash('sha256',(string)($details['key']??''))))
   return new WP_Error('mad4b_v4_staging_trust_invalid','Trust digest invalid');
  $central=get_option(MAD4B_Central_PK_Pairing_V1::PRIVATE_OPTION,'');
  $private=is_string($central)?openssl_pkey_get_private($central):false;
  if(!$private)return new WP_Error('mad4b_v4_central_signer_missing','Central signer unavailable');
  $time=(string)time();
  $nonce=bin2hex(random_bytes(16));
  $host=(string)wp_parse_url($s['origin'],PHP_URL_HOST);
  $body="POST\n".MAD4B_Central_Signed_Profile_V4::ENDPOINT."\n".$s['company']."\n".$time."\n".$nonce."\n".$host;
  if(!openssl_sign($body,$signed,$private,OPENSSL_ALGO_SHA256))
   return new WP_Error('mad4b_v4_challenge_sign_failed','Cannot sign challenge');
  $reply=wp_safe_remote_post($s['origin'].'/wp-json'.MAD4B_Central_Signed_Profile_V4::ENDPOINT,[
   'timeout'=>16,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,
   'limit_response_size'=>16384,'headers'=>[
    'X-Mad4b-Company'=>$s['company'],'X-Mad4b-Time'=>$time,
    'X-Mad4b-Nonce'=>$nonce,'X-Mad4b-Signature'=>base64_encode($signed),
    'Accept'=>'application/json',
   ],
  ]);
  if(is_wp_error($reply))
   return new WP_Error('mad4b_v4_remote_unreachable','Remote signed endpoint unavailable');
  $http=wp_remote_retrieve_response_code($reply);
  if($http!==200)
   return new WP_Error('mad4b_v4_remote_http_'.$http,'Remote signed endpoint did not accept challenge');
  $data=json_decode(wp_remote_retrieve_body($reply),true);
  if(!is_array($data))
   return new WP_Error('mad4b_v4_remote_invalid_json','Remote response invalid');
  $known=['url'=>$s['origin'],'host'=>$host];
  $valid=MAD4B_Central_Signed_Profile_V4::check(
   $data,$s['company'],$known,$time,$nonce,$remote
  );
  if(is_wp_error($valid))return $valid;
  if(($data['environment']??'')!==$s['environment']||
     ($data['site_uuid']??'')!==$s['uuid']||
     ($data['profile_uuid']??'')!==$s['uuid'])
   return new WP_Error('mad4b_v4_site_scope_mismatch','Staging site or environment mismatch');
  $records=get_option(self::STATE,[]);
  if(!is_array($records))return new WP_Error('mad4b_v4_scoped_registry_invalid','Registry invalid');
  $records[$target]=[
   'company_id'=>$s['company'],'environment'=>$s['environment'],
   'canonical_origin'=>$s['origin'],'site_uuid'=>$s['uuid'],
   'profile_uuid'=>$data['profile_uuid'],'profile_digest'=>$data['profile_digest'],
   'site_public_sha256'=>$row['sha256'],'verified_at'=>gmdate('c'),
   'mutual_signatures_verified'=>true,'remote_write_authorized'=>false
  ];
  if(!update_option(self::STATE,$records,false) && get_option(self::STATE,[])!==$records)
   return new WP_Error('mad4b_v4_scoped_save_failed','Cannot save signed readback');
  return ['state'=>'SCOPED_SIGNED_SITE_PROFILE_BOUND',
   'target'=>$target,'site_uuid'=>$s['uuid'],'environment'=>'staging',
   'profile_digest'=>$data['profile_digest'],'mutual_signatures_verified'=>true,
   'remote_write_authorized'=>false,'production_mutation'=>false];
 }
 static function boot():void {
  if(!self::enabled()||!defined('WP_CLI')||!WP_CLI)return;
  WP_CLI::add_command('mad4b-v4-scope',new class {
   public function register($args,$assoc) {
    $file=$assoc['public-file']??'';
    $sha=$assoc['sha256']??'';
    if(!is_string($file)||!is_file($file)||is_link($file))
     WP_CLI::error('public_pem_file_required');
    try {
     $v=MAD4B_Central_Scoped_V4_Trust::register($args[0]??'',file_get_contents($file),$sha);
     WP_CLI::line(wp_json_encode($v));
    }catch(Throwable $e){WP_CLI::error($e->getMessage());}
   }
   public function verify($args,$assoc) {
    $v=MAD4B_Central_Scoped_V4_Trust::verify($args[0]??'');
    if(is_wp_error($v))WP_CLI::error($v->get_error_code());
    WP_CLI::line(wp_json_encode($v));
   }
  });
 }
}
MAD4B_Central_Scoped_V4_Trust::boot();
