<?php
/**
 * Plugin Name: MAD4B Reviews Gallery Layout & Resolution Contract
 * Description: Preserves full traveler photos in a consistent responsive UAEL carousel while the shared Elementor templates use Large image derivatives.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

add_action('wp_head', static function():void {
    if (is_admin() || is_feed() || is_preview() || wp_doing_ajax())return;
    // The same gallery can appear on the home and multiple tour templates.
    // Rules do not select any other Elementor gallery or product image.
?>
<style id="mad4b-reviews-gallery-layout-v1" data-no-optimize="1">
.elementor-element-dcae8b6 .elementor-element-b9c2361 {
  width:100%!important;
  min-width:0!important;
  max-width:100%!important;
  min-height:0!important;
}
.elementor-element-dcae8b6 .elementor-element-d3b8065 {
  width:100%!important;
  min-width:0!important;
}
.elementor-element-d3b8065 .uael-img-carousel-wrap .slick-list {
  height:360px!important;
}
.elementor-element-d3b8065 .uael-img-carousel-wrap .uael-grid-item {
  padding-inline:7px!important;
  box-sizing:border-box;
}
.elementor-element-d3b8065 .uael-img-carousel-wrap .uael-grid-img,
.elementor-element-d3b8065 .uael-img-carousel-wrap .uael-grid-img-thumbnail {
  display:block;
  height:350px!important;
  overflow:hidden;
  border-radius:14px!important;
  background:#edf1f4;
}
.elementor-element-d3b8065 .uael-img-carousel-wrap .uael-grid-img-thumbnail img {
  display:block!important;
  width:100%!important;
  height:350px!important;
  max-height:350px!important;
  object-fit:contain!important;
  object-position:center center!important;
  border-radius:14px!important;
  background:#edf1f4;
}
@media(max-width:767px) {
  .elementor-element-d3b8065 .uael-img-carousel-wrap .slick-list {
    height:330px!important;
  }
  .elementor-element-d3b8065 .uael-img-carousel-wrap .uael-grid-img,
  .elementor-element-d3b8065 .uael-img-carousel-wrap .uael-grid-img-thumbnail,
  .elementor-element-d3b8065 .uael-img-carousel-wrap .uael-grid-img-thumbnail img {
    height:320px!important;
    max-height:320px!important;
  }
  .elementor-element-d3b8065 .uael-img-carousel-wrap .uael-grid-item {
    padding-inline:4px!important;
  }
}
@media(forced-colors:active) {
  .elementor-element-d3b8065 .uael-img-carousel-wrap .uael-grid-img-thumbnail {
    border:1px solid CanvasText!important;
  }
}
</style>
<?php
}, 110);
