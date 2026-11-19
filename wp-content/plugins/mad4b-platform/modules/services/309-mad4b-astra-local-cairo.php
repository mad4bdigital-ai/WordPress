<?php
/**
 * Plugin Name: MAD4B Local Cairo — Astra Staging
 * Description: Reuse Elementor's available Cairo WOFF2 CSS locally for Astra; no typography replacement.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Astra_Local_Cairo_V1 {
    const OPTION='mad4b_astra_local_cairo_v1';
    private static function bound(){
        if(wp_get_environment_type()!=='staging'||wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return false;
        $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
        return is_array($profile)&&($profile['site_uuid']??'')==='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    }
    private static function local_file(){
        return WP_CONTENT_DIR.'/uploads/elementor/google-fonts/css/cairo.css';
    }
    private static function verified(){
        $css=self::local_file();
        if(!is_file($css)||filesize($css)<1000)return false;
        $text=file_get_contents($css);
        if(!str_contains($text,"font-family: 'Cairo'")||!str_contains($text,'font-display: swap')||
            !str_contains($text,'woff2')||str_contains($text,'fonts.gstatic.com'))return false;
        return is_file(WP_CONTENT_DIR.'/uploads/elementor/google-fonts/fonts/cairo-0d2badd4.woff2')
            && is_file(WP_CONTENT_DIR.'/uploads/elementor/google-fonts/fonts/cairo-eb4f7cda.woff2');
    }
    public static function url($src,$handle){
        if($handle!=='astra-google-fonts'||!self::bound())return $src;
        $policy=get_option(self::OPTION,[]);
        if(empty($policy['enabled'])||!self::verified()||strpos((string)$src,'fonts.googleapis.com')===false||
           stripos((string)$src,'Cairo')===false)return $src;
        return 'https://tours.dev.mad4b.com/wp-content/uploads/elementor/google-fonts/css/cairo.css';
    }
    public static function boot(){
        if(self::bound())add_filter('style_loader_src',[__CLASS__,'url'],999,2);
    }
}
add_action('plugins_loaded',['MAD4B_Astra_Local_Cairo_V1','boot'],70);
