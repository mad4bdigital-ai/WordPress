<?php
/**
 * Plugin Name: MAD4B Tenant Presentation Adapter Loader
 * Description: Site-identity guarded activation of the already-present native pricing presentation adapter.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Presentation_Adapter_Loader_V1 {
    public static function eligible(): bool {
        foreach (['MAD4B_TENANT_COMPANY_CODE','MAD4B_TENANT_SITE_UUID',
                  'MAD4B_TENANT_ORIGIN','MAD4B_TENANT_ENVIRONMENT'] as $key)
            if (!defined($key)) return false;
        $code=(string)constant('MAD4B_TENANT_COMPANY_CODE');
        $uuid=(string)constant('MAD4B_TENANT_SITE_UUID');
        $origin=rtrim((string)constant('MAD4B_TENANT_ORIGIN'),'/');
        $environment=(string)constant('MAD4B_TENANT_ENVIRONMENT');
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code)
            || !wp_is_uuid($uuid)
            || !in_array($environment,['staging','production'],true)
            || wp_get_environment_type()!==$environment
            || wp_parse_url($origin,PHP_URL_SCHEME)!=='https'
            || $origin!==rtrim((string)home_url('/'),'/'))return false;
        // A configured site-owned identity must match exactly. The tenant
        // wp-config hint alone cannot authorize loading a cross-site module.
        return class_exists('MAD4B_SCP_Site_Profile',false)
            && MAD4B_SCP_Site_Profile::configured()
            && MAD4B_SCP_Site_Profile::site_uuid()===$uuid;
    }
    public static function load(): void {
        if (!self::eligible())return;
        $path=__DIR__.'/shared-product-core/adapters/presentation-context-v1.php';
        if (!is_file($path)||!is_readable($path))return;
        require_once $path;
    }
    public static function boot():void {
        // Identity provider is available after regular plugin bootstrap, while
        // JetEngine options and Elementor templates are registered on init.
        add_action('plugins_loaded',[__CLASS__,'load'],5);
    }
}
MAD4B_Tenant_Presentation_Adapter_Loader_V1::boot();
