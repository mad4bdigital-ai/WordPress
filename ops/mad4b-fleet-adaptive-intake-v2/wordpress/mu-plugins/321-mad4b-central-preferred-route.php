<?php
/**
 * Plugin Name: MAD4B Central Preferred Host Router
 * Description: VPS Client Cross-SSH-first company operation transport planning; Company Manager OAuth remains the default write authorization path.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Company_Central_Preferred_Router_V1',false)) {
final class MAD4B_Company_Central_Preferred_Router_V1 {
    const OPTION='mad4b_company_central_preferred_router_v1';
    const CONTRACT='mad4b.company-central-preferred-router.v1';
    const ROUTE='mad4b/company-operation-route-plan';
    const ALLOWED_OPERATIONS=array(
        'site.identity.read'=>'read',
        'wordpress.home.read'=>'read',
        'wordpress.wpcli.read'=>'read',
        'site.binding.inspect'=>'read',
        'site.runtime.inspect'=>'read',
        'site.content.inspect'=>'read',
        'fleet-intake.auto'=>'write',
        'fleet-intake.plan'=>'write',
        'fleet-intake.save'=>'write',
        'content.write'=>'write',
        'plugin.install'=>'write',
        'configuration.write'=>'write',
    );
    private static function central() {
        return wp_get_environment_type()==='staging' &&
          untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com' &&
          class_exists('MAD4B_Company_Environment_Registry_V2',false);
    }
    public static function permitted() {
        return self::central() && current_user_can('manage_options');
    }
    private static function error($reason,$status=409) {
        return new WP_Error('mad4b_company_preferred_router_'.$reason,
            'Company routing plan cannot be trusted for this site and operation.',
            array('status'=>$status,'write_executed'=>false,'oauth_grant_issued'=>false));
    }
    private static function projection() {
        $state=get_option(self::OPTION,array());
        if(!is_array($state)||($state['contract']??'')!==self::CONTRACT
           ||($state['origin']??'')!==untrailingslashit(home_url('/'))
           ||($state['read_only_projection']??null)!==true
           ||($state['write_grants_issued']??null)!==false
           ||($state['authorization_default']??'')!=='company_manager_oauth'
           ||($state['preferred_remote_transport']??'')!=='vps_client_mcp_cross_ssh'
           ||!is_array($state['sites']??null)
           ||($state['target_count']??null)!==count($state['sites'])
           ||(int)($state['generated_epoch']??0)>time()+60
           ||(int)($state['expires_epoch']??0)<time()
           ||(int)($state['expires_epoch']??0)>(int)($state['generated_epoch']??0)+1800)
            return self::error('expired_or_invalid_host_projection',503);
        $checksum=(string)($state['projection_sha256']??'');
        $payload=$state;unset($payload['projection_sha256']);
        $expected=hash('sha256',wp_json_encode(self::sort_recursive($payload),JSON_UNESCAPED_SLASHES));
        if(!preg_match('/^[a-f0-9]{64}$/D',$checksum)
           ||!hash_equals($checksum,$expected))
            return self::error('host_route_proof_digest_changed',409);
        return $state;
    }
    private static function sort_recursive($value) {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as $k=>$v)$value[$k]=self::sort_recursive($v);
        return $value;
    }
    public static function plan_for($company,$env,$operation) {
        $company=(string)$company;$env=(string)$env;$operation=(string)$operation;
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
           ||!in_array($env,array('staging','production'),true)
           ||!isset(self::ALLOWED_OPERATIONS[$operation]))
            return self::error('unsupported_scope_or_operation',400);
        if(!self::central())return self::error('not_original_central',403);
        $registry=MAD4B_Company_Environment_Registry_V2::target($company,$env);
        if(!is_array($registry) || ($registry['company_id']??'')!==$company
           ||($registry['environment']??'')!==$env)
            return self::error('target_not_in_original_central_registry',404);
        $data=self::projection();
        if(is_wp_error($data))return $data;
        $key=$company.':'.$env;$site=$data['sites'][$key]??null;
        if(!is_array($site)||($site['company_id']??'')!==$company
           ||($site['environment']??'')!==$env
           ||($site['site_uuid']??null)!==($registry['site_uuid']??null)
           ||($site['canonical_origin']??'')!==untrailingslashit((string)($registry['canonical_origin']??'')))
            return self::error('original_company_identity_changed',409);
        $type=self::ALLOWED_OPERATIONS[$operation];
        $ready=$site['transport_ready']===true;
        $selected=$ready?(string)($site['preferred_transport']??''):null;
        if($selected!==null&&!in_array($selected,array('vps_client_mcp_cross_ssh',
            'vps_client_local_wpcli'),true))
            return self::error('host_route_provider_unrecognized',409);
        $is_read=$type==='read';
        $state=!$ready?(string)($site['state']??'TRANSPORT_NOT_VERIFIED'):
           ($is_read?'ROUTE_READY_REQUIRES_ORIGINAL_READ_ADMISSION':
           'TRANSPORT_READY_WRITE_AUTHORITY_REQUIRED');
        $response=array(
            'contract'=>self::CONTRACT.'.plan',
            'company_id'=>$company,'environment'=>$env,
            'site_uuid'=>$site['site_uuid'],
            'canonical_origin'=>$site['canonical_origin'],
            'operation'=>$operation,'operation_class'=>$type,
            'state'=>$state,'selected_transport'=>$selected,
            'transport_preference'=>array('vps_client_mcp_cross_ssh','company_manager_connected_api'),
            'authorization_default'=>'company_manager_oauth',
            'authorization_owner'=>'MAD4B Company Manager',
            'next_authorization_operation'=>$is_read?'USE_EXISTING_READ_ADMISSION':
              'COMPANY_MANAGER_OAUTH_EXACT_OPERATION_GRANT_OR_STEP_UP',
            'authorization_required_for_original_write'=>!$is_read,
            'write_execution_authorized'=>false,
            'input_intake_itself_grants_write'=>false,
            'after_authorization_executor'=>$selected===null?null:
             ($selected==='vps_client_mcp_cross_ssh'?'original_vps_client_cross_ssh_wpcli':
              'original_local_wpcli'),
            'original_wordpress_permissions_required'=>true,
            'original_313_receipt_required'=>!$is_read,
            'original_operation_executed'=>false,
            'original_313_receipt_issued'=>false,
            'fallback_policy'=>'PRE_DISPATCH_ONLY_NO_SILENT_RETRY_SAME_TARGET',
            'projection_epoch'=>$data['generated_epoch'],
            'projection_expires_epoch'=>$data['expires_epoch'],
            'host_projection_sha256'=>$data['projection_sha256']);
        if(!empty($site['reason']))$response['route_blocker']=$site['reason'];
        return $response;
    }
    public static function routing_policy($company,$env,$operation) {
        $map=array(
            'site.binding.inspect'=>'site.binding.inspect',
            'site.readiness.run'=>'site.runtime.inspect',
            'site.runtime.inspect'=>'site.runtime.inspect',
            'site.content.inspect'=>'site.content.inspect',
            'site.presentation.inspect'=>'site.runtime.inspect',
        );
        $route=self::plan_for($company,$env,$map[$operation]??'site.binding.inspect');
        if(is_wp_error($route))return array(
            'state'=>'ROUTER_PREFLIGHT_REQUIRED',
            'preferred_lane'=>null,
            'candidate_lanes'=>array('vps_client_mcp_cross_ssh','company_manager_connected_api'),
            'default_authorization'=>'company_manager_oauth',
            'write_authorized'=>false,'original_313_certified'=>false);
        return array('state'=>$route['state'],
            'preferred_lane'=>$route['selected_transport'],
            'candidate_lanes'=>array('vps_client_mcp_cross_ssh','company_manager_connected_api'),
            'default_authorization'=>'company_manager_oauth',
            'selected_transport_is_write_authorization'=>false,
            'write_authorized'=>false,
            'original_313_certified'=>false,
            'projection_sha256'=>$route['host_projection_sha256'],
            'explanation'=>'Original VPS Client owns remote transport; Company Manager owns OAuth reauthorization and grants.');
    }
    public static function rest() {
        register_rest_route('mad4b-company-management/v1','/operation-route-plan',array(
            'methods'=>'GET','permission_callback'=>array(__CLASS__,'permitted'),
            'callback'=>static function($r) {
                return self::plan_for((string)$r->get_param('company'),
                    (string)$r->get_param('environment'),
                    (string)$r->get_param('operation'));
            }));
    }
    public static function ability() {
        if(!self::central()||!function_exists('wp_register_ability'))return;
        if(function_exists('wp_has_ability')&&wp_has_ability(self::ROUTE))return;
        wp_register_ability(self::ROUTE,array(
            'label'=>'MAD4B Company Preferred Operation Router',
            'description'=>'Read-only host-verified company transport preference and default Company Manager OAuth authorization route. Does not grant or execute WordPress writes.',
            'category'=>'mad4b-read',
            'permission_callback'=>array(__CLASS__,'permitted'),
            'execute_callback'=>static function($a) {
                if(!is_array($a))return self::error('invalid_args',400);
                return self::plan_for($a['company']??'',$a['environment']??'',
                    $a['operation']??'');
            },
            'input_schema'=>array('type'=>'object','properties'=>array(
                'company'=>array('type'=>'string'),
                'environment'=>array('type'=>'string','enum'=>array('staging','production')),
                'operation'=>array('type'=>'string','enum'=>array_keys(self::ALLOWED_OPERATIONS))
            ),'required'=>array('company','environment','operation'),
                'additionalProperties'=>false),
            'output_schema'=>array('type'=>'object','additionalProperties'=>true),
            'meta'=>array('public'=>false,
                'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))
        ));
    }
    public static function boot() {
        if(!self::central())return;
        add_action('rest_api_init',array(__CLASS__,'rest'));
        add_action('wp_abilities_api_init',array(__CLASS__,'ability'),49);
    }
}
MAD4B_Company_Central_Preferred_Router_V1::boot();
}
