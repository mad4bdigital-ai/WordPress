<?php
/**
 * MAD4B strict, bound-instance, reducing-only Master Admin capability guard.
 * Independent of the Staging-only Company Portfolio management UI.
 * No owner grants, signed certificates, new roles, or company write jobs.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Role_Reduction_Guard_V1 {
    public const CONTRACT='mad4b.company-master-admin.reducing-only-role-guard.v1';
    /** Authority-reducing policy only: never grants capabilities or changes an unbound site. */
    public const DENIED_MASTER_CAPS=array(
        'manage_options','activate_plugins','install_plugins','update_plugins',
        'delete_plugins','edit_plugins','edit_theme_options','delete_users',
        'remove_users','fluentform_full_access','fluentform_settings_manager',
        'create_users','edit_users','promote_users','install_languages',
        'rank_math_role_manager','wpml_manage_languages',
        'wpml_manage_troubleshooting','manage_woocommerce'
    );
    /** Runs after ordinary WordPress role boot, for exact bound company instances only.
     * No migration version or production approval is forged. */
    public static function enforce_denied_master_caps():array {
        $environment=function_exists('wp_get_environment_type')?wp_get_environment_type():'';
        if(!in_array($environment,array('staging','production'),true))
            return array('state'=>'ENVIRONMENT_NOT_ENROLLED','removed'=>0);
        // Healthy roles should not cost another Site Profile read on each request.
        $master=get_role('master_admin');
        if(!$master)return array('state'=>'MASTER_ADMIN_ROLE_NOT_FOUND','removed'=>0);
        $pending=array();
        foreach(self::DENIED_MASTER_CAPS as $cap)
            if($master->has_cap($cap))$pending[]=$cap;
        if(!$pending)return array('state'=>'ROLE_POLICY_ALREADY_COMPLIANT',
            'removed'=>0,'capabilities_granted'=>0);
        if(!class_exists('MAD4B_SCP_Site_Profile',false)
            || !method_exists('MAD4B_SCP_Site_Profile','status'))
            return array('state'=>'SITE_PROFILE_NOT_LOADED','removed'=>0);
        $site=MAD4B_SCP_Site_Profile::status();
        if(!is_array($site)||empty($site['configured'])
           ||empty($site['deployment_binding_match'])
           ||empty($site['origin_match'])||empty($site['environment_match'])
           ||($site['environment']??'')!==$environment
           ||!function_exists('wp_is_uuid')
           ||!wp_is_uuid((string)($site['site_uuid']??''))
           ||!function_exists('home_url')
           ||($site['canonical_origin']??'')!==untrailingslashit(home_url('/')))
            return array('state'=>'EXACT_SITE_BINDING_NOT_VERIFIED','removed'=>0);
        $removed=0;
        foreach($pending as $cap){
            $master->remove_cap($cap);
            $removed++;
        }
        return array('state'=>'EXACT_SITE_ROLE_POLICY_ENFORCED',
            'removed'=>$removed,'capabilities_granted'=>0,
            'site_uuid'=>$site['site_uuid'],'environment'=>$environment);
    }
    public static function boot():void {
        add_action('init',array(__CLASS__,'enforce_denied_master_caps'),100);
    }
}
MAD4B_Company_Role_Reduction_Guard_V1::boot();
