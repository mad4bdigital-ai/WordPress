<?php
/**
 * Plugin Name: MAD4B Company Transport Modes (Central Staging)
 * Description: Read vs managed execution planning without widening site authority.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Transport_Modes_V1 {
    const API='mad4b-central/v1';
    public static function enabled():bool {
        return function_exists('wp_get_environment_type') &&
            wp_get_environment_type()==='staging' &&
            untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
    }
    public static function permission():bool {
        return self::enabled()&&current_user_can('manage_options');
    }
    public static function targets():array {
        if(!self::enabled() ||
           !class_exists('MAD4B_Company_Environment_Registry_V2',false))return [];
        $out=[];
        foreach(MAD4B_Company_Environment_Registry_V2::discover() as $id=>$company) {
            foreach($company['environments'] as $environment=>$row) {
                $key=$id.':'.$environment;
                $out[$key]=[
                    'company_id'=>$id,'environment'=>$environment,
                    'canonical_origin'=>$row['canonical_origin'],
                    'site_uuid'=>$row['site_uuid'],
                    'read_mode'=>[
                        'mode'=>'read',
                        'state'=>$key==='all-royal-egypt:staging' &&
                            $row['site_uuid']==='49c562d1-8f2f-456f-b454-26816c6ba4cb'
                            ? 'PINNED_READ_ADAPTER_ENROLLED' : 'REMOTE_READ_ADAPTER_NOT_ENROLLED',
                        'operations'=>$key==='all-royal-egypt:staging'
                            ? ['health','wordpress_version'] : [],
                    ],
                    'managed_mode'=>[
                        'mode'=>'managed',
                        'state'=>$environment==='production'?'PRODUCTION_MUTATION_DISABLED':
                            'DEDICATED_EXECUTOR_AND_EXACT_GRANT_REQUIRED',
                        'operations'=>[],
                        'remote_write_authorized'=>false,
                    ],
                ];
            }
        }
        ksort($out,SORT_STRING);
        return $out;
    }
    public static function plan(string $company,string $environment,
                                string $mode,string $operation):array {
        $key=$company.':'.$environment;
        $row=self::targets()[$key]??null;
        if(!$row)return [
            'contract'=>'mad4b.company.transport-plan.v1',
            'state'=>'TARGET_NOT_ENROLLED','execution_allowed'=>false,
            'remote_write_authorized'=>false];
        $is_read=$mode==='read' &&
            in_array($operation,['health','wordpress_version'],true) &&
            in_array($operation,$row['read_mode']['operations'],true);
        $is_managed=$mode==='managed' && $environment==='staging' &&
            in_array($operation,['signed_site_agent_install'],true);
        return [
            'contract'=>'mad4b.company.transport-plan.v1',
            'company_id'=>$company,'environment'=>$environment,
            'canonical_origin'=>$row['canonical_origin'],
            'site_uuid'=>$row['site_uuid'],'mode'=>$mode,
            'operation'=>$operation,
            'state'=>$mode==='managed' && $environment==='production'
                ?'PRODUCTION_MUTATION_DISABLED':
                ($is_read?'PINNED_READ_ADAPTER_ENROLLED':
                ($is_managed?'DEDICATED_EXECUTOR_AND_EXACT_GRANT_REQUIRED':
                    'OPERATION_NOT_REGISTERED')),
            // This WordPress API only plans. Execution is on the VPS broker,
            // whose own authorization and signed readback are separate.
            'execution_allowed'=>false,
            'vps_gateway_read_candidate'=>$is_read,
            'remote_write_authorized'=>false,
            'production_mutation'=>false,
            'executor_hint'=>$is_read?'are-staging-pinned-readonly':null,
            'next_action'=>$is_managed?
                'enroll_separate_scoped_managed_executor':'use_bounded_vps_gateway',
        ];
    }
    public static function rest_list() {
        return new WP_REST_Response([
            'contract'=>'mad4b.company.transport-modes.v1',
            'read_mode'=>'bounded_pinned_ssh',
            'managed_mode'=>'separate_capability_scoped_executor',
            'production_mutation'=>false,
            'targets'=>self::targets(),
        ],200);
    }
    public static function rest_plan(WP_REST_Request $req) {
        $a=self::plan((string)$req['company'],
            (string)$req['environment'],(string)$req->get_param('mode'),
            (string)$req->get_param('operation'));
        return new WP_REST_Response($a,200);
    }
    public static function boot():void {
        if(!self::enabled())return;
        add_action('rest_api_init',static function() {
            register_rest_route(self::API,'/company-transport-modes',[
                'methods'=>'GET','permission_callback'=>[__CLASS__,'permission'],
                'callback'=>[__CLASS__,'rest_list']]);
            register_rest_route(self::API,'/company-transport-modes/(?P<company>[a-z][a-z0-9-]{2,47})/(?P<environment>staging|production)/plan',[
                'methods'=>'GET','permission_callback'=>[__CLASS__,'permission'],
                'callback'=>[__CLASS__,'rest_plan']]);
        });
    }
}
MAD4B_Company_Transport_Modes_V1::boot();
