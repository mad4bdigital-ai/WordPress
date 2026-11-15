<?php
/**
 * Plugin Name: MAD4B Tour Gallery Popup Layout
 * Description: Responsive, scroll-safe Elementor/UAEL tour gallery dialog; does not alter gallery data or other popups.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts', static function (): void {
    if (!is_singular('tours-and-activities')) return;
    wp_register_style('mad4b-tour-gallery-popup-layout', false, [], '1.0.0');
    wp_enqueue_style('mad4b-tour-gallery-popup-layout');
    wp_add_inline_style('mad4b-tour-gallery-popup-layout', <<<'CSS'
/* Target only Elementor popups containing the UAEL tour photo gallery. */
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-widget-content {
    width: min(1100px, calc(100vw - 32px)) !important;
    max-width: calc(100vw - 32px) !important;
    max-height: calc(100dvh - 32px) !important;
    border-radius: 16px !important;
    overflow: hidden !important;
    box-sizing: border-box;
}
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-message {
    width: 100% !important;
    max-height: calc(100dvh - 40px) !important;
    min-height: 0 !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    padding: 18px 20px !important;
    box-sizing: border-box !important;
    overscroll-behavior: contain;
}
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-close-button {
    position: absolute !important;
    z-index: 100 !important;
    top: 10px !important;
    right: 12px !important;
    margin: 0 !important;
    background: rgba(255,255,255,.95) !important;
    border-radius: 50%;
    padding: 8px;
}
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .elementor-widget-uael-image-gallery,
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .uael-img-gallery-wrap {
    width: 100% !important;
    max-width: 100% !important;
}
@media (max-width: 767px) {
    body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-widget-content {
        width: calc(100vw - 16px) !important;
        max-width: calc(100vw - 16px) !important;
        max-height: calc(100dvh - 16px) !important;
    }
    body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-message {
        padding: 14px 10px !important;
        max-height: calc(100dvh - 24px) !important;
    }
}
CSS
    );
}, 55);

add_action('wp_enqueue_scripts', static function (): void {
    if (!is_singular('tours-and-activities')) return;
    // Keep the Elementor button as the single popup authority. This asset
    // only makes the surrounding background-image photo frames actionable.
    $relative='mad4b-tour-gallery-popup-layout/frames.js';
    $path=__DIR__.'/'.$relative;
    if (!is_readable($path) || is_link($path)) return;
    wp_enqueue_script(
        'mad4b-tour-gallery-frame-opener',
        plugins_url($relative, __FILE__),
        ['elementor-frontend'],
        (string)filemtime($path),
        true
    );
    // Per-object focal metadata is presentation-only. It never replaces an
    // image, changes ownership, or implies photographic/license approval.
    // Other registered media providers may contribute their own field names
    // through the same policy filter; nothing is tied to Elementor IDs.
    $post_id=(int)get_queried_object_id();
    $requested=get_post_meta($post_id,'_mad4b_media_focal_points_v1',true);
    if(!is_array($requested))$requested=[];
    $requested=apply_filters('mad4b/media/frame_focal_policy',$requested,$post_id,'tours-and-activities');
    if(!is_array($requested))$requested=[];
    $by_url=[];
    foreach(array_slice($requested,0,30,true) as $key=>$point){
        if(!is_string($key)||!preg_match('/^[a-zA-Z_][a-zA-Z_0-9]{1,90}$/D',$key)
            ||!is_array($point)||array_keys($point)!==['x','y'])continue;
        $x=$point['x'];$y=$point['y'];
        if((!is_int($x)&&!is_float($x)) ||(!is_int($y)&&!is_float($y))
            ||$x<0||$x>100||$y<0||$y>100)continue;
        $url=get_post_meta($post_id,$key,true);
        if(!is_string($url)||strlen($url)>2048||!str_starts_with($url,'https://'))continue;
        $parts=wp_parse_url($url);
        $host=strtolower((string)($parts['host']??''));
        $allowed_hosts=[strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)),
            strtolower((string)wp_parse_url(wp_get_upload_dir()['baseurl'],PHP_URL_HOST))];
        if(!in_array($host,$allowed_hosts,true)||!str_contains((string)($parts['path']??''),'/wp-content/uploads/'))continue;
        $by_url[$url]=['x'=>$x,'y'=>$y];
    }
    if($by_url){
        wp_add_inline_script('mad4b-tour-gallery-frame-opener',
            'window.MAD4B_TOUR_FRAME_FOCAL_V1='.
            wp_json_encode($by_url,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).';',
            'before');
    }
    wp_add_inline_style('mad4b-tour-gallery-popup-layout',
        'body.single-tours-and-activities .mad4b-gallery-frame-opener {'.
        'cursor:pointer;}'.
        'body.single-tours-and-activities .mad4b-gallery-frame-opener:focus-visible {'.
        'outline:3px solid var(--e-global-color-accent,#b88942);'.
        'outline-offset:-4px;}'
    );
}, 58);
