<?php
/**
 * Plugin Name: MAD4B Company Environment Registry V2 (Staging)
 * Description: Canonical per-environment identity, connection and capability projection; no delegated write grants.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Environment_Registry_V2 {
    const OPTION='mad4b_company_environment_registry_v2';
    const API='mad4b-central/v1';

    public static function enabled():bool {
        return function_exists('wp_get_environment_type') &&
          wp_get_environment_type()==='staging' &&
          untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
    }
    public static function admin():bool {
        return self::enabled() && current_user_can('manage_options');
    }
    private static function exact_origin($url):bool {
        $u=wp_parse_url((string)$url);
        return is_array($u) && ($u['scheme']??'')==='https' &&
          !empty($u['host']) && !isset($u['user']) && !isset($u['pass']) &&
          !isset($u['port']) && !isset($u['query']) && !isset($u['fragment']) &&
          in_array($u['path']??'',['','/'],true) &&
          strlen($u['host'])<=253 && strpos($u['host'],'..')===false;
    }
    private static function vault_status():array {
        $out=[];
        if(!class_exists('MAD4B_Central_Simple_Connections_V1',false))return $out;
        $response=MAD4B_Central_Simple_Connections_V1::status();
        $data=$response instanceof WP_REST_Response?$response->get_data():[];
        foreach((array)($data['companies']??[]) as $r)
            if(is_array($r)&&is_string($r['company_id']??null))
                $out[$r['company_id']]=($r['configured']??false)===true;
        return $out;
    }
    private static function production_entry($id,$source,$s,$binding,$vault):array {
        $origin=(string)$source['url'];
        $exact=is_array($binding) &&
           ($binding['company_code']??'')===$id &&
           ($binding['canonical_origin']??'')===$origin &&
           ($binding['host_binding_verified']??false)===true &&
           wp_is_uuid((string)($binding['company_site_uuid']??'')) &&
           wp_is_uuid((string)($binding['native_site_profile_uuid']??''));
        $v4=$exact && ($binding['registration_mode']??'')==='p256_signed_site_profile_v4';
        $local=$exact && ($binding['registration_mode']??'')==='local_vps_verified_readback';
        $v3=is_array($s) && ($s['identity_protocol']??'')==='p256_mutual_signed_v3';
        $seen=$v3?strtotime((string)($s['p256_last_verified_at']??'')):false;
        $recent=$seen!==false && $seen<=time() && time()-$seen<=86400;
        return self::entry($id,'production',$origin,($v4||$local)?$binding['company_site_uuid']:null,
           ($v4||$local)?$binding['native_site_profile_uuid']:null,[
            'source'=>'legacy_company_canonical',
            'environment_attested'=>false,
            'site_profile_observed'=>false,
            'signed_v4_centrally_verified'=>$v4,
            'p256_v3_centrally_verified_recent'=>$v3&&$recent,
            'p256_v3_last_verified_at'=>$v3?($s['p256_last_verified_at']??null):null,
            'local_vps_readback_verified'=>$local,
            'profile_digest'=>($v4||$local)?($binding['native_profile_digest']??null):null,
           ],[
            'native_mcp'=>'external_session_only',
            'signed_identity'=>$v4?'v4_exact':(($v3&&$recent)?'v3_recent':'pairing_required'),
            'rest_vault'=>!empty($vault)?'configured_reference_only':'not_configured',
            'wpcli_ssh'=>$local?'local_identity_bound_executor_not_authorized':'no_approved_mutation_runner',
           ]);
    }
    private static function staging_entry($id,$row):array {
        $origin=(string)$row['canonical_origin'];
        // Only the scoped verifier can promote authenticated, current P-256 evidence.
        // Database candidate flags and external ChatGPT OAuth are not central proofs.
        $signed=class_exists('MAD4B_Environment_Signed_Identity_V4',false)
            ? MAD4B_Environment_Signed_Identity_V4::status($id,'staging') : [];
        $verified=is_array($signed) && ($signed['centrally_verified']??false)===true &&
            ($signed['canonical_origin']??'')===$origin &&
            ($signed['site_uuid']??'')===(string)$row['site_uuid'];
        return self::entry($id,'staging',$origin,(string)$row['site_uuid'],null,[
            'source'=>'authenticated_site_profile_observation',
            'environment_attested'=>($row['environment']??'')==='staging',
            'site_profile_observed'=>
                 ($row['source']??'')==='authenticated_mcp_site_profile_status' &&
                 preg_match('/^[a-f0-9]{64}$/D',(string)($row['native_profile_digest']??''))===1,
            // Validated by the environment-scoped verifier, never by candidate flags.
            'signed_v4_centrally_verified'=>$verified,
            'p256_v3_centrally_verified_recent'=>false,
            'p256_v3_last_verified_at'=>null,
            'local_vps_readback_verified'=>false,
            'profile_digest'=>(string)($row['native_profile_digest']??''),
           ],[
            'native_mcp'=>'external_session_only',
            'signed_identity'=>$verified?'v4_exact':'separate_pairing_required',
            'rest_vault'=>'separate_staging_credentials_required',
            'wpcli_ssh'=>'diagnostic_only_not_write_authorized',
           ]);
    }
    private static function entry($id,$environment,$origin,$site_uuid,$profile_uuid,$evidence,$channels):array {
        $identity_ready=!empty($evidence['signed_v4_centrally_verified']) ||
           !empty($evidence['p256_v3_centrally_verified_recent']) ||
           !empty($evidence['original_native_host_ledger_verified']);
        return [
            'company_id'=>$id,'environment'=>$environment,
            'canonical_origin'=>$origin,'site_uuid'=>$site_uuid,'profile_uuid'=>$profile_uuid,
            'identity_evidence'=>$evidence,
            'authorized_connections'=>[
                'native_mcp'=>['state'=>$channels['native_mcp'],
                    'central_execution_authorized'=>false,'credential_reuse_allowed'=>false],
                'signed_identity'=>['state'=>$channels['signed_identity'],
                    'centrally_verified'=>$identity_ready,'scope'=>'identity.read'],
                'rest_vault'=>['state'=>$channels['rest_vault'],
                    'credential_export_allowed'=>false,'central_execution_authorized'=>false],
                'wpcli_ssh'=>['state'=>$channels['wpcli_ssh'],
                    'central_write_authorized'=>false],
            ],
            'capabilities'=>[
                'identity.read'=>['candidate_available'=>$identity_ready,'execute_authorized'=>false],
                'content.read'=>['candidate_available'=>false,'execute_authorized'=>false],
                'content.write'=>['candidate_available'=>false,'execute_authorized'=>false],
                'plugin.install'=>['candidate_available'=>false,'execute_authorized'=>false],
            ],
            'write_authorized'=>false,
            'connection_state'=>$identity_ready?'IDENTITY_PROBE_CANDIDATE':'ENVIRONMENT_BINDING_PENDING',
        ];
    }
    // Central Staging is also a local Demo company target. Its host scope
    // remains non-authorizing; expose only the independently verified
    // company identity through the normal Company Environment Registry.
    private static function original_native_demo_target():?array {
        if (!self::enabled() ||
            !class_exists('MAD4B_Native_VPS_Principal_V1',false))return null;
        $scope=MAD4B_Native_VPS_Principal_V1::scope();
        $slot=MAD4B_Native_VPS_Principal_V1::existing();
        if (!is_array($scope) || !is_array($slot) ||
            ($scope['contract']??'')!=='mad4b.central-host-scope.v1' ||
            ($scope['scope_kind']??'')!=='central_native_host' ||
            ($scope['company_target']??true)!==false ||
            ($scope['owner_enrolled']??false)!==true ||
            ($scope['environment']??'')!=='staging' ||
            ($scope['state']??'')!=='CENTRAL_HOST_IDENTITY_VERIFIED' ||
            !MAD4B_Native_VPS_Principal_V1::ledger($slot,$scope))return null;
        $id=(string)($scope['workload']??'');
        $origin=(string)($scope['canonical_origin']??'');
        $uuid=(string)($scope['site_uuid']??'');
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$id) ||
            !self::exact_origin($origin) || !wp_is_uuid($uuid) ||
            $origin!==untrailingslashit(home_url('/')))return null;
        $row=self::entry($id,'staging',$origin,$uuid,null,[
            'source'=>'original_native_host_ledger',
            'environment_attested'=>true,'site_profile_observed'=>false,
            'signed_v4_centrally_verified'=>false,
            'p256_v3_centrally_verified_recent'=>false,
            'original_native_host_ledger_verified'=>true,
            'local_vps_readback_verified'=>true,'profile_digest'=>null,
        ],[
            'native_mcp'=>'external_session_only',
            'signed_identity'=>'original_native_host_ledger_verified',
            'rest_vault'=>'independent_company_credentials_required',
            'wpcli_ssh'=>'local_identity_only_executor_not_authorized',
        ]);
        $row['role']='self_hosted_demo_target';
        $row['host_authority_reused_for_write']=false;
        return $row;
    }

    public static function discover():array {
        if(!self::enabled() || !class_exists('MAD4B_Central_Company_REST_V1',false))return [];
        $directory=MAD4B_Central_Company_REST_V1::directory();
        $saved=get_option('mad4b_central_companies_v1',[]);
        $bindings=get_option('mad4b_tourism_company_site_registry_v1',[]);
        $environment_rows=get_option('mad4b_portfolio_environment_sites_v1',[]);
        $vault=self::vault_status();
        if(!is_array($saved))$saved=[];
        if(!is_array($bindings))$bindings=[];
        if(!is_array($environment_rows))$environment_rows=[];
        $out=[];
        foreach($directory as $id=>$source) {
            if(!is_string($id)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$id) ||
               !is_array($source)||!self::exact_origin($source['url']??null))continue;
            $out[$id]=['company_id'=>$id,'company_name'=>(string)($source['name']??$id),
                'environments'=>['production'=>self::production_entry($id,$source,$saved[$id]??[],
                    $bindings[$id]??[],!empty($vault[$id]))]];
            $key=$id.':staging';
            $staging=$environment_rows[$key]??null;
            if(is_array($staging) && ($staging['company_id']??'')===$id &&
               ($staging['environment']??'')==='staging' &&
               self::exact_origin($staging['canonical_origin']??null) &&
               wp_is_uuid((string)($staging['site_uuid']??'')) &&
               untrailingslashit((string)$staging['canonical_origin'])!==
                  untrailingslashit((string)$source['url'])) {
                $out[$id]['environments']['staging']=self::staging_entry($id,$staging);
            }
        }
        // Add the central host as its own Demo target only after the original
        // signed host ledger verifies. Never override an existing company
        // registration or produce a new grant from this projection.
        $demo=self::original_native_demo_target();
        if (is_array($demo)) {
            $id=(string)$demo['company_id'];
            if (!isset($out[$id]))
                $out[$id]=['company_id'=>$id,'company_name'=>$id,
                    'environments'=>[]];
            if (!isset($out[$id]['environments']['staging']))
                $out[$id]['environments']['staging']=$demo;
        }
        ksort($out,SORT_STRING);
        return $out;
    }
    public static function target($company,$environment):?array {
        $all=self::discover();
        $key=(string)$company;
        $env=(string)$environment;
        return isset($all[$key]['environments'][$env])?$all[$key]['environments'][$env]:null;
    }
    public static function legacy_targets():array {
        $out=[];
        foreach(self::discover() as $id=>$company)
          foreach($company['environments'] as $env=>$item) {
            $ident=$item['identity_evidence'];
            $state=$item['authorized_connections'];
            $out[$id.':'.$env]=[
                'company_id'=>$id,'environment'=>$env,
                'canonical_origin'=>$item['canonical_origin'],
                'site_uuid'=>$item['site_uuid'],'profile_uuid'=>$item['profile_uuid'],
                'identity'=>[
                    'site_profile_v4_verified'=>$ident['signed_v4_centrally_verified'],
                    'p256_v3_recent'=>$ident['p256_v3_centrally_verified_recent'],
                    'local_vps_binding'=>$ident['local_vps_readback_verified']],
                'channels'=>[
                    'native_mcp'=>['state'=>$state['native_mcp']['state'],
                      'centrally_authorized'=>false],
                    'signed_identity'=>['state'=>$state['signed_identity']['state'],'scope'=>'identity.read'],
                    'rest_vault'=>['state'=>$state['rest_vault']['state'],
                      'scope'=>'identity.read','no_credentials_exported'=>true],
                    'wpcli_ssh'=>['state'=>$state['wpcli_ssh']['state'],'remote_write_allowed'=>false]],
                'writes_enabled'=>false,
            ];
          }
        ksort($out,SORT_STRING);
        return $out;
    }
    public static function reconcile():array {
        if(!self::enabled())return ['state'=>'DISABLED'];
        $companies=self::discover();
        $snapshot=['contract'=>'mad4b.company.environment-registry.v2',
          'schema_version'=>2,'generated_at'=>gmdate('c'),
          'company_count'=>count($companies),
          'environment_count'=>array_sum(array_map(static function($x) {
              return count($x['environments']);},$companies)),
          'companies'=>$companies,'production_mutation'=>false,
          'credentials_exported'=>false,'authority_grants_changed'=>false];
        update_option(self::OPTION,$snapshot,false);
        return $snapshot;
    }
    public static function rest_list():WP_REST_Response {
        return new WP_REST_Response(['contract'=>'mad4b.company.environment-registry.v2',
            'live'=>true,'companies'=>self::discover(),
            'snapshot'=>get_option(self::OPTION,[])],200);
    }
    public static function rest_one(WP_REST_Request $req) {
        $companies=self::discover();
        $id=(string)$req['company'];
        if(!isset($companies[$id]))return new WP_Error('mad4b_company_not_enrolled',
            'Company is not enrolled',['status'=>404]);
        return new WP_REST_Response($companies[$id],200);
    }
    public static function boot():void {
        if(!self::enabled())return;
        add_action('rest_api_init',static function() {
            register_rest_route(self::API,'/company-environments',[
              'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
              'callback'=>[__CLASS__,'rest_list']]);
            register_rest_route(self::API,'/company-environments/(?P<company>[a-z][a-z0-9-]{2,47})',[
              'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
              'callback'=>[__CLASS__,'rest_one']]);
        });
    }
}
MAD4B_Company_Environment_Registry_V2::boot();
