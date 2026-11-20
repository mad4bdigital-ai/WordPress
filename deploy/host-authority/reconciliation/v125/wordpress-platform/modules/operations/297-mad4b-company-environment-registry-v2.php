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
            'source'=>($source['registration_source']??'')==='replacement_pending_non_authorizing'
                ? 'replacement_pending_non_authorizing' : 'legacy_company_canonical',
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
        // Signed V4 binds this exact Staging site's original Site Profile UUID.
        // Publish that identity as a read-only descriptor, never as a write grant.
        return self::entry($id,'staging',$origin,(string)$row['site_uuid'],
            $verified ? (string)$signed['site_uuid'] : null,[
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
    /** Discover enrolled extra company environments without inferring writes.
     * An environment registration is a read-only inventory projection; it is
     * not a signed Site Profile, original 305 transport or delegation.
     */
    private static function additional_environment_entry($id,$environment,$row):?array {
        if(!is_string($environment) ||
            !preg_match('/^[a-z][a-z0-9_-]{1,39}$/D',$environment) ||
            in_array($environment,['production','staging'],true) ||
            !is_array($row) || ($row['company_id']??null)!==$id ||
            ($row['environment']??null)!==$environment ||
            !self::exact_origin($row['canonical_origin']??null) ||
            !wp_is_uuid((string)($row['site_uuid']??'')))return null;
        $origin=untrailingslashit((string)$row['canonical_origin']);
        // No signed status is inferred from a row's signed_v4_verified flag.
        // No local WPCLI write authority is inferred from a pinned route.
        $digest=(string)($row['native_profile_digest']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$digest))return null;
        return self::entry($id,$environment,$origin,(string)$row['site_uuid'],null,[
            'source'=>'registered_additional_environment_identity_only',
            'environment_attested'=>false,'site_profile_observed'=>false,
            'signed_v4_centrally_verified'=>false,
            'p256_v3_centrally_verified_recent'=>false,
            'local_vps_readback_verified'=>false,
            'profile_digest'=>$digest,
        ],[
            'native_mcp'=>'exact_scope_separate_enrollment_required',
            'signed_identity'=>'independent_per_environment_proof_required',
            'rest_vault'=>'separate_company_environment_credentials_required',
            'wpcli_ssh'=>'read_candidate_only_not_write_authorized',
        ]);
    }
    private static function entry($id,$environment,$origin,$site_uuid,$profile_uuid,$evidence,$channels):array {
        $identity_ready=!empty($evidence['signed_v4_centrally_verified']) ||
           !empty($evidence['p256_v3_centrally_verified_recent']) ||
           !empty($evidence['original_native_host_ledger_verified']);
        // A separately verified, company-scoped local VPS WP-CLI identity is
        // an eligible *read candidate*, not a P-256 signed identity and
        // never an executable operation or a standing remote write grant.
        $identity_candidate=$identity_ready || !empty($evidence['local_vps_readback_verified']);
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
                'identity.read'=>['candidate_available'=>$identity_candidate,'execute_authorized'=>false],
                'content.read'=>['candidate_available'=>false,'execute_authorized'=>false],
                'content.write'=>['candidate_available'=>false,'execute_authorized'=>false],
                'plugin.install'=>['candidate_available'=>false,'execute_authorized'=>false],
            ],
            'write_authorized'=>false,
            'connection_state'=>$identity_candidate?'IDENTITY_PROBE_CANDIDATE':'ENVIRONMENT_BINDING_PENDING',
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
            // Advisory replacement identity only. Original Site Profile, signed
            // 305 enrollment and write authority must be independently proven.
            if(class_exists('MAD4B_Company_Site_Replacement_V1',false)) {
                $candidate=MAD4B_Company_Site_Replacement_V1::stage_state($id);
                if(in_array(($candidate['state']??''),['PENDING_NATIVE_SITE_PROFILE','PENDING_NATIVE_REGISTRATION','PENDING_NATIVE_305','PENDING_RECONCILE'],true)
                    && ($candidate['canonical_origin']??'')===(string)$source['url']
                    && wp_is_uuid((string)($candidate['new_site_uuid']??''))) {
                    $out[$id]['environments']['production']['replacement_candidate_site_uuid']=(string)$candidate['new_site_uuid'];
                    $out[$id]['environments']['production']['replacement_state']=(string)$candidate['state'];
                    $out[$id]['environments']['production']['replacement_profile_observed']=($candidate['site_profile_enrolled']??false)===true;
                    $out[$id]['environments']['production']['replacement_candidate_is_authority']=false;
                }
            }
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
            // Other registered environment names use the same safe inventory
            // contract, never the Staging proof/grant or the Production row.
            $usedOrigins=[untrailingslashit((string)$source['url'])];
            if(isset($out[$id]['environments']['staging']))
                $usedOrigins[]=untrailingslashit((string)$out[$id]['environments']['staging']['canonical_origin']);
            foreach($environment_rows as $environmentKey=>$additional) {
                if(!is_string($environmentKey) ||
                    strpos($environmentKey,$id.':')!==0)continue;
                $environment=substr($environmentKey,strlen($id)+1);
                $extra=self::additional_environment_entry($id,$environment,$additional);
                if(!$extra || in_array($extra['canonical_origin'],$usedOrigins,true))continue;
                $out[$id]['environments'][$environment]=$extra;
                $usedOrigins[]=$extra['canonical_origin'];
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
    /**
     * Read-only Start Company reservation projection.
     * A pending reservation is NOT an enrolled environment or a write grant.
     */
    public static function reserved_target($company,$environment):?array {
        if (!self::enabled() ||
            !class_exists('MAD4B_Company_Portfolio_Manager_V1',false))return null;
        $company=(string)$company;
        $environment=(string)$environment;
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company) ||
            !in_array($environment,['staging','production'],true))return null;
        $r=MAD4B_Company_Portfolio_Manager_V1::coolify_service_reservation($company);
        if (!is_array($r) || ($r['company_code']??null)!==$company ||
            ($r['environment']??null)!==$environment ||
            ($r['state']??null)!=='PRIVATE_RESERVATION_REQUIRES_NATIVE_BOOTSTRAP' ||
            !wp_is_uuid((string)($r['site_uuid']??'')) ||
            !self::exact_origin($r['canonical_origin']??null) ||
            !is_int($r['application_id']??null) ||
            ($r['resource_type']??null)!=='service' ||
            ($r['public_live']??null)!==false ||
            ($r['native_executor_authorized']??null)!==false)return null;
        $launch=get_post_meta($r['application_id'],'_mad4b_company_launch_v1',true);
        if (!is_array($launch) ||
            ($launch['state']??null)!=='resource_reserved' ||
            ($launch['reserved_target_site_uuid']??null)!==$r['site_uuid'] ||
            ($launch['coolify_resource_uuid']??null)!==$r['coolify_resource_uuid'] ||
            ($launch['target_environment']??null)!==$environment ||
            ($launch['data']['company_code']??null)!==$company ||
            ($launch['public_live']??null)!==false)return null;
        return [
            'contract'=>'mad4b.company.pending-native-reservation.v1',
            'company_id'=>$company,'environment'=>$environment,
            'site_uuid'=>(string)$r['site_uuid'],
            'canonical_origin'=>(string)$r['canonical_origin'],
            'coolify_resource_uuid'=>(string)$r['coolify_resource_uuid'],
            'application_id'=>(int)$r['application_id'],
            'reservation_state'=>'PRIVATE_RESERVATION_REQUIRES_NATIVE_BOOTSTRAP',
            'identity_attested'=>false,'write_authorized'=>false,
            'native_executor_authorized'=>false,
            'public_live'=>false,'eligible_as_parent_target'=>false
        ];
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
                'replacement_candidate_site_uuid'=>$item['replacement_candidate_site_uuid']??null,
                'replacement_state'=>$item['replacement_state']??null,
                'replacement_candidate_is_authority'=>false,
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
