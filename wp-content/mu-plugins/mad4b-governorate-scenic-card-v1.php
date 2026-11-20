<?php
/**
 * Plugin Name: MAD4B Governorate Scenic Card Labels
 * Description: Compact readable destination labels, preserving landmark visibility in the existing JetEngine home carousel.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/** CSS only; no new asset request, JS, image preload, or Elementor data rewrite. */
add_action('wp_head',static function():void {
    if(is_admin() || is_feed() || is_preview() || wp_doing_ajax())return;
    $home=is_front_page();
    if(!$home && is_page()) {
      $front=(int)get_option('page_on_front');
      $lang=(string)apply_filters('wpml_current_language','en');
      $localized=$front?(int)apply_filters('wpml_object_id',$front,'page',false,$lang):0;
      $home=$localized>0 && get_queried_object_id()===$localized;
    }
    if(!$home)return;
?>
<style id="mad4b-governorate-scenic-label-v1" data-no-optimize="1">
/* Only the existing Top Cities home widget, never tour/gallery or archive cards. */
body.home .elementor-element-b25dbff
 .jet-listing-grid__item .elementor-element-95d1c9c {
  justify-content:flex-end!important;
  align-items:flex-start!important;
  gap:5px!important;
  padding:0 13px 13px!important;
}
/* Eliminate the two wide mid-frame strips supplied by listing defaults. */
body.home .elementor-element-b25dbff
 .jet-listing-grid__item .elementor-element-3b68b75,
body.home .elementor-element-b25dbff
 .jet-listing-grid__item .elementor-element-402c8ca {
  width:max-content!important;
  max-width:min(86%,300px)!important;
  min-width:0!important;
  align-self:flex-start!important;
  margin:0!important;
  flex:0 0 auto!important;
  padding:0!important;
  text-align:start!important;
  z-index:2;
}
/* Compact translucent surface only behind text; no full-image gradient. */
body.home .elementor-element-b25dbff
 .elementor-element-3b68b75 .jet-listing-dynamic-link__link {
  display:inline-flex!important;
  width:auto!important;
  max-width:100%!important;
  align-items:center!important;
  min-height:36px!important;
  padding:5px 11px!important;
  margin:0!important;
  color:#fff!important;
  background:rgba(8,30,43,.79)!important;
  border:1px solid rgba(255,255,255,.18)!important;
  border-radius:7px!important;
  box-shadow:0 2px 9px rgba(0,0,0,.1);
  text-align:start!important;
  line-height:1.18!important;
  overflow-wrap:anywhere;
}
body.home .elementor-element-b25dbff
 .elementor-element-3b68b75 .jet-listing-dynamic-link__label {
  display:inline!important;
  padding:0!important;
  background:transparent!important;
  color:#fff!important;
  font-size:clamp(15px,1.45vw,19px)!important;
  font-weight:650!important;
  line-height:1.22!important;
}
body.home .elementor-element-b25dbff
 .elementor-element-402c8ca :is(.jet-listing-dynamic-field__content,.jet-listing-dynamic-field__inline-wrap) {
  display:inline-flex!important;
  width:max-content!important;
  max-width:100%!important;
  margin:0!important;
  padding:4px 9px!important;
  background:rgba(8,30,43,.72)!important;
  border:1px solid rgba(255,255,255,.15)!important;
  border-radius:6px!important;
  color:#fff!important;
  font-size:clamp(12px,1.05vw,13px)!important;
  font-weight:500!important;
  line-height:1.2!important;
  letter-spacing:.01em;
}
/* Respect logical start in RTL and smaller screens without obscuring the hero. */
@media(max-width:767px) {
 body.home .elementor-element-b25dbff .jet-listing-grid__item .elementor-element-95d1c9c {
  padding:0 10px 10px!important;gap:4px!important;
 }
 body.home .elementor-element-b25dbff .elementor-element-3b68b75 .jet-listing-dynamic-link__link {
  padding:5px 9px!important;min-height:32px!important;
 }
}
@media(forced-colors:active) {
 body.home .elementor-element-b25dbff .jet-listing-dynamic-link__link,
 body.home .elementor-element-b25dbff .jet-listing-dynamic-field__content {
  border:1px solid CanvasText!important;
 }
}
</style>
<?php
},110);
