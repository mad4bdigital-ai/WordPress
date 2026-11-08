<?php
/**
 * Plugin Name: MAD4B Policy Registry Admin Bridge
 * Description: Exposes the existing versioned Policy Registry editor when the source Adaptive Controls page is not installed.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Policy_Registry_Admin_Bridge_V1',false)) {
final class MAD4B_Policy_Registry_Admin_Bridge_V1 {
    public static function boot(): void {
        add_action('admin_menu',[self::class,'register'],95);
    }
    public static function register(): void {
        // The original adaptive admin owns the page when it exists.
        if (!class_exists('MAD4B_Company_Policy_Registry_V2',false) ||
            class_exists('MAD4B_Company_Adaptive_Controls',false)) return;
        add_options_page('MAD4B Dynamic Policy Registry v2',
            'MAD4B Dynamic Policy Registry','manage_options',
            'mad4b-company-adaptive-controls',[self::class,'render']);
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden','Forbidden',['response'=>403]);
        }
        if (!class_exists('MAD4B_Company_Policy_Registry_V2',false)) {
            wp_die('Policy Registry unavailable','Unavailable',['response'=>503]);
        }
        echo '<div class="wrap"><h1>MAD4B Dynamic Policy Registry v2</h1>';
        echo '<p>Company-scoped and contextual rules. Only active rules influence runtime; current existing company defaults remain authoritative until changed.</p>';
        MAD4B_Company_Policy_Registry_V2::admin_page_section();
        echo '</div>';
    }
}
MAD4B_Policy_Registry_Admin_Bridge_V1::boot();
}
