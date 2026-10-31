<?php
/**
 * Plugin Name: MAD4B Public Catalog First Render & Safe Discovery (Staging)
 * Version: 0.1.0
 * Description: Restore native Elementor listing CSS on initial archive paint, publish ARD discovery, and scope public cache eligibility.
 */
defined('ABSPATH') || exit;
final class MAD4B_Public_Catalog_First_Render_V1 {
    private const SITE_UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    private static function bound():bool {
        if(wp_get_environment_type()!=='staging' || wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return false;
        $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
        return is_array($p) && ($p['site_uuid']??'')===self::SITE_UUID;
    }
    private static function is_archive():bool {return is_post_type_archive('tours-and-activities');}
    public static function archive_css():void {
        if(!self::bound() || is_admin() || !self::is_archive() || wp_doing_ajax())return;
        // Elementor's saved native, scoped CSS was missing on initial server HTML.
        // JetEngine adds it after switching layout, causing large shifts on first paint.
        $css='';
        $locale=defined('ICL_SITEPRESS_VERSION') ? (string)apply_filters('wpml_current_language',null) : 'en';
        if(!in_array($locale,['en','ar','fr','de','es','it'],true))return;
        foreach([25102,33747] as $base){
            $id=defined('ICL_SITEPRESS_VERSION')?(int)apply_filters('wpml_object_id',$base,'jet-engine',false,$locale):$base;
            if($id<1)return;
            $meta=get_post_meta($id,'_elementor_css',true);
            // A stale Elementor CSS snapshot can omit nested layout controls.
            // Regenerate only when a required control is missing, with a
            // 5-minute failure throttle. The update is source-derived.
            // Every translated List/Grid template now uses the source design's
            // native Elementor element topology. Localized text/dynamic tags
            // remain owned by their locale-specific post metadata.
            $required=$base===25102?'elementor-element-82a8340':'elementor-element-7d5f640';
            $modified=(int)get_post_modified_time('U',true,$id);
            $snapshot=is_array($meta)?(int)($meta['time']??0):0;
            if(!is_array($meta)||!str_contains((string)($meta['css']??''),$required)||$modified>$snapshot){
                $lock='mad4b_catalog_css_repair_'.$id;
                if(!get_transient($lock)&&class_exists(\Elementor\Core\Files\CSS\Post::class)){
                    set_transient($lock,1,5*MINUTE_IN_SECONDS);
                    try {
                        $class=\Elementor\Core\Files\CSS\Post::class;
                        (new $class($id))->update();
                        $meta=get_post_meta($id,'_elementor_css',true);
                        if(is_array($meta)&&str_contains((string)($meta['css']??''),$required))delete_transient($lock);
                    } catch (\Throwable $error) {
                        error_log('[MAD4B] Elementor listing CSS repair incomplete for template '.$id);
                    }
                }
            }
            if(!is_array($meta)||($meta['status']??'')!=='inline')continue;
            $sheet=$meta['css']??'';
            if(!is_string($sheet)||strlen($sheet)<1000||strlen($sheet)>100000 ||
                !str_contains($sheet,'.elementor-'.$id.' ') || preg_match('~</style|<script|@import~i',$sheet))continue;
            $css.="
".$sheet;
        }
        if(strlen($css)<2000)return;
        // A fixed source is used, not JS-applied widths or layout-change events.
        wp_register_style('mad4b-catalog-listing-native-first-paint',false,[], '20261101-2');
        wp_enqueue_style('mad4b-catalog-listing-native-first-paint');
        wp_add_inline_style('mad4b-catalog-listing-native-first-paint',$css);
    }
    public static function discover_ard():void {
        if(!self::bound()||is_admin()||is_feed())return;
        echo '<link rel="ard" type="application/ard+json" href="'.esc_url('https://tours.dev.mad4b.com/.well-known/ard.json').'" />' . "
";
    }
    public static function chosen_script():void {
        if(!self::bound()||is_admin()||!is_front_page())return;
        $f=__DIR__.'/assets/mad4b-chosen-crawlable.js';
        wp_enqueue_script('mad4b-chosen-crawlable',content_url('/mu-plugins/assets/mad4b-chosen-crawlable.js'),[],is_file($f)?(string)filemtime($f):'20261101',true);
    }
    public static function public_home_cache():void {
        if(!self::bound()||(!is_front_page()&&!self::is_archive())||is_admin()||is_preview()||wp_doing_ajax()||is_user_logged_in())return;
        // Only anonymous, unvaried default-currency locale homepages may receive microcache.
        $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
        $allowed=['/'=>'en','/ar/'=>'ar','/fr/'=>'fr','/de/'=>'de','/it/'=>'it','/es/'=>'es'];
        foreach(['en','ar','fr','de','it','es'] as $language){
            $prefix=$language==='en'?'':'/'.$language;
            $allowed[$prefix.'/tours-and-activities/']=$language;
        }
        if(!isset($allowed[$path]) || !in_array($_SERVER['REQUEST_METHOD']??'', ['GET','HEAD'],true))return;
        if(defined('ICL_SITEPRESS_VERSION') && apply_filters('wpml_current_language',null)!==$allowed[$path])return;
        if(!empty($_SERVER['QUERY_STRING'])||!empty($_SERVER['HTTP_COOKIE'])||!empty($_SERVER['HTTP_AUTHORIZATION'])||!empty($_SERVER['PHP_AUTH_USER']))return;
        if(!empty($_SERVER['HTTP_X_WP_NONCE'])||!empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])||!empty($_SERVER['HTTP_X_GEO_COUNTRY']))return;
        if(get_option('woocommerce_default_customer_address')!=='base'||get_option('woocommerce_currency')!=='USD')return;
        if(!empty($_SERVER['HTTP_CF_IPCOUNTRY']) && $_SERVER['HTTP_CF_IPCOUNTRY']!=='XX')return;
        if(!empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY']))return;
        if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']))return;
        if(!empty($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']))return;
        if(function_exists('get_woocommerce_currency') && get_woocommerce_currency()!=='USD')return;
        header('X-MAD4B-Cache-Eligibility: public-static-v1');
        header('Cache-Control: public, max-age='.(self::is_archive()?15:30));
    }
}
add_action('wp_enqueue_scripts',['MAD4B_Public_Catalog_First_Render_V1','archive_css'],99);
add_action('wp_enqueue_scripts',['MAD4B_Public_Catalog_First_Render_V1','chosen_script'],100);
add_action('wp_head',['MAD4B_Public_Catalog_First_Render_V1','discover_ard'],1);
add_action('template_redirect',['MAD4B_Public_Catalog_First_Render_V1','public_home_cache'],0);
