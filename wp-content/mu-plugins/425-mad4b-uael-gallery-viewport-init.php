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
