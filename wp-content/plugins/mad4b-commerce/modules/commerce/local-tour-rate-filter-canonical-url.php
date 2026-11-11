<?php
/**
 * Local tour filter URL protection. The vendor's JSF mixed URL state may
 * replace a Tour permalink with /jsf/... or /?wcmlc=EUR. These four
 * Tour filters use native AJAX; keep this frontend page canonical so
 * the currency switcher never navigates to the site's home page.
 *
 * Guard is intentionally local-only and adds no public query bypass.
 */
if ( ! defined('ABSPATH') ) return;
add_action('wp_head', static function () {
  if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) || ! is_singular('tours-and-activities')) return;
  $post_id = (int) get_queried_object_id();
  if (!$post_id || !in_array((string)get_post_meta($post_id,'tour_rates_display_style',true),['Grid Listing','Accordion'],true)) return;
  ?>
  <script id="local-tour-rate-filter-canonical-url">
  (function () {
    'use strict';
    var originUrl = new URL(window.location.href);
    var originalPath = originUrl.pathname;
    // WordPress also serves single Tours through the plain ?p=ID route.
    // Preserve this explicit query context instead of treating it as home.
    var plainTour = originalPath === '/' &&
      originUrl.searchParams.get('post_type') === 'tours-and-activities' &&
      /^[0-9]+$/.test(originUrl.searchParams.get('p') || '');
    if (!originalPath || (originalPath === '/' && !plainTour) ||
        /(?:^|\/)jsf\//.test(originalPath)) return;
    ['pushState', 'replaceState'].forEach(function (name) {
      var nativeMethod = window.history[name];
      window.history[name] = function (state, title, newUrl) {
        if (newUrl !== undefined && newUrl !== null) {
          var target;
          try { target = new URL(newUrl, window.location.href); } catch (e) {}
          if (target && target.origin === originUrl.origin &&
              (target.pathname === '/' || /^\/jsf\//.test(target.pathname))) {
            var corrected = new URL(originUrl.href);
            target.searchParams.forEach(function (value, key) {
              corrected.searchParams.set(key, value);
            });
            newUrl = corrected.pathname + corrected.search + corrected.hash;
          }
        }
        return nativeMethod.call(this, state, title, newUrl);
      };
    });
  })();
  </script>
  <?php
}, 0);
