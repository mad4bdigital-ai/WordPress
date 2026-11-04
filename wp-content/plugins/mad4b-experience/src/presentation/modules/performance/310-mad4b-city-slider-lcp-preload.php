<?php
/**
 * Plugin Name: MAD4B First Visible City Card LCP Preload (Staging)
 * Description: Preload the first actual JetEngine city-card background from the emitted HTML before CSS backgrounds are parsed.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_City_First_Card_LCP_V1 {
    private static function bound(){
        return MAD4B_Presentation_Experience::enabled('city-first-card-lcp');
    }
    public static function should_buffer(){
        if(!self::bound()||is_admin()||is_feed()||is_preview()||!is_front_page()||wp_doing_ajax())return false;
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
        if($path!=='/')return false;
        $settings=(array)get_option('mad4b_city_lcp_preload_v1',[]);
        $language=(string)($settings['reference_language']??'en');
        if(defined('ICL_SITEPRESS_VERSION')&&apply_filters('wpml_current_language',null)!==$language)return false;
        return !empty($settings['enabled'])
            && preg_match('/^jet-listing-grid--[0-9]{1,8}$/D',(string)($settings['grid_class']??''))
            && preg_match('/^[a-f0-9]{6,12}$/D',(string)($settings['card_element_id']??''));
    }
    public static function first_visible_background($html){
        if(!is_string($html)||strlen($html)>8*1024*1024)return null;
        $settings=(array)get_option('mad4b_city_lcp_preload_v1',[]);
        $grid=$settings['grid_class']??'';
        $card=$settings['card_element_id']??'';
        if(!is_string($grid)||!preg_match('/^jet-listing-grid--[0-9]{1,8}$/D',$grid)
          ||!is_string($card)||!preg_match('/^[a-f0-9]{6,12}$/D',$card))return null;
        $grid_rx='~<div\b[^>]*class=["\x27][^"\x27]*\bjet-listing-grid__items\b[^"\x27]*\b'.preg_quote($grid,'~').'\b[^>]*>~i';
        if(!preg_match($grid_rx,$html,$match,PREG_OFFSET_CAPTURE))return null;
        $offset=$match[0][1]+strlen($match[0][0]);
        $following=substr($html,$offset,52000);
        $card_rx='~<div\b(?=[^>]*\bdata-id=["\x27]'.preg_quote($card,'~').'["\x27])[^>]*>~i';
        if(!preg_match($card_rx,$following,$found))return null;
        $tag=html_entity_decode($found[0],ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(!preg_match('~background-image\s*:\s*url\(\s*["\x27]?(https://[^)"\x27\s]+)~i',$tag,$image))return null;
        $url=html_entity_decode($image[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        $host=(string)wp_parse_url($url,PHP_URL_HOST);
        $sitehost=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
        $path=(string)wp_parse_url($url,PHP_URL_PATH);
        $uploads=wp_upload_dir();
        $baseurl=(string)wp_parse_url((string)($uploads['baseurl']??''),PHP_URL_PATH);
        if($host!==$sitehost || $baseurl==='' || !str_starts_with($path,rtrim($baseurl,'/').'/')
         ||!preg_match('~\.(?:jpe?g|png|webp|avif)$~i',$path))return null;
        $relative=substr($path,strlen(rtrim($baseurl,'/')));
        if(str_contains($relative,'..')||str_contains($relative,'\\'))return null;
        // Only a real matching optimized sibling may be preloaded.
        $candidate=rtrim((string)($uploads['basedir']??''),'/').$relative.'.webp';
        if(preg_match('~\.(?:jpe?g|png)$~i',$path)){
            if(is_file($candidate)&&filesize($candidate)>1000)return $url.'.webp';
            return null;
        }
        return $url;
    }
    public static function transform($html){
        if(!is_string($html)||stripos($html,'</head>')===false)return $html;
        $url=self::first_visible_background($html);
        if(!$url)return $html;
        $type=str_ends_with(strtolower((string)wp_parse_url($url,PHP_URL_PATH)),'.webp')?' type="image/webp"':'';
        // Never output escaped newline characters in the HTML: they become
        // visible text nodes above the header and can even win initial LCP.
        $preload='<link id="mad4b-city-first-slide-lcp" rel="preload" as="image" href="'.esc_url($url).'"'.$type.' media="(max-width: 767px)" fetchpriority="high" data-no-optimize="1" />';
        return preg_replace_callback('~<head\b[^>]*>~i',static fn($m)=>$m[0].$preload,$html,1);

    }
    public static function boot(){
        if(self::should_buffer())ob_start([__CLASS__,'transform']);
    }
}
add_action('template_redirect',['MAD4B_City_First_Card_LCP_V1','boot'],1);
