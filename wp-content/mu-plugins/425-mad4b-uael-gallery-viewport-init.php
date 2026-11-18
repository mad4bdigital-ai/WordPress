<?php
/**
 * Plugin Name: MAD4B UAEL Native Offscreen Gallery Viewport
 * Version: 1.0.0
 * Description: Initialize offscreen UAEL image carousels with original Elementor callbacks only when they approach the viewport.
 */
defined('ABSPATH') || exit;
final class MAD4B_UAEL_Gallery_Viewport_V1 {
    private const ASSET_SHA256 = '55d22248ca970e4414742db4d66a8e6fbfe54f763e0ac44b20daa6ed2cdbe717';
    private const OPTION = 'mad4b_perf_uael_gallery_viewport_v1';
    public static function head(): void {
        $mode=(string)get_option(self::OPTION, 'off');
        $query=(string)($_SERVER['QUERY_STRING']??'');
        $probe=((string)($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'')==='1'
            && ($query==='mad4b_uael_gallery_viewport_probe=1'
                || ($query==='' && (string)($_SERVER['HTTP_X_MAD4B_UAEL_GALLERY_CANARY']??'')==='v1')));
        if (!in_array($mode,['on','canary'],true)
            || ($mode==='canary' && !$probe)
            || ($mode==='on' && $query!=='' && !$probe)
            || is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview()
            || !is_front_page() || is_feed()
            || (string)($_SERVER['REQUEST_METHOD']??'GET')!=='GET')
            return;
        // This hook runs after wp_print_head_scripts (priority 9), so the
        // existing WordPress jQuery handle is available. No separate CDN.
        if (!wp_script_is('jquery-core','done')) return;
        $file = __DIR__.'/mad4b-uael-gallery-viewport/uael-gallery.'.substr(self::ASSET_SHA256,0,12).'.js';
        if (is_link($file) || !is_readable($file) || filesize($file)<1200
            || filesize($file)>8000
            || !hash_equals(self::ASSET_SHA256,(string)hash_file('sha256',$file))) return;
        $js = file_get_contents($file);
        if (!is_string($js) || strpos(strtolower($js),'</script')!==false) return;
        echo '<script id="mad4b-uael-gallery-viewport-v1" data-no-optimize="1" data-no-defer="1" data-cfasync="false">',
            $js,'</script>', "\n";
    }
}
add_action('wp_head',[MAD4B_UAEL_Gallery_Viewport_V1::class,'head'],10);

/** Shared UAEL owner: server-verified carousel-only dependency optimization. */
final class MAD4B_UAEL_Carousel_Dependency_Gate_V1 {
    private const OPTION = 'mad4b_perf_uael_carousel_assets_v1';
    private static int $carousels=0;
    private static int $other_uael=0;
    private static bool $active=false;
    private static bool $applied=false;
    private static function eligible(): bool {
        if (self::$active) return true;
        if (is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview()
            || is_feed() || !is_front_page() ||
            (string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
        $host=(string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST);
        if (!in_array($host,['wayfatours.com','tours.dev.mad4b.com'],true))return false;
        $mode=(string)get_option(self::OPTION,'off');
        if ($mode==='on' && (string)($_SERVER['QUERY_STRING']??'')==='')return self::$active=true;
        if ($mode!=='canary')return false;
        $probe=(
           (string)($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'')==='1'
           && (string)($_SERVER['HTTP_X_MAD4B_UAEL_CAROUSEL_ASSETS']??'')==='v1'
           && in_array((string)($_SERVER['QUERY_STRING']??''),['','mad4b_uael_carousel_probe=1'],true)
        );
        if ($probe)self::$active=true;
        return $probe;
    }
    public static function inspect($widget): void {
        if (!self::eligible() || !is_object($widget) || !method_exists($widget,'get_name')) return;
        $name=(string)$widget->get_name();
        if ($name==='uael-image-gallery') {
            $settings=method_exists($widget,'get_settings_for_display') ? $widget->get_settings_for_display() : [];
            if (is_array($settings) && (string)($settings['gallery_style']??'')==='carousel')
                self::$carousels++;
            else self::$other_uael++;
            return;
        }
        if (strpos($name,'uael-')===0) self::$other_uael++;
    }
    public static function prune(): void {
        if (!self::eligible() || self::$carousels<1 || self::$other_uael!==0)return;
        if (!function_exists('wp_script_is'))return;
        // These scripts support Masonry/Justified gallery modes only.
        // Keep UAEL Slick, Fancybox, imagesLoaded and the native frontend script.
        if (!wp_script_is('uael-slick','enqueued') ||
            !wp_script_is('uael-fancybox','enqueued') ||
            !wp_script_is('uael-frontend-script','enqueued'))return;
        foreach (['uael-isotope','uael-justified'] as $handle)
            if (wp_script_is($handle,'enqueued'))wp_dequeue_script($handle);
        self::$applied=true;
    }
    public static function audit(): void {
        if (!self::eligible() || (string)get_option(self::OPTION,'off')!=='canary')return;
        echo '<meta id="mad4b-uael-carousel-assets-v1-audit" data-no-optimize="1"',
        ' data-gallery="',self::$carousels,'"',
        ' data-other="',self::$other_uael,'"',
        ' data-pruned="',(self::$applied?'1':'0'),'"',
        ' data-isotope="',(wp_script_is('uael-isotope','enqueued')?'1':'0'),'"',
        ' data-justified="',(wp_script_is('uael-justified','enqueued')?'1':'0'),'"',
        ' data-slick="',(wp_script_is('uael-slick','enqueued')?'1':'0'),'"',
        ' data-fancybox="',(wp_script_is('uael-fancybox','enqueued')?'1':'0'),'"',
        ' />',"\n";
    }
}
add_action('elementor/frontend/widget/before_render',[MAD4B_UAEL_Carousel_Dependency_Gate_V1::class,'inspect'],10,1);
add_action('wp_print_footer_scripts',[MAD4B_UAEL_Carousel_Dependency_Gate_V1::class,'prune'],1);
add_action('wp_footer',[MAD4B_UAEL_Carousel_Dependency_Gate_V1::class,'audit'],99);
