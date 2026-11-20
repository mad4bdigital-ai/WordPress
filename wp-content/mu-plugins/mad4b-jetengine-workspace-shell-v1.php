<?php
/**
 * Plugin Name: MAD4B JetEngine Unified Workspace Shell
 * Version: 1.0.0
 * Description: Presentation-only single workspace surface for native JetEngine Options Pages and service pricing modules.
 */
defined('ABSPATH') || exit;

final class MAD4B_JetEngine_Workspace_Shell_V1 {
    public const SCRIPT='mad4b-jetengine-workspace-shell-v1';
    public static function enqueue():void {
        if(!is_admin() || !class_exists('MAD4B_JetEngine_Field_Modes_V1',false))return;
        $slug=sanitize_key((string)($_GET['page']??''));
        if($slug==='dynamic-pricing-controls')$slug='etg-dynamic-pricing-controls';
        if($slug==='' || !isset(MAD4B_JetEngine_Field_Modes_V1::pages()[$slug]))return;
        if($slug==='etg-dynamic-pricing-controls'){
            if(!class_exists('MAD4B_JetEngine_Pricing_Controls_Access_V1',false) ||
                !MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized())return;
        }elseif(!current_user_can('manage_options'))return;
        $base=WPMU_PLUGIN_DIR.'/assets/';
        $css=$base.'mad4b-jetengine-workspace-shell-v1.css';
        $js=$base.'mad4b-jetengine-workspace-shell-v1.js';
        if(!is_readable($css) || !is_readable($js) ||
           !wp_style_is('mad4b-jetengine-functional-modes','registered') ||
           !wp_script_is('mad4b-jetengine-functional-modes','registered'))return;
        wp_enqueue_style(self::SCRIPT,content_url('mu-plugins/assets/'.basename($css)),
            ['mad4b-jetengine-functional-modes'],(string)filemtime($css));
        // One shared visual system for all comparable Pricing Controls
        // components (Stay, Transport, Guide, Policy and Cost Inspector).
        // It deliberately runs after the original JetEngine shell and
        // original service styles, without replacing their controllers.
        if($slug==='etg-dynamic-pricing-controls'){
            $system=$base.'mad4b-pricing-interface-v2.css';
            if(is_readable($system))
                wp_enqueue_style('mad4b-pricing-interface-v2',
                    content_url('mu-plugins/assets/'.basename($system)),
                    [self::SCRIPT],(string)filemtime($system));
        }
        // Append our overrides after every registered service styling layer,
        // without touching the original vendor or composed panel files.
        wp_enqueue_script(self::SCRIPT,content_url('mu-plugins/assets/'.basename($js)),
            ['mad4b-jetengine-functional-modes'],(string)filemtime($js),true);
        wp_add_inline_script(self::SCRIPT,'window.MAD4BWorkspaceShell='.
            wp_json_encode(['pricing'=>$slug==='etg-dynamic-pricing-controls',
                'version'=>'1.0.0']).';','before');
    }
}
add_action('admin_enqueue_scripts',[MAD4B_JetEngine_Workspace_Shell_V1::class,'enqueue'],999);
