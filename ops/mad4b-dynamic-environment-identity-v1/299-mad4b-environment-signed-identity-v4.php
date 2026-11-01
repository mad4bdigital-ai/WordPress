<?php
/**
 * Plugin Name: MAD4B Per-Environment Signed Identity V4 (Central Staging)
 * Description: Environment-scoped authenticated identity challenge, no remote mutations.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Environment_Signed_Identity_V4 {
    const TRUST_PREFIX='mad4b_environment_p256_trust_v1_';
    const PROOFS='mad4b_environment_signed_proofs_v1';
    const API='mad4b-central/v1';
    const TARGET_PATH='/mad4b-company/v1/signed-site-profile-v4';
    const TTL=86400;
    public static function enabled():bool {
        return function_exists('wp_get_environment_type') &&
            wp_get_environment_type()==='staging' &&
            untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
    }
    public static function permission():bool {
        return self::enabled()&&current_user_can('manage_options');
    }
    public static function trust_option(string $company,string $environment):string {
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company) ||
           !in_array($environment,['staging'],true))return '';
        return self::TRUST_PREFIX.str_replace('-','_',$company).'_'.$environment;
    }
    private static function issue(string $code,int $status=409) {
        return new WP_Error($code,'Exact environment identity attestation not ready',['status'=>$status]);
    }
    private static function target(string $company,string $env) {
        // Resolve from raw enrolled Staging record; V2 consumes this status.
        if(!self::enabled() || $env!=='staging' ||
           !class_exists('MAD4B_Central_Company_REST_V1',false))return null;
        $directory=MAD4B_Central_Company_REST_V1::directory();
        if(!isset($directory[$company]))return null;
        $rows=get_option('mad4b_portfolio_environment_sites_v1',[]);
        $row=is_array($rows)?($rows[$company.':staging']??null):null;
        if(!is_array($row)||($row['company_id']??'')!==$company||
           ($row['environment']??'')!=='staging' ||
           !wp_is_uuid((string)($row['site_uuid']??'')) ||
           !preg_match('/^[a-f0-9]{64}$/D',(string)($row['native_profile_digest']??'')))return null;
        $origin=(string)($row['canonical_origin']??'');
        $url=wp_parse_url($origin);
        if(!is_array($url)||($url['scheme']??'')!=='https'||
           empty($url['host'])||isset($url['user'])||isset($url['pass'])||
           isset($url['port'])||isset($url['query'])||isset($url['fragment'])||
           !in_array($url['path']??'',['','/'],true)||
           untrailingslashit($origin)===untrailingslashit((string)$directory[$company]['url']))return null;
        return ['company_id'=>$company,'environment'=>'staging',
           'canonical_origin'=>$origin,'site_uuid'=>(string)$row['site_uuid'],
           'identity_evidence'=>['profile_digest'=>(string)$row['native_profile_digest']]];
    }
    private static function trust(string $company,string $env):array {
        $name=self::trust_option($company,$env);
        if($name==='')return [];
        $raw=get_option($name,'');
        if(!is_string($raw) || strlen($raw)>7000)return [];
        $key=openssl_pkey_get_public($raw);
        $details=$key?openssl_pkey_get_details($key):false;
        if(!is_array($details)||($details['type']??null)!==OPENSSL_KEYTYPE_EC||
           ($details['ec']['curve_name']??'')!=='prime256v1')return [];
        return ['public_key'=>$key,'sha256'=>hash('sha256',(string)$details['key'])];
    }
    public static function status(string $company,string $env):array {
        $target=self::target($company,$env);
        if(!$target)return ['state'=>'EXACT_ENVIRONMENT_TARGET_NOT_ENROLLED',
            'centrally_verified'=>false,'remote_write_authorized'=>false];
        $key=self::trust($company,$env);
        $proofs=get_option(self::PROOFS,[]);
        $proof=is_array($proofs)?($proofs[$company.':'.$env]??null):null;
        $valid=is_array($proof)&&!empty($key)&&
            ($proof['contract']??'')==='mad4b.environment.signed-v4-proof.v1' &&
            ($proof['company_id']??'')===$company &&
            ($proof['environment']??'')===$env &&
            ($proof['canonical_origin']??'')===$target['canonical_origin'] &&
            ($proof['site_uuid']??'')===$target['site_uuid'] &&
            ($proof['profile_digest']??'')===($target['identity_evidence']['profile_digest']??'') &&
            hash_equals($key['sha256'],(string)($proof['trust_key_sha256']??'')) &&
            ($proof['verified_at_epoch']??0)>0 &&
            ($proof['verified_at_epoch']??0)<=time() &&
            time()-(int)$proof['verified_at_epoch']<=self::TTL;
        return [
            'contract'=>'mad4b.environment.signed-identity-status.v1',
            'company_id'=>$company,'environment'=>$env,
            'canonical_origin'=>$target['canonical_origin'],
            'site_uuid'=>$target['site_uuid'],
            'state'=>$valid?'SIGNED_V4_CENTRAL_PROOF_CURRENT':
                (empty($key)?'EXACT_ENVIRONMENT_TRUST_NOT_PROVISIONED':'SIGNED_V4_PROOF_NOT_CURRENT'),
            'central_trust_configured'=>!empty($key),
            'centrally_verified'=>$valid,
            'verified_at'=>$valid?($proof['verified_at']??null):null,
            'remote_write_authorized'=>false,
        ];
    }
    public static function verify(string $company,string $env) {
        if(!self::permission())return self::issue('mad4b_environment_admin_required',403);
        $target=self::target($company,$env);
        if(!$target)return self::issue('mad4b_environment_exact_target_missing',404);
        $key=self::trust($company,$env);
        if(!$key)return self::issue('mad4b_environment_p256_trust_missing');
        if(!class_exists('MAD4B_Central_PK_Pairing_V1',false) ||
            !class_exists('MAD4B_Central_Signed_Profile_V4',false))
            return self::issue('mad4b_environment_crypto_runtime_unavailable',503);
        $priv=get_option(MAD4B_Central_PK_Pairing_V1::PRIVATE_OPTION,'');
        $private=is_string($priv)?openssl_pkey_get_private($priv):false;
        if(!$private)return self::issue('mad4b_environment_central_signing_key_missing');
        $time=(string)time();
        $nonce=bin2hex(random_bytes(16));
        $origin=$target['canonical_origin'];
        $host=wp_parse_url($origin,PHP_URL_HOST);
        $directory=MAD4B_Central_Company_REST_V1::directory();
        $production=isset($directory[$company]['url']) ?
            wp_parse_url((string)$directory[$company]['url'],PHP_URL_HOST) : null;
        if(!is_string($host) || $host==='' ||
           !is_string($production) || $production==='' ||
           strcasecmp($host,$production)===0)
            return self::issue('mad4b_environment_origin_scope_invalid',403);
        $body="POST\n".self::TARGET_PATH."\n".$company."\n".$time."\n".$nonce."\n".$host;
        if(!openssl_sign($body,$signed,$private,OPENSSL_ALGO_SHA256))
            return self::issue('mad4b_environment_challenge_sign_failed',503);
        $response=wp_safe_remote_post($origin.'/wp-json'.self::TARGET_PATH,[
            'timeout'=>12,'redirection'=>0,'sslverify'=>true,
            'reject_unsafe_urls'=>true,'limit_response_size'=>16384,
            'headers'=>['X-Mad4b-Company'=>$company,'X-Mad4b-Time'=>$time,
                'X-Mad4b-Nonce'=>$nonce,'X-Mad4b-Signature'=>base64_encode($signed),
                'Accept'=>'application/json']]);
        if(is_wp_error($response))return self::issue('mad4b_environment_remote_unavailable',502);
        if(wp_remote_retrieve_response_code($response)!==200)
            return self::issue('mad4b_environment_signed_endpoint_not_ready',409);
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if(!is_array($data))return self::issue('mad4b_environment_signed_response_invalid');
        if(($data['environment']??'')!==$env ||
            ($data['site_uuid']??'')!==$target['site_uuid'] ||
            ($data['profile_digest']??'')!==($target['identity_evidence']['profile_digest']??'') ||
            ($data['profile_uuid']??'')!==$target['site_uuid'])
            return self::issue('mad4b_environment_signed_profile_mismatch');
        $ok=MAD4B_Central_Signed_Profile_V4::check($data,$company,
            ['url'=>$origin],$time,$nonce,$key['public_key']);
        if(is_wp_error($ok))return $ok;
        $existing=get_option(self::PROOFS,[]);
        if(!is_array($existing))return self::issue('mad4b_environment_proof_store_invalid');
        $existing[$company.':'.$env]=[
            'contract'=>'mad4b.environment.signed-v4-proof.v1',
            'company_id'=>$company,'environment'=>$env,
            'canonical_origin'=>$origin,'site_uuid'=>$target['site_uuid'],
            'profile_digest'=>$data['profile_digest'],
            'trust_key_sha256'=>$key['sha256'],
            'verified_at_epoch'=>time(),'verified_at'=>gmdate('c'),
            'remote_write_authorized'=>false,
        ];
        if(!update_option(self::PROOFS,$existing,false) &&
            get_option(self::PROOFS,[])!==$existing)
            return self::issue('mad4b_environment_proof_persistence_failed',503);
        return self::status($company,$env);
    }
    public static function rest_status(WP_REST_Request $request) {
        return new WP_REST_Response(self::status((string)$request['company'],
            (string)$request['environment']),200);
    }
    public static function rest_verify(WP_REST_Request $request) {
        $response=self::verify((string)$request['company'],(string)$request['environment']);
        return is_wp_error($response)?$response:new WP_REST_Response($response,200);
    }
    public static function boot():void {
        if(!self::enabled())return;
        add_action('rest_api_init',static function(){
            $path='/company-environments/(?P<company>[a-z][a-z0-9-]{2,47})/(?P<environment>staging)/signed-v4';
            register_rest_route(self::API,$path,[
                ['methods'=>WP_REST_Server::READABLE,
                 'permission_callback'=>[__CLASS__,'permission'],
                 'callback'=>[__CLASS__,'rest_status']],
                ['methods'=>WP_REST_Server::CREATABLE,
                 'permission_callback'=>[__CLASS__,'permission'],
                 'callback'=>[__CLASS__,'rest_verify']],
            ]);
        });
    }
}
MAD4B_Environment_Signed_Identity_V4::boot();
