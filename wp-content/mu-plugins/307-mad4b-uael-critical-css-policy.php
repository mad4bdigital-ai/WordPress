<?php
/**
 * Plugin Name: MAD4B UAEL Critical CSS Eligibility — Staging
 * Description: UAEL remains active. Load its shared stylesheet non-blocking on the homepage only, retain ordinary blocking CSS elsewhere.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_UAEL_Critical_CSS_Policy_V1 {
    const OPTION='mad4b_uael_css_eligibility_v1';
    const POLICY='mad4b.uael-critical-css.v1';
    private static function bound() {
        if(wp_get_environment_type()!=='staging' || wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')
            return false;
        $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
        return is_array($p) && ($p['site_uuid']??'')==='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    }
    private static function translated_front_page(){
        if(is_front_page())return true;
        // WPML may not set the default WordPress front-page conditional on
        // translated landing routes; accept ONLY exact language roots.
        $request=(string)($_SERVER['REQUEST_URI']??'');
        $path=(string)wp_parse_url($request,PHP_URL_PATH);
        if(!preg_match('~^/(ar|de|es|it|fr)/?$~D',$path,$m))return false;
        if(!defined('ICL_SITEPRESS_VERSION'))return false;
        $active=apply_filters('wpml_active_languages',null,'skip_missing=0');
        return is_array($active)&&isset($active[$m[1]]);
    }
    private static function mobile_tuned_product() {
        // Resolve translations by their canonical WPML product identity. A locale's
        // product slug is not guaranteed to match the English source slug.
        if(function_exists('is_singular') && is_singular('tours-and-activities')){
            $target=(int)get_queried_object_id();
            $english=apply_filters('wpml_object_id',$target,'tours-and-activities',true,'en');
            if((int)$english===60629)return true;
        }
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        // WPML language prefix is a routing dimension, not a separate CSS design.
        if(preg_match('~^/(ar|fr|de|es|it)(/.*)$~D',$path,$m)){
            $active=apply_filters('wpml_active_languages',null,'skip_missing=0');
            if(is_array($active)&&isset($active[$m[1]]))$path=$m[2];
        }
        $setting=(array)get_option(self::OPTION,[]);
        return in_array($path,(array)($setting['mobile_tuned_routes']??[]),true)
            && in_array($path,['/tours-and-activities/',
                '/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/'],true);
    }
    public static function eligible() {
        if(!self::bound() || is_admin() || is_customize_preview()
            || !(self::translated_front_page() || self::mobile_tuned_product()))return false;
        if(defined('ELEMENTOR_VERSION') && class_exists('Elementor\Plugin')){
            try{if(Elementor\Plugin::$instance->editor->is_edit_mode() ||
                Elementor\Plugin::$instance->preview->is_preview_mode())return false;}
            catch(\Throwable $e){return false;}
        }
        $setting=get_option(self::OPTION,[]);
        return is_array($setting) && ($setting['mode']??'')==='nonblocking_home_only'
            && ($setting['site_uuid']??'')==='e5e64bca-ed62-48d2-b2b3-dff693cd702a'
            && ($setting['origin']??'')==='https://tours.dev.mad4b.com'
            && !empty($setting['enabled']);
    }
    public static function stylesheet($html,$handle,$href,$media){
        if($handle!=='uael-frontend')return $html;
        if(!self::eligible())return $html;
        // Default WordPress prints <link rel='stylesheet' id='uael-frontend-css' ... media='all'/>.
        // Keep both the URL and the stylesheet. Only delay its render-blocking status.
        // The fallback handles browsers with scripting disabled.
        $replacement=preg_replace('/\smedia=[\'\"][^\'\"]*[\'\"]/i','',$html);
        if(!is_string($replacement)||!str_contains($replacement,'rel='))return $html;
        $mobile_media=self::mobile_tuned_product() ? 'screen and (min-width: 769px)' : 'print';
        $changed=preg_replace('/(<link\b)([^>]*?)(\s*\/?>)/i',
            '$1$2 media="'. $mobile_media .'" onload="this.onload=null;this.media=\'all\'"$3',
            $replacement,1,$count);
        if($count!==1||!is_string($changed))return $html;
        // A deliberately conservative CSS fallback, NOT an unloaded dependency.
        $fallback='<noscript><link rel="stylesheet" id="uael-frontend-css-fallback" href="'.
            esc_url($href).'" media="all" /></noscript>';
        return $changed.$fallback;
    }
    public static function cli($args,$assoc){
        $action=$args[0]??'status';
        if($action==='status'){
            WP_CLI::line(wp_json_encode(['contract'=>self::POLICY,'bound'=>self::bound(),
                'option'=>get_option(self::OPTION,[])],JSON_PRETTY_PRINT));
            return;
        }
        if($action!=='enable'||($assoc['confirm']??'')!=='UAEL HOME NONBLOCKING ONLY' || !self::bound())
            WP_CLI::error('Only enable --confirm="UAEL HOME NONBLOCKING ONLY" supported for this bound Staging site');
        $p=['contract'=>self::POLICY,'mode'=>'nonblocking_home_only','enabled'=>true,
            'origin'=>rtrim(home_url(),'/'),'site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
            'uael_not_deactivated'=>true,'other_pages_unchanged'=>true,'configured_at'=>gmdate('c')];
        update_option(self::OPTION,$p,false);
        if(get_option(self::OPTION,[])!==$p)WP_CLI::error('Policy readback failed');
        WP_CLI::success('Staging homepage non-blocking UAEL CSS mode stored');
    }
    public static function register(){
        if(!self::bound())return;
        add_filter('style_loader_tag',[__CLASS__,'stylesheet'],500,4);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))WP_CLI::add_command('mad4b perf uael',[__CLASS__,'cli']);
    }
}
add_action('plugins_loaded',['MAD4B_UAEL_Critical_CSS_Policy_V1','register'],65);
