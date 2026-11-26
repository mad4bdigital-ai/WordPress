<?php
/**
 * Presentation adapter for existing source pricing panels in the native
 * MAD4B JetEngine Dynamic Pricing workspace. Keeps all AJAX, security,
 * JetEngine native form, and standalone pricing policies unchanged.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/pricing-admin-context.php';
final class MAD4B_Pricing_Workspace_Unified_V1 {
    public static function assets():void {
        if(!is_admin() || !class_exists('MAD4B_Transport_Self_Pricing_Controls_V1') ||
           !MAD4B_Transport_Self_Pricing_Controls_V1::permitted())return;
        $page=sanitize_key((string)($_GET['page']??''));
        if(!MAD4B_Pricing_Admin_Context_V1::matches($page))return;
        $base=dirname(__DIR__);
        $files=['pricing-workspace-unified.js',
            'pricing-workspace-unified.css'];
        foreach($files as $file)
            if(!is_file($base.'/assets/'.$file))return;
        $main=$base.'/mad4b-pricing-registry.php';
        $deps=[];
        if(wp_script_is('mad4b-jetengine-functional-modes','registered'))
            $deps[]='mad4b-jetengine-functional-modes';
        if(wp_script_is('mad4b-transport-self-controls','registered'))
            $deps[]='mad4b-transport-self-controls';
        wp_enqueue_script('mad4b-pricing-workspace-unified',
            set_url_scheme(plugins_url('assets/'.$files[0],$main),'https'),
            $deps,(string)filemtime($base.'/assets/'.$files[0]),true);
        wp_add_inline_script('mad4b-pricing-workspace-unified',
            'window.MAD4BPricingWorkspaceUnified='.
            wp_json_encode([
              'version'=>'1.0.0','page'=>$page,
              'editorial_only'=>true,'native_controls_preserved'=>true,
              'transport_checkout_enabled'=>false,
            ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';',
            'before');
        wp_enqueue_style('mad4b-pricing-workspace-unified',
            set_url_scheme(plugins_url('assets/'.$files[1],$main),'https'),
            [],(string)filemtime($base.'/assets/'.$files[1]));
    }
    public static function boot():void {
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],110);
    }
}
MAD4B_Pricing_Workspace_Unified_V1::boot();
