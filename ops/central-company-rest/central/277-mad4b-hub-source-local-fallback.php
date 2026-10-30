<?php
/**
 * Plugin Name: MAD4B Hub Source Local Fallback
 * Description: Staging-only, root-VPS WP-CLI fallback for an unbound external Control MCP client.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

/**
 * A fallback is a transport decision, never a new grant. The caller must be
 * an enrolled WordPress administrator in CLI mode, initiated by the root-owned
 * VPS runner. An authenticated external MCP denial must NOT trigger fallback.
 */
final class MAD4B_Hub_Source_Local_Fallback_V1 {
    const CONTRACT='mad4b.hub.source-fallback.v1';
    const AUDIT='mad4b_hub_source_fallback_decisions_v1';
    const ENABLED='mad4b_hub_source_local_fallback_enabled_v1';
    const REASON='external_client_unbound';

    private static function site_ready(): bool {
        if(!class_exists('MAD4B_Hub_Source_Sync_Jobs_V1') ||
           !class_exists('MAD4B_SCP_Site_Profile') ||
           !class_exists('MAD4B_SCP_ChatGPT_Auto_Binding') ||
           wp_get_environment_type()!=='staging' ||
           home_url('/')!=='https://tours.dev.mad4b.com/' ||
           !MAD4B_SCP_Site_Profile::origin_enrolled() ||
           !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
            return false;
        return true;
    }
    private static function enabled(): bool {
        // Default ON only for the exact enrolled Staging site. The operator can
        // disable it via the named WordPress option without changing PHP code.
        return self::site_ready() && get_option(self::ENABLED,true)===true;
    }
    private static function error($code,$status=403) {
        return new WP_Error($code,'Local fallback rejected: '.$code,['status'=>$status]);
    }
    private static function external_bound(): bool {
        $binding=MAD4B_SCP_ChatGPT_Auto_Binding::status();
        return is_array($binding) && !empty($binding['client_observed']);
    }
    public static function status($input=[]): array {
        $site=self::site_ready();
        $plan=$site?MAD4B_Hub_Source_Sync_Jobs_V1::plan():[];
        $mode=$site && self::external_bound()?'external_control_mcp':'vps_wpcli_fallback';
        $enabled=self::enabled();
        $eligible=$enabled && !self::external_bound() &&
            !empty($plan['ready']) && empty($plan['existing_active_jobs']) &&
            empty($plan['publish_enabled']) && empty($plan['auto_merge_enabled']) &&
            ($plan['transport']??'')==='wp_cli_cross_ssh' &&
            ($plan['company_id']??'')==='tourism-almallahgroup' &&
            ($plan['provider_id']??'')==='tourism_wp' &&
            ($plan['dataset_id']??'')==='tourism_main';
        return [
            'contract'=>self::CONTRACT,
            'site'=>home_url('/'),
            'policy'=>'external_first_vps_wpcli_fallback',
            'default_fallback_enabled'=>$enabled,
            'preferred_execution'=>$mode,
            'fallback_eligible'=>$eligible,
            'fallback_reason'=>$eligible?self::REASON:null,
            'eligible_source'=>['company'=>'tourism-almallahgroup',
                'provider'=>'tourism_wp','dataset'=>'tourism_main',
                'transport'=>'wp_cli_cross_ssh'],
            'requires_enrolled_administrator'=>true,
            'requires_vps_root_runner'=>true,
            'requires_explicit_host_job_invocation'=>true,
            'automatic_polling'=>false,'cron_enabled'=>false,
            'fallback_on_authorization_denial'=>false,
            'fallback_on_remote_401_or_403'=>false,
            'cross_company_allowed'=>false,'production_allowed'=>false,
            'publishing_enabled'=>false,'auto_merge_enabled'=>false,
            'active_jobs'=>$plan['existing_active_jobs']??[],
        ];
    }
    private static function host_actor_ready(): bool {
        return defined('WP_CLI') && WP_CLI &&
            defined('MAD4B_HUB_SOURCE_HOST_RUNNER') &&
            MAD4B_HUB_SOURCE_HOST_RUNNER===true &&
            current_user_can('manage_options') &&
            get_current_user_id()>0 &&
            MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id());
    }
    private static function audit(array $entry): bool {
        $old=get_option(self::AUDIT,[]);
        if(!is_array($old))return false;
        $old[]=$entry;
        $next=array_slice($old,-30);
        $stored=update_option(self::AUDIT,$next,false);
        return $stored!==false || get_option(self::AUDIT,[])===$next;
    }
    public static function request_from_host($input=[]) {
        if(!self::site_ready() || !self::host_actor_ready())
            return self::error('enrolled_vps_cli_administrator_required');
        if(!is_array($input) || count($input)!==2 ||
            !isset($input['request_key'],$input['reason']) ||
            ($input['reason']??'')!==self::REASON ||
            !is_string($input['request_key']) ||
            !preg_match('/^[A-Za-z0-9_.-]{8,80}$/D',$input['request_key']))
            return self::error('invalid_fallback_request',400);
        $decision=self::status();
        if(!$decision['default_fallback_enabled'])
            return self::error('fallback_disabled');
        if($decision['preferred_execution']!=='vps_wpcli_fallback')
            return self::error('external_mcp_preferred');
        if(!$decision['fallback_eligible'])
            return self::error('source_not_ready_or_job_active');
        $actor=get_current_user_id();
        $entry=[
            'at'=>gmdate('c'),'actor_user_id'=>$actor,'environment'=>'staging',
            'company'=>'tourism-almallahgroup','provider'=>'tourism_wp',
            'dataset'=>'tourism_main','reason'=>self::REASON,
            'request_key'=>$input['request_key'],'decision'=>'host_request_authorized',
            'no_oauth_impersonation'=>true,'no_publish'=>true,
        ];
        // Fail closed if we cannot record the decision BEFORE queueing a Job.
        if(!self::audit($entry))
            return self::error('fallback_audit_unavailable',503);
        $job=MAD4B_Hub_Source_Sync_Jobs_V1::request([
            'company_id'=>'tourism-almallahgroup','provider_id'=>'tourism_wp',
            'dataset_id'=>'tourism_main','transport'=>'wp_cli_cross_ssh',
            'request_key'=>$input['request_key'],
        ]);
        if(is_wp_error($job))return $job;
        return [
            'contract'=>self::CONTRACT,
            'path'=>'vps_wpcli_fallback',
            'reason'=>self::REASON,
            'job_id'=>$job['job_id'],'status'=>$job['status'],
            'company_id'=>$job['company_id'],
            'provider_id'=>$job['provider_id'],
            'dataset_id'=>$job['dataset_id'],
            'requires_vps_runner'=>true,'published'=>false,
        ];
    }
    public static function register_abilities(): void {
        if(!self::site_ready() || !function_exists('wp_register_ability'))return;
        $ability='mad4b/hub-source-fallback-status';
        if(function_exists('wp_has_ability')&&wp_has_ability($ability))return;
        wp_register_ability($ability,[
            'label'=>'Content Hub Default Fallback Policy',
            'description'=>'Read the scoped external-first VPS WP-CLI fallback policy and eligibility; no execution.',
            'category'=>'mad4b-read',
            'execute_callback'=>[__CLASS__,'status'],
            'permission_callback'=>static function(){return current_user_can('manage_options');},
            'input_schema'=>['type'=>'object','properties'=>[],'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>false,
                'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
        ]);
    }
    public static function boot(): void {
        if(!self::site_ready())return;
        add_action('wp_abilities_api_init',[__CLASS__,'register_abilities'],27);
    }
}
MAD4B_Hub_Source_Local_Fallback_V1::boot();
