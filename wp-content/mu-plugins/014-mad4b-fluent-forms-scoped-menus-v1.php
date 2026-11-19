<?php
/**
 * Plugin Name: MAD4B Fluent Forms Scoped Navigation
 * Description: Align the three Fluent Forms page gates with its existing per-form ACL without global settings rights.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Fluent_Forms_Scoped_Menus_V1 {
    public const CONTRACT='mad4b.fluent-forms.native-menu-scoped-caps.v1';
    private const ROUTES=[
        'fluent_forms#add=1'=>'fluentform_forms_manager',
        'fluent_forms_all_entries'=>'fluentform_entries_viewer',
        'fluent_forms_reports'=>'fluentform_entries_viewer',
    ];
    public static function reconcile(): void {
        global $submenu, $_wp_submenu_nopriv;
        if (!class_exists('FluentForm\App\Modules\Acl\Acl',false)
            || !class_exists('MAD4B_Admin_Surface_Registry_V1',false)
            || empty($submenu['fluent_forms']) || !is_array($submenu['fluent_forms'])) return;
        foreach($submenu['fluent_forms'] as &$item) {
            $slug=(string)($item[2]??'');
            if(!isset(self::ROUTES[$slug]))continue;
            $native=self::ROUTES[$slug];
            // Only correct the mistaken fallback of settings_manager to an
            // existing per-form native permission. Do not grant a WP role cap.
            if(($item[1]??'')!=='fluentform_settings_manager'
                || !current_user_can($native)
                || !current_user_can('fluentform_dashboard_access')
                || !MAD4B_Admin_Surface_Registry_V1::allowed($slug,'read'))continue;
            $item[1]=$native;
            // WordPress stored a prior access rejection when the native
            // third-party menu was originally registered with settings cap.
            // Clear it for exactly this route, this request, this actor.
            if(isset($_wp_submenu_nopriv['fluent_forms'][$slug]))
                unset($_wp_submenu_nopriv['fluent_forms'][$slug]);
        }
        unset($item);
    }
    public static function boot():void {
        add_action('admin_menu',[__CLASS__,'reconcile'],1500);
    }
}
MAD4B_Fluent_Forms_Scoped_Menus_V1::boot();
