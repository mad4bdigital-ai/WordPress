<?php
/**
 * Plugin Name: MAD4B Central Companies REST (MU)
 * Description: Strict, Staging-scoped, administrator-only company registry and remote REST identity verification.
 * Version: 0.1.0
 *
 * This registry does not replicate a WordPress database, administer users or
 * execute remote commands. Read-only remote handshake, no external write routes.
 */
if (!defined('ABSPATH')) { exit; }

final class MAD4B_Central_Company_REST_V1 {
    const OPTION = 'mad4b_central_companies_v1';
    const ROUTE = 'mad4b-central/v1';
    const COMPANIES = [
        'all-royal-egypt' => [
            'name' => 'All Royal Egypt',
            'url' => 'https://www.allroyalegypt.com',
            'host' => 'www.allroyalegypt.com',
            'feature_variant' => 'multi_country_tours',
        ],
        'egypt-tour-gates' => [
            'name' => 'Egypt Tour Gates',
            'url' => 'https://egypttourgates.com',
            'host' => 'egypttourgates.com',
            'feature_variant' => 'daytour_your_way',
        ],
        'tourism-almallahgroup' => [
            'name' => 'Tourism Almallah Group',
            'url' => 'https://tourism.almallahgroup-mg.com',
            'host' => 'tourism.almallahgroup-mg.com',
            'feature_variant' => 'discover_nile_your_way',
        ],
    ];
    /**
     * Discover newly provisioned company records automatically on every read.
     * The Company Provisioning registry owns identity. This projection never
     * grants DNS, site access, or write authority; incomplete records stay pending.
     */
    public static function discovered_from_provisioning($registry): array {
        $result=[];
        if(!is_array($registry) || count($registry)>1000)return $result;
        foreach($registry as $key=>$row){
            if(!is_string($key)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$key)||
               isset(self::COMPANIES[$key])||!is_array($row) ||
               ($row['company_code']??null)!==$key||
               !wp_is_uuid((string)($row['company_site_uuid']??''))||
               !wp_is_uuid((string)($row['native_site_profile_uuid']??''))||
               !preg_match('/^[a-f0-9]{64}$/D',(string)($row['native_profile_digest']??'')))
                continue;
            $url=(string)($row['canonical_origin']??'');
            $components=wp_parse_url($url);
            if(!is_array($components)||($components['scheme']??'')!=='https'||
               empty($components['host'])||isset($components['user'])||
               isset($components['pass'])||isset($components['port'])||
               isset($components['query'])||isset($components['fragment'])||
               !in_array(($components['path']??''),['','/'],true))
                continue;
            $host=strtolower($components['host']);
            if(!preg_match('/^(?=.{4,253}$)[a-z0-9][a-z0-9.-]*[a-z0-9]$/D',$host) ||
               strpos($host,'..')!==false)continue;
            $bound=($row['host_binding_verified']??false)===true;
            $result[$key]=[
                'name'=>$key,'url'=>'https://'.$host,'host'=>$host,
                'feature_variant'=>'provisioned_company',
                'provisioning_verified'=>$bound,
                'registration_source'=>'company_provisioning_registry',
                'company_site_uuid'=>$row['company_site_uuid'],
                'native_site_profile_uuid'=>$row['native_site_profile_uuid']
            ];
        }
        return $result;
    }
    public static function directory():array {
        $legacy=[];
        foreach(self::COMPANIES as $key=>$entry)
            $legacy[$key]=$entry+['provisioning_verified'=>true,'registration_source'=>'legacy_enrolled_registry'];
        $registry=get_option('mad4b_tourism_company_site_registry_v1',[]);
        return $legacy +
            (class_exists('MAD4B_Company_Site_Replacement_V1',false)
                ? MAD4B_Company_Site_Replacement_V1::directory_candidates() : []) +
            self::discovered_from_provisioning($registry);
    }
    public static function enabled(): bool {
        return strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)) === 'tours.dev.mad4b.com';
    }
    public static function secret_option(string $company): string {
        return 'mad4b_central_company_rest_secret_v1_'.str_replace('-','_',$company);
    }
    public static function canonical_list(): array {
        $saved=get_option(self::OPTION,[]);
        if(!is_array($saved))$saved=[];
        $bindings=get_option('mad4b_tourism_company_site_registry_v1',[]);
        if(!is_array($bindings))$bindings=[];
        $all=[];
        foreach(self::directory() as $id=>$static){
            $state=$saved[$id]??[];
            $ready=($static['provisioning_verified']??false)===true;
            $proof=$bindings[$id]??[];
            $valid_proof=is_array($proof) &&
              in_array(($proof['registration_mode']??''),[
                'p256_signed_site_profile_v4','local_vps_verified_readback'],true) &&
              ($proof['company_code']??null)===$id &&
              ($proof['canonical_origin']??null)===$static['url'] &&
              !empty($proof['host_binding_verified']) &&
              wp_is_uuid((string)($proof['company_site_uuid']??'')) &&
              wp_is_uuid((string)($proof['native_site_profile_uuid']??''));
            $all[$id]=[
                'company_id'=>$id,
                'company_name'=>$static['name'],
                'canonical_url'=>$static['url'],
                'canonical_host'=>$static['host'],
                'feature_variant'=>$static['feature_variant'],
                'registration_source'=>$static['registration_source'],
                'provisioning_state'=>$ready?'enrolled':'pending_host_binding',
                'company_site_uuid'=>$static['company_site_uuid']??($valid_proof?$proof['company_site_uuid']:null),
                'native_site_profile_uuid'=>$static['native_site_profile_uuid']??($valid_proof?$proof['native_site_profile_uuid']:null),
                'site_profile_proof'=>$valid_proof?($proof['registration_mode']??''):'unverified',
                'source_of_content'=>'tenant_wordpress',
                'remote_rest_path'=>'/wp-json/mad4b-company/v1/identity',
                'administrative_scope'=>'company_identity.read',
                'credential_configured'=>self::has_secret($id),
                'last_authenticated_check'=>is_array($state)?($state['last_authenticated_check']??null):null,
                'connection_state'=>!$ready?'pending_host_binding':
                    (is_array($state)?($state['connection_state']??'pending_authenticated_pairing'):'pending_authenticated_pairing'),
                'writes_enabled'=>false,
                'full_db_copy_enabled'=>false,
                'historical_rate_publication_enabled'=>false,
            ];
        }
        return $all;
    }
    private static function has_secret(string $id): bool {
        $value=get_option(self::secret_option($id),'');
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D',$value)===1;
    }
    public static function seed(): array {
        if(!self::enabled())throw new RuntimeException('Central registry must run on tours.dev.mad4b.com only');
        $saved=get_option(self::OPTION,[]);
        if(!is_array($saved))$saved=[];
        $result=[];
        foreach(self::directory() as $id=>$data){
            if(!isset($saved[$id])||!is_array($saved[$id])){
                $saved[$id]=['connection_state'=>'pending_authenticated_pairing','last_authenticated_check'=>null];
                $result[]=$id;
            }
        }
        // No third-party values and no credentials in registry.
        update_option(self::OPTION,$saved,false);
        return ['new_records'=>$result,'registered'=>count(self::COMPANIES)];
    }
    public static function admin_only() {
        return self::enabled() && current_user_can('manage_options');
    }
    public static function list_companies() {
        return new WP_REST_Response([
            'contract'=>'mad4b.central-companies.admin-registry.v1',
            'central_site'=>'tours.dev.mad4b.com',
            'company_count'=>count(self::COMPANIES),
            'companies'=>array_values(self::canonical_list()),
            'no_remote_mutation'=>true
        ]);
    }
    public static function status(WP_REST_Request $request) {
        $id=(string)$request['company'];
        $all=self::canonical_list();
        if(!isset($all[$id]))return new WP_Error('mad4b_unknown_company','Unknown enrolled company',['status'=>404]);
        return new WP_REST_Response($all[$id]);
    }
    public static function verify(WP_REST_Request $request) {
        $id=(string)$request['company'];
        $known=self::directory()[$id]??null;
        if(!$known)return new WP_Error('mad4b_unknown_company','Unknown enrolled company',['status'=>404]);
        if(($known['provisioning_verified']??false)!==true)
            return new WP_Error('mad4b_company_host_binding_pending','Provisioning identity not yet bound',['status'=>409]);
        $secret=get_option(self::secret_option($id),'');
        if(!is_string($secret)||preg_match('/^[a-f0-9]{64}$/D',$secret)!==1){
            return new WP_Error('mad4b_company_unpaired','Company credential has not been provisioned',['status'=>409]);
        }
        $time=(string)time();
        try {$nonce=bin2hex(random_bytes(16));}catch(Throwable $e){
            return new WP_Error('mad4b_no_entropy','Unable to create challenge',['status'=>503]);
        }
        $path='/mad4b-company/v1/identity';
        $signature=hash_hmac('sha256',"GET\n".$path."\n".$id."\n".$time."\n".$nonce,$secret);
        $headers=[
            'X-Mad4b-Company'=>$id,
            'X-Mad4b-Time'=>$time,
            'X-Mad4b-Nonce'=>$nonce,
            'X-Mad4b-Signature'=>$signature,
            'Accept'=>'application/json',
        ];
        // Pinned canonical exact hosts. No arbitrary URL or redirects, TLS required.
        $url=$known['url'].'/wp-json'.$path;
        $response=wp_safe_remote_get($url,[
            'headers'=>$headers,
            'timeout'=>10,'redirection'=>0,'reject_unsafe_urls'=>true,
            'limit_response_size'=>32768,'sslverify'=>true,
        ]);
        if(is_wp_error($response)){
            return new WP_Error('mad4b_company_network_unverified','Remote identity request failed',['status'=>502]);
        }
        $status=wp_remote_retrieve_response_code($response);
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if($status!==200 || !is_array($data) ||
           ($data['contract']??'')!=='mad4b.company.remote-rest-identity.v1' ||
           ($data['company_id']??'')!==$id ||
           ($data['canonical_host']??'')!==$known['host'] ||
           ($data['site_root']??'')!==$known['url'] ||
           ($data['rest_authenticated']??false)!==true ||
           ($data['permission_scope']??'')!=='company_identity.read'){
            return new WP_Error('mad4b_company_identity_unverified','Remote signed identity is not valid for this company',['status'=>409]);
        }
        $snapshot=get_option(self::OPTION,[]);
        if(!is_array($snapshot))$snapshot=[];
        if(!isset($snapshot[$id])||!is_array($snapshot[$id]))$snapshot[$id]=[];
        $snapshot[$id]['connection_state']='authenticated_read_only';
        $snapshot[$id]['last_authenticated_check']=gmdate('c');
        update_option(self::OPTION,$snapshot,false);
        $result=[
            'contract'=>'mad4b.central-companies.connection-proof.v1',
            'company_id'=>$id,
            'remote_rest_authenticated'=>true,
            'remote_control_plane_active'=>(bool)($data['control_plane_active']??false),
            'permission_scope'=>'company_identity.read',
            'content_transfer_authorized'=>false,
            'remote_writes_authorized'=>false,
            'historical_rates_for_sale'=>false,
            'checked_at'=>$snapshot[$id]['last_authenticated_check']
        ];
        $rest=new WP_REST_Response($result,200);
        $rest->header('Cache-Control','no-store, private');
        return $rest;
    }
    public static function boot(): void {
        if(!self::enabled())return;
        add_action('rest_api_init',static function(){
            register_rest_route(self::ROUTE,'/companies',[
                'methods'=>WP_REST_Server::READABLE,
                'permission_callback'=>[__CLASS__,'admin_only'],
                'callback'=>[__CLASS__,'list_companies']
            ]);
            register_rest_route(self::ROUTE,'/companies/(?P<company>[a-z0-9-]+)/status',[
                'methods'=>WP_REST_Server::READABLE,
                'permission_callback'=>[__CLASS__,'admin_only'],
                'callback'=>[__CLASS__,'status']
            ]);
            register_rest_route(self::ROUTE,'/companies/(?P<company>[a-z0-9-]+)/verify',[
                'methods'=>WP_REST_Server::CREATABLE,
                'permission_callback'=>[__CLASS__,'admin_only'],
                'callback'=>[__CLASS__,'verify']
            ]);
        });
    }
}
MAD4B_Central_Company_REST_V1::boot();
