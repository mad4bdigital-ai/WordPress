<?php
/**
 * Plugin Name: MAD4B First Visible City Card LCP Preload (Staging)
 * Description: Preload the first actual JetEngine city-card background from the emitted HTML before CSS backgrounds are parsed.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_City_First_Card_LCP_V1 {
    const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    const GRID='jet-listing-grid--45927';
    const CARD='95d1c9c';
    private static function bound(){
        if(wp_get_environment_type()!=='staging'||wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return false;
        $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
        return is_array($p)&&($p['site_uuid']??'')===self::UUID;
    }
    public static function should_buffer(){
        if(!self::bound()||is_admin()||is_feed()||is_preview()||!is_front_page()||wp_doing_ajax())return false;
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
        if($path!=='/')return false;
        if(defined('ICL_SITEPRESS_VERSION')&&apply_filters('wpml_current_language',null)!=='en')return false;
        return !empty(get_option('mad4b_city_lcp_preload_v1',[])['enabled']);
    }
    public static function first_visible_background($html){
        if(!is_string($html)||strlen($html)>8*1024*1024)return null;
        // The listing wrapper itself is unique; ignore early CSS selector references.
        if(!preg_match('~<div\b[^>]*class=["\'][^"\']*\bjet-listing-grid__items\b[^"\']*\bjet-listing-grid--45927\b[^>]*>~i',$html,$grid,PREG_OFFSET_CAPTURE))return null;
        $offset=$grid[0][1]+strlen($grid[0][0]);
        $following=substr($html,$offset,52000);
        if(!preg_match('~<div\b(?=[^>]*\bdata-id=["\']95d1c9c["\'])[^>]*>~i',$following,$card))return null;
        $tag=html_entity_decode($card[0],ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(!preg_match('~background-image\s*:\s*url\(\s*["\']?(https://[^)"\'\s]+)~i',$tag,$match))return null;
        $url=html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        $host=(string)wp_parse_url($url,PHP_URL_HOST);
        $path=(string)wp_parse_url($url,PHP_URL_PATH);
        if($host!=='tours.dev.mad4b.com'||!str_starts_with($path,'/wp-content/uploads/')||
           !preg_match('~\.(?:jpe?g|png|webp|avif)$~i',$path))return null;
        // LiteSpeed rewrites the displayed card's JPEG to its .jpg.webp sibling.
        // Preload the *same* bytes instead of creating an unused duplicate JPEG request.
        $candidate=WP_CONTENT_DIR.substr($path,strlen('/wp-content')).'.webp';
        if(preg_match('~\.(?:jpe?g|png)$~i',$path)){
            if(is_file($candidate)&&filesize($candidate)>1000)return $url.'.webp';
            // No known same-resource optimized sibling: avoid an unused preload.
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
