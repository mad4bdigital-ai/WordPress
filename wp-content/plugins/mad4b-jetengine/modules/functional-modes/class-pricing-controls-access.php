<?php
/**
 * MAD4B native pricing options-page scoped capability/route adapter.
 *
 * Changes ONLY the runtime JetEngine Options Page Factory for the existing
 * etg-dynamic-pricing-controls page, retaining all native options, metadata,
 * save handlers, nonces, fields and aliases. It does not grant or map WordPress
 * manage_options, and changes no company or rate configuration.
 */
defined('ABSPATH') || exit;
final class MAD4B_JetEngine_Pricing_Controls_Access_V1 {
    public const SOURCE_SLUG='etg-dynamic-pricing-controls';
    public const PUBLIC_ALIAS='dynamic-pricing-controls';
    public const CAPABILITY='commerce_manage_pricing';

    public static function authorized(): bool {
        return current_user_can(self::CAPABILITY) &&
            current_user_can('manage_woocommerce');
    }
    public static function boot(): void {
        if (!is_admin()) return;
        // JetEngine registers the actual page on admin_menu:99. Set its
        // scoped capability & company parent before its own callback runs.
        add_action('admin_menu',[__CLASS__,'bind_native'],85);
        add_action('admin_init',[__CLASS__,'redirect_legacy_settings_url'],1);
    }
    private static function company_parent(): string {
        // Single registered company pricing workspace. No Settings-level
        // permission or WooCommerce-specific navigation is required.
        return class_exists('MAD4B_Pricing_Workspace_V1')
            && MAD4B_Pricing_Workspace_V1::permitted()
              ? MAD4B_Pricing_Workspace_V1::SLUG
              : 'woocommerce';
    }
    public static function bind_native(): void {
        if(!self::authorized())return;
        if(!function_exists('jet_engine') || !isset(jet_engine()->options_pages))return;
        $pages=jet_engine()->options_pages->registered_pages ?? [];
        $native=$pages[self::SOURCE_SLUG]??null;
        if(!($native instanceof Jet_Engine_Options_Page_Factory)
            || $native->slug!==self::SOURCE_SLUG
            || !isset($native->page['fields'])
            || !is_array($native->page['fields']))return;
        // Scoped capability is honored by JetEngine for both page access
        // and native Save. No generic user_has_cap or manage_options grants.
        $native->page['capability']=self::CAPABILITY;
        $native->page['parent']=self::company_parent();
    }
    public static function canonical_url(): string {
        return admin_url('admin.php?page='.self::PUBLIC_ALIAS);
    }
    public static function legacy_redirect_target(): ?string {
        $page=sanitize_key((string)($_GET['page']??''));
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        $script=basename((string)($_SERVER['SCRIPT_NAME']??''));
        if(!self::authorized()||$method!=='GET'
            ||$page!==self::PUBLIC_ALIAS
            ||$script!=='options-general.php')return null;
        return self::canonical_url();
    }
    public static function redirect_legacy_settings_url(): void {
        $to=self::legacy_redirect_target();
        if(!$to)return;
        wp_safe_redirect($to,302,'MAD4B Pricing Workspace');
        exit;
    }
}
