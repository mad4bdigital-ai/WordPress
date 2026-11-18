<?php
/** Deployment preflight: read-only, never creates authority or changes WP roles. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Company_Release_Status {
    const VERSION='REV121-staging-candidate.1';
    public static function status($request) {
        $settings=class_exists('MAD4B_SCP_Readiness_Workflow_Options')?
            MAD4B_SCP_Readiness_Workflow_Options::settings():array();
        $role=get_role('master_admin');
        $pageRows=array();
        if(function_exists('jet_engine') && isset(jet_engine()->options_pages)) {
            foreach(jet_engine()->options_pages->get_items() as$p) {
                if(in_array($p['slug']??'',array('company-communication-operations','company-operations-policies'),true))
                    $pageRows[]=['slug'=>$p['slug'],'id'=>(int)$p['id'],'field_count'=>count($p['fields']??[])];
            }
        }
        $cap=$role?(bool)$role->has_cap('mad4b_manage_company_operations'):false;
        $profile=get_option('mad4b_scp_site_profile_v2',array());
        $result=['contract'=>'mad4b.company.release-preflight.v1',
            'release'=>self::VERSION,
            'environment'=>wp_get_environment_type(),
            'site_home'=>home_url('/'),
            'state'=>'candidate_only_no_operational_grant',
            'master_role_exists'=>(bool)$role,
            'master_operations_cap_granted'=>$cap,
            'master_core_user_promotion_allowed'=>$role?(bool)$role->has_cap('promote_users'):false,
            'master_plugin_install_allowed'=>$role?(bool)$role->has_cap('install_plugins'):false,
            'options_pages'=>$pageRows,
            'board_id'=>(int)($settings['board_id']??0),
            'site_profile_present'=>is_array($profile)&&!empty($profile),
            'site_write_policy_open'=>class_exists('MAD4B_SCP_Policy')?(bool)MAD4B_SCP_Policy::can_mutate():false,
            'fluent_smtp_active'=>function_exists('is_plugin_active') && is_plugin_active('fluent-smtp/fluent-smtp.php'),
            'operational_rollout_ready'=>false,
            'reasons'=>['role_migration_not_authorized','jetengine_company_options_not_imported','governed_write_closed','no_multi_tenant_e2e_certification'],
            'no_mutation_performed'=>true];
        $response=rest_ensure_response($result);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public static function boot():void {
        add_action('rest_api_init',static function(){
            register_rest_route('mad4b/v1','/company-release/status',[
                'methods'=>'GET',
                'permission_callback'=>static fn()=>current_user_can('manage_options'),
                'callback'=>[__CLASS__,'status']]);
        },30);
    }
}
MAD4B_SCP_Company_Release_Status::boot();
