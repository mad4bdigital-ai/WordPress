<?php
/**
 * Plugin Name: MAD4B Portfolio VPS MCP Client Fallback
 * Description: Staging-only, enrolled-portfolio owner read/planning fallback; never replaces write authorization.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Portfolio_VPS_MCP_Fallback_V1 {
    const CONTRACT='mad4b.portfolio.vps-mcp-client-fallback.v1';
    const HOST='tours.dev.mad4b.com';
    const ROUTE='mad4b-central/v1';
    private static function scoped(): bool {
        return function_exists('wp_get_environment_type') && wp_get_environment_type()==='staging'
            && strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))===self::HOST
            && class_exists('MAD4B_SCP_Site_Profile',false)
            && MAD4B_SCP_Site_Profile::origin_enrolled()
            && MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
            && class_exists('MAD4B_Central_Company_REST_V1',false);
    }
    public static function permitted($request=null) {
        if(!self::scoped())return new WP_Error('portfolio_fallback_scope_denied','Exact enrolled Staging site required',['status'=>403]);
        if(!is_user_logged_in() || get_current_user_id()<1)return new WP_Error('portfolio_fallback_auth_required','Authenticated central owner required',['status'=>401]);
        if(!current_user_can('manage_options') ||
            !MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id()) ||
            !class_exists('MAD4B_SCP_Policy',false) ||
            !MAD4B_SCP_Policy::can_read())
            return new WP_Error('portfolio_fallback_owner_denied','Exact enrolled central-site owner required',['status'=>403]);
        return true;
    }
    private static function deny($code,$http=409) {
        return new WP_Error('mad4b_portfolio_fallback_'.$code,'Portfolio fallback not eligible: '.$code,['status'=>$http]);
    }
    private static function rows():array {
        if(!self::scoped())return [];
        $data=MAD4B_Central_Company_REST_V1::canonical_list();
        $directory=MAD4B_Central_Company_REST_V1::directory();
        $out=[];
        foreach($data as $key=>$row){
            $id=(string)($row['company_id']??'');
            if($id!==$key || !isset($directory[$key]))continue;
            $known=$directory[$key];
            if(($row['canonical_host']??'')!==$known['host'] ||
               ($row['canonical_url']??'')!==$known['url'])continue;
            $ready=($row['provisioning_state']??'')==='enrolled';
            $verified=$ready && ($row['credential_configured']??false)===true &&
                ($row['connection_state']??'')==='authenticated_read_only';
            $out[$id]=[
                'company_id'=>$id,'company_name'=>(string)$row['company_name'],
                'canonical_host'=>$known['host'],
                'registration_source'=>$row['registration_source']??'unknown',
                'provisioning_state'=>$ready?'enrolled':'pending_host_binding',
                'last_recorded_connection_state'=>(string)($row['connection_state']??'unknown'),
                'live_remote_identity_certified'=>false,
                'credential_configured'=>(bool)($row['credential_configured']??false),
                'remote_read_preconditions_reported'=>$verified,
                'remote_write_authorized'=>false
            ];
        }
        return $out;
    }
    /** Read-only visibility for owner-approved and partial provisioning records. */
    private static function pending():array {
        $pending=[];
        $active=MAD4B_Central_Company_REST_V1::directory();
        $registered=get_option('mad4b_tourism_company_site_registry_v1',[]);
        if(is_array($registered) && count($registered)<=1000)
            foreach($registered as $id=>$row){
                if(!is_string($id)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$id) ||
                   !is_array($row)||($row['company_code']??null)!==$id ||
                   isset($active[$id]))continue;
                $pending[$id]=[
                    'company_code'=>$id,'state'=>'pending_site_identity',
                    'source'=>'company_provisioning_registry',
                    'remote_connection_allowed'=>false,
                    'write_authorized'=>false
                ];
            }
        if(class_exists('MAD4B_VPS_Company_Launch',false) &&
           post_type_exists(MAD4B_VPS_Company_Launch::TYPE)) {
            $ids=get_posts([
                'post_type'=>MAD4B_VPS_Company_Launch::TYPE,
                'post_status'=>'private','fields'=>'ids',
                'posts_per_page'=>100,'orderby'=>'ID','order'=>'DESC'
            ]);
            foreach($ids as $post_id){
                $record=get_post_meta($post_id,MAD4B_VPS_Company_Launch::META,true);
                if(!is_array($record)||($record['contract']??'')!==MAD4B_VPS_Company_Launch::VERSION)
                    continue;
                $id=(string)($record['data']['company_code']??'');
                if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$id) ||
                   isset($active[$id])||isset($pending[$id]))continue;
                $pending[$id]=[
                    'company_code'=>$id,'state'=>sanitize_key((string)($record['state']??'intake_received')),
                    'application_id'=>(int)$post_id,
                    'source'=>'start_your_company_intake',
                    'remote_connection_allowed'=>false,
                    'write_authorized'=>false
                ];
            }
        }
        return array_values($pending);
    }
    public static function status($input=[]) {
        $permission=self::permitted();
        if(is_wp_error($permission))return $permission;
        $write=class_exists('MAD4B_SCP_Runtime_Convergence',false)
            ?MAD4B_SCP_Runtime_Convergence::status():[];
        $scope=self::rows();
        return [
            'contract'=>self::CONTRACT,'environment'=>'staging','central_site'=>home_url('/'),
            'operator_scope'=>'central_portfolio_owner_enrolled_site_user',
            'company_count'=>count($scope),'companies'=>array_values($scope),
            'pending_company_count'=>count(self::pending()),
            'pending_companies'=>self::pending(),
            'new_company_registration'=>'automatic_from_provisioning_registry_on_read',
            'pending_intake_excluded_from_operational_authority'=>true,
            'preferred_transport'=>'external_mcp_when_authenticated_and_reachable',
            'fallback_transport'=>'vps_native_mcp_stdio',
            'fallback_client_installed'=>true,
            'fallback_execution'=>'vps_root_owned_manual_cli_only',
            'read_only_tools'=>['mad4b-site-info'],
            'tenant_scope_must_be_explicit'=>true,'remote_tenant_verification_inherited'=>false,
            'external_401_403_triggers_fallback'=>false,
            'external_owner_session_reused'=>false,
            'owner_oauth_impersonated'=>false,
            'local_mcp_client_is_not_a_write_grant'=>true,
            'write_ready'=>false,
            'write_blockers'=>is_array($write)?($write['required_blockers']??[]):['runtime_status_unavailable'],
            'shared_write_gate_untouched'=>true,
            'cross_company_write_allowed'=>false,'production_mutation'=>false,
            'mutations_performed'=>false
        ];
    }
    public static function plan($input=[]) {
        $permission=self::permitted();
        if(is_wp_error($permission))return $permission;
        if(!is_array($input)||array_diff(array_keys($input),['company_id','operation']))
            return self::deny('invalid_plan_input',400);
        $company=(string)($input['company_id']??'');
        $operation=(string)($input['operation']??'site_info');
        if($operation!=='site_info')return self::deny('write_or_unknown_operation_denied',403);
        $scope=self::rows();
        if(!array_key_exists($company,$scope))return self::deny('unknown_or_unenrolled_company',404);
        return [
            'contract'=>self::CONTRACT,'company'=>$scope[$company],
            'operation'=>'site_info','server'=>'mad4b-chatgpt',
            'read_tool'=>'mad4b-site-info',
            'vps_local_client_invocation'=>'portfolio-mcp-client --company=' . $company,
            'fallback_transport'=>'vps_native_mcp_stdio',
            'can_probe_central_control_mcp_read'=>true,
            'can_read_company_remote'=>false,
            'can_write'=>false,
            'non_mutating'=>true,'execution_performed'=>false,
            'external_401_403_not_retryable_with_fallback'=>true,
            'separate_tenant_write_approval_required'=>true
        ];
    }
    public static function register_abilities():void {
        if(!self::scoped()||!function_exists('wp_register_ability'))return;
        foreach(['mad4b/portfolio-vps-mcp-status'=>'status','mad4b/portfolio-vps-mcp-plan'=>'plan'] as $name=>$method){
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,[
                'label'=>$method==='status'?'Portfolio VPS MCP Fallback Status':'Portfolio VPS MCP Fallback Plan',
                'description'=>'Read-only central owner company-scoped VPS MCP client fallback. No new write privileges.',
                'category'=>'mad4b-read','execute_callback'=>[__CLASS__,$method],
                'permission_callback'=>[__CLASS__,'permitted'],
                'input_schema'=>[
                    'type'=>'object','properties'=>[
                        'company_id'=>['type'=>'string'],
                        'operation'=>['type'=>'string','enum'=>['site_info']]
                    ],'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'show_in_rest'=>false,
                    'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                    'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
            ]);
        }
    }
    public static function rest():void {
        if(!self::scoped())return;
        register_rest_route(self::ROUTE,'/portfolio-vps-client/status',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'permitted'],
            'callback'=>static fn($request)=>rest_ensure_response(self::status())
        ]);
        register_rest_route(self::ROUTE,'/portfolio-vps-client/plan',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'permitted'],
            'callback'=>static fn($request)=>rest_ensure_response(self::plan([
                'company_id'=>(string)$request->get_param('company_id'),
                'operation'=>(string)($request->get_param('operation')?:'site_info')
            ]))
        ]);
    }
    public static function boot():void {
        add_action('wp_abilities_api_init',[__CLASS__,'register_abilities'],60);
        add_action('rest_api_init',[__CLASS__,'rest'],65);
    }
}
MAD4B_Portfolio_VPS_MCP_Fallback_V1::boot();

