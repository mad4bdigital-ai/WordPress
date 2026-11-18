<?php
/**
 * Plugin Name: MAD4B Mobile Bottom Actions Navigation Visibility
 * Version: 1.0.0
 * Description: Give the real Elementor mobile menu priority over fixed lower
 * booking/inquiry actions, without removing original Elementor spacers.
 */
defined('ABSPATH') || exit;

add_action('wp_head', static function (): void {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    echo <<<'HTML'
<style id="mad4b-mobile-bottom-nav-policy-v1" data-no-optimize="1">
/* Only a genuinely OPEN Elementor mobile menu suspends floating page actions.
   Retain original Elementor dock and invisible layout spacer in the DOM.
   No page body transforms, reparenting, polling, or click interception. */
@media (max-width:767px) {
 /* The archive minimum belongs to the original Elementor CTA dock, which
    is portaled into body on DOM readiness. Never paint an isolated price
    outside its ready dock while that one-time portal is still pending.
    Keep the original inquiry button accessible if the portal JS fails. */
 body.post-type-archive-tours-and-activities
 .elementor-element-650c69:not([data-mad4b-dock-mounted="viewport"])
 .mad4b-archive-price-value {
   visibility:hidden!important;
   pointer-events:none!important;
 }
 body:has(.elementor-location-header .e-n-menu-toggle[aria-expanded="true"])
 :is(
  .mad4b-mobile-dock-host,
  .elementor-sticky--active[data-settings*='"sticky":"bottom"'],
  .mad4b-tour-mobile-action-dock.elementor-sticky--active,
  #ast-scroll-top,
  .ahc-btn
 ) {
   visibility:hidden!important;
   pointer-events:none!important;
   opacity:0!important;
 }
}
</style>
HTML;
}, 6);
