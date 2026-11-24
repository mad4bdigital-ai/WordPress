<?php
/**
 * Plugin Name: MAD4B Offscreen UAEL Gallery Bootstrap
 * Description: Defer only far-offscreen public Tour image-carousel initialization while preserving UAEL's native Slick/lightbox behavior.
 * Version: 1.0.1
 */
defined('ABSPATH') || exit;
// Enabled by default only on the narrowly qualified anonymous Tour frontend.
// Company operators may opt out by setting mad4b_offscreen_uael_gallery_v1=off.
// No authorization, publication, commerce or admin policy is relaxed.

add_action('wp_enqueue_scripts', static function (): void {
    // Private, editor, forms, transaction and unknown routes remain unchanged.
    if (get_option('mad4b_offscreen_uael_gallery_v1', 'on') !== 'on'
        || is_admin() || wp_doing_ajax() || is_user_logged_in()
        || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        || !empty($_POST) || !empty($_GET)
        || is_preview() || is_customize_preview()
        || (defined('WP_CLI') && WP_CLI)
        || (defined('DOING_CRON') && DOING_CRON)
        || (defined('REST_REQUEST') && REST_REQUEST)
        || !is_singular('tours-and-activities')
        || !get_queried_object_id()
        || (function_exists('is_checkout') && is_checkout())
        || (function_exists('is_cart') && is_cart())
        || (function_exists('is_account_page') && is_account_page())
        || !empty($_SERVER['HTTP_AUTHORIZATION'])
    ) return;

    $js = <<<'JS'
(function(){
  "use strict";
  // A first-party admission hook, not a fork of Slick/UAEL. Preserve all
  // images, URLs, gallery callbacks and the original jQuery plugin.
  if (window.__mad4bUaelOffscreenPrepared) return;
  window.__mad4bUaelOffscreenPrepared = true;

  var state = {installed:false,deferred:0,activated:0};
  window.MAD4BUaelOffscreen = state;

  function install(){
    var $ = window.jQuery;
    if (!$ || !$.fn || typeof $.fn.imagesLoaded !== 'function'
        || typeof window.IntersectionObserver !== 'function') return false;

    var nativeImagesLoaded = $.fn.imagesLoaded;
    if (nativeImagesLoaded.__mad4bOffscreenGalleryV1) return true;

    var pending = new WeakMap();

    function admission(){
      var node = this.length === 1 ? this[0] : null;
      if (!node || arguments.length !== 1 || typeof arguments[0] !== 'function'
          || !node.getAttribute || node.getAttribute('data-widget_type') !== 'uael-image-gallery.default'
          || !node.querySelector || !node.querySelector('.uael-img-carousel-wrap')
          || (node.closest && node.closest('form,#fluentform_6,[data-id="6072b3f"]'))) {
        return nativeImagesLoaded.apply(this,arguments);
      }

      var box = node.getBoundingClientRect();
      // Hidden, invalid, above-fold or previously visible galleries use the
      // standard immediate UAEL lifecycle and its own dimensions.
      if (box.height < 100 || box.width < 100
          || box.top <= window.innerHeight * 2
          || box.bottom < -window.innerHeight) {
        return nativeImagesLoaded.apply(this,arguments);
      }

      var existing = pending.get(node);
      if (existing) {
        existing.callbacks.push([arguments[0]]);
        return this;
      }

      var collection = this;
      var job = {callbacks:[[arguments[0]]],observer:null,activated:false};
      pending.set(node,job);

      try {
        job.observer = new IntersectionObserver(function(entries){
          if (job.activated || !entries.some(function(entry){return entry.isIntersecting;})) return;
          job.activated = true;
          job.observer.disconnect();
          pending.delete(node);
          if (!node.isConnected) return;
          state.activated++;
          // UAEL's own imagesLoaded callback still invokes slick(options)
          // then slick('getSlick'), in the original order and context.
          job.callbacks.forEach(function(args){nativeImagesLoaded.apply(collection,args);});
        },{rootMargin:'1100px 0px',threshold:0});
        job.observer.observe(node);
      } catch (e) {
        pending.delete(node);
        if (job.observer) job.observer.disconnect();
        return nativeImagesLoaded.apply(this,arguments);
      }
      state.deferred++;
      return this;
    }

    admission.__mad4bOffscreenGalleryV1 = true;
    $.fn.imagesLoaded = admission;
    state.installed = true;
    return true;
  }

  // This inline loader is emitted before Elementor's frontend handle,
  // and its DOM-ready listener precedes UAEL's image gallery initialization.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install, {once:true});
  } else {
    install();
  }
})();
JS;
    wp_add_inline_script('elementor-frontend', $js, 'before');
}, 960);
