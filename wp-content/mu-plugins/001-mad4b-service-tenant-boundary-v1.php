<?php
/**
 * Plugin Name: MAD4B Tenant Service Identity Boundary
 * Description: Neutral tenant/site context check for the legacy service presentation overlays.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Tenant_Service_Identity_Boundary_V1',false)){
final class MAD4B_Tenant_Service_Identity_Boundary_V1 {
    public static function eligible():bool {
        foreach(['MAD4B_TENANT_COMPANY_CODE','MAD4B_TENANT_SITE_UUID',
            'MAD4B_TENANT_ORIGIN','MAD4B_TENANT_ENVIRONMENT'] as $name)
            if(!defined($name))return false;
        $company=(string)constant('MAD4B_TENANT_COMPANY_CODE');
        $uuid=(string)constant('MAD4B_TENANT_SITE_UUID');
        $origin=rtrim((string)constant('MAD4B_TENANT_ORIGIN'),'/');
        $env=(string)constant('MAD4B_TENANT_ENVIRONMENT');
        $home=rtrim((string)get_option('home',''),'/');
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
            || !wp_is_uuid($uuid)
            || !in_array($env,['staging','production'],true)
            || wp_get_environment_type()!==$env
            || wp_parse_url($origin,PHP_URL_SCHEME)!=='https'
            || $origin!==$home)return false;
        // Honor a verified native identity when the provider is already loaded.
        if(class_exists('MAD4B_SCP_Site_Profile',false)){
            if(!MAD4B_SCP_Site_Profile::configured()
                || MAD4B_SCP_Site_Profile::site_uuid()!==$uuid)return false;
        }
        // This controls presentation only and never mints a transport/write grant.
        return true;
    }
}
}
