<?php
/**
 * Plugin Name: MAD4B Company Content Hub Overview
 * Description: Read-only Company operational view; never opens Central Hub owner commands.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Content_Hub_Overview_V1 {
    public const SLUG='mad4b-company-content-hub';
    public static function can_read():bool {
        return class_exists('MAD4B_Admin_Surface_Registry_V1',false)
            && MAD4B_Admin_Surface_Registry_V1::allowed(self::SLUG,'read');
    }
    public static function menu():void {
        if(!self::can_read() || !class_exists('MAD4B_Company_Workspaces_V1',false))return;
        add_submenu_page(MAD4B_Company_Workspaces_V1::menu_parent(),
            'Content Hub — Company','Content Hub', 'mad4b_manage_company_operations',
            self::SLUG,[__CLASS__,'page']);
    }
    public static function page():void {
        if(!self::can_read())wp_die('Company content access required.');
        $installed=class_exists('MAD4B_Content_Hub',false);
        $ready=$installed && method_exists('MAD4B_Content_Hub','central_identity_ready')
            && MAD4B_Content_Hub::central_identity_ready();
        echo '<div class="wrap"><h1>Content Hub — Company overview</h1>';
        echo '<p>The Content Hub runs only on the authenticated central source. This page cannot run research, publish, change tenants, retrieve credentials, or approve native jobs.</p>';
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th>Current WordPress site</th><td>'.esc_html(home_url()).'</td></tr>';
        echo '<tr><th>Central Hub component</th><td>'.($installed?'Installed':'Not enrolled on this site').'</td></tr>';
        echo '<tr><th>Bound central authority ready</th><td>'.($ready?'Verified':'Not available').'</td></tr>';
        echo '</tbody></table><p>';
        foreach([
            'mad4b-company-content-jobs'=>'Company Content Jobs',
            'mad4b-control-plane-content-pipeline'=>'Content Pipeline',
            'mad4b-content-readiness-alerts'=>'Content Readiness',
        ] as $slug=>$label) {
            if(!class_exists('MAD4B_Admin_Surface_Registry_V1',false)
                || !MAD4B_Admin_Surface_Registry_V1::allowed($slug,'read'))continue;
            echo '<a class="button button-secondary" href="'.
                esc_url(add_query_arg('page',$slug,admin_url('admin.php'))).'">'.
                esc_html($label).'</a> ';
        }
        echo '</p></div>';
    }
    public static function boot():void {
        add_action('admin_menu',[__CLASS__,'menu'],820);
    }
}
MAD4B_Company_Content_Hub_Overview_V1::boot();
