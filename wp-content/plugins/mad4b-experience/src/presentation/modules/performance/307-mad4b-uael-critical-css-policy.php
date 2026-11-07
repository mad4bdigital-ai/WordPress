<?php
/**
 * Plugin Name: MAD4B UAEL Critical CSS Eligibility
 * Description: UAEL remains active. Load its shared stylesheet non-blocking on the homepage only, retain ordinary blocking CSS elsewhere.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_UAEL_Critical_CSS_Policy_V1 {
    const OPTION='mad4b_uael_css_eligibility_v1';
    const POLICY='mad4b.uael-critical-css.v1';
    private static function bound() {
        return MAD4B_Presentation_Experience::enabled('uael-critical-css');
    }
    private static function translated_front_page(): bool {
        if (is_front_page()) return true;
        if (!defined('ICL_SITEPRESS_VERSION')) return false;
        // The site's language roots come from WPML, not a fixed locale list.
        $current=(string)apply_filters('wpml_current_language',null);
        $active=apply_filters('wpml_active_languages',null,'skip_missing=0');
        if ($current==='' || !is_array($active) || !isset($active[$current])) return false;
        $base=rtrim((string)wp_parse_url((string)get_option('home',''),PHP_URL_PATH),'/');
        $request=rtrim((string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH),'/');
        return $request===($base.'/'.$current);
    }
    private static function mobile_breakpoint(): int {
        $setting=get_option(self::OPTION,[]);
        $configured=is_array($setting) ? (int)($setting['mobile_breakpoint_px']??0) : 0;
        if ($configured>0) return $configured;
        // Read the active Elementor site kit, preserving each company's design system.
        $kit=(int)get_option('elementor_active_kit',0);
        $meta=$kit>0 ? get_post_meta($kit,'_elementor_page_settings',true) : [];
        return is_array($meta) ? (int)($meta['viewport_mobile']??0) : 0;
    }
    private static function mobile_tuned_product(): bool {
        $setting=get_option(self::OPTION,[]);
        if (!is_array($setting)) return false;
        $configured=(int)($setting['mobile_tuned_post_id']??$setting['mobile_tuned_tour_id']??0);
        $target=(int)get_queried_object_id();
        if ($configured>0 && $target>0) {
            $postType=(string)get_post_type($target);
            if ($postType!=='') {
                $defaultLanguage=(string)apply_filters('wpml_default_language',null);
                $source=$defaultLanguage!=='' ? (int)apply_filters(
                    'wpml_object_id',$target,$postType,true,$defaultLanguage
                ) : $target;
                if ($source===$configured) return true;
            }
        }
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        $active=apply_filters('wpml_active_languages',null,'skip_missing=0');
        if (is_array($active)) {
            $base=rtrim((string)wp_parse_url((string)get_option('home',''),PHP_URL_PATH),'/');
            foreach (array_keys($active) as $lang) {
                $prefix=$base.'/'.(string)$lang;
                if (str_starts_with($path,$prefix.'/')) {
                    $path=$base.substr($path,strlen($prefix));
                    break;
                }
            }
        }
        $routes=$setting['mobile_tuned_routes']??[];
        return is_array($routes)&&count($routes)<=30
            && in_array($path,$routes,true)
            && str_starts_with($path,'/') && !str_contains($path,'..');
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
            && wp_is_uuid((string)($setting['site_uuid']??''))
            && (($setting['site_uuid']??'')===(string)MAD4B_Presentation_Experience::site_uuid())
            && rtrim((string)($setting['origin']??''),'/')===rtrim((string)get_option('home'),'/')
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
        $isMobileTuned=self::mobile_tuned_product();
        $breakpoint=$isMobileTuned ? self::mobile_breakpoint() : 0;
        // No guessed breakpoint: decline this optimization if the site kit is incomplete.
        if ($isMobileTuned && ($breakpoint<240 || $breakpoint>2000)) return $html;
        $mobile_media=$isMobileTuned ? 'screen and (min-width: '.($breakpoint+1).'px)' : 'print';
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
            WP_CLI::error('Only exact-site enable --confirm="UAEL HOME NONBLOCKING ONLY" supported');
        $uuid=MAD4B_Presentation_Experience::site_uuid();
        if(!wp_is_uuid($uuid))WP_CLI::error('Current site identity is not verified');
        $p=['contract'=>self::POLICY,'mode'=>'nonblocking_home_only','enabled'=>true,
            'origin'=>rtrim((string)get_option('home'),'/'),'site_uuid'=>$uuid,
            'uael_not_deactivated'=>true,'other_pages_unchanged'=>true,'configured_at'=>gmdate('c')];
        update_option(self::OPTION,$p,false);
        if(get_option(self::OPTION,[])!==$p)WP_CLI::error('Policy readback failed');
        WP_CLI::success('Current-site homepage non-blocking UAEL CSS mode stored');
    }
    public static function register(){
        if(!self::bound())return;
        add_filter('style_loader_tag',[__CLASS__,'stylesheet'],500,4);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))WP_CLI::add_command('mad4b perf uael',[__CLASS__,'cli']);
    }
}
add_action('plugins_loaded',['MAD4B_UAEL_Critical_CSS_Policy_V1','register'],65);
