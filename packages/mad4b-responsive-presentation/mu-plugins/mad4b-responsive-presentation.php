<?php
/**
 * Plugin Name: MAD4B Shared Responsive Presentation
 * Description: Brand-neutral responsive archive tools and Tour Rate card/accordion parity.
 * Version: 1.2.0
 * License: Proprietary
 *
 * Source: packages/mad4b-responsive-presentation/mu-plugins/mad4b-responsive-presentation.php
 * Safe: style-only; no queries, pricing, company settings or template mutations.
 */
defined('ABSPATH') || exit;
if (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) return;

add_action('wp_head', static function () {
    // All public Elementor templates may inherit the same full-width canvas.
    // Style selectors remain scoped to authored components and existing routes.
    if (is_admin() || is_feed() || is_embed()) return;
    ?>
<style id="mad4b-responsive-presentation-v1">
/* Brand-neutral responsive presentation: destination tour toolbar, content width and tour-rate parity. */
@media (min-width: 1025px) {
  body:is(.post-type-archive-tours-and-activities,.tax-destinations,.single-tours-and-activities,.blog,.single-post) .ast-container {
    width: calc(100% - 36px) !important;
    max-width: 1560px !important;
    padding-inline: 0 !important;
  }
  .elementor-45804 .elementor-element-bd895d0 > .e-con-inner {
    max-width: min(100%, 1500px) !important;
  }
}
.elementor-45804 .elementor-element-894c714,
.elementor-45804 .elementor-element-2654d6f,
.elementor-45804 .elementor-element-c7a98c5,
.elementor-45804 .elementor-element-efe922d,
.elementor-45804 .elementor-element-adb058f {
  min-width: 0;
  box-sizing: border-box;
}
.elementor-45804 .elementor-element-894c714 { padding: 14px !important; }
.elementor-45804 .elementor-element-2654d6f { padding: 10px !important; }
.elementor-45804 .elementor-element-894c714 .elementor-widget-jet-ajax-search,
.elementor-45804 .elementor-element-894c714 .jet-ajax-search {
  width: 100%;
  max-width: 100%;
  min-width: 0;
}
.elementor-45804 .elementor-element-adb058f {
  gap: 8px;
  min-width: 0;
}
.elementor-45804 .elementor-element-adb058f .elementor-widget-jet-smart-filters-sorting,
.elementor-45804 .elementor-element-adb058f .elementor-widget-jet-engine-layout-switcher {
  min-width: 0;
  margin: 0 !important;
}
@media (max-width: 767px) {
  .elementor-45804 .elementor-element-894c714 {
    display: flex !important;
    flex-direction: column !important;
    flex-wrap: nowrap !important;
    gap: 12px !important;
    padding: 12px !important;
    min-height: 0 !important;
  }
  .elementor-45804 .elementor-element-2654d6f {
    flex: 0 0 auto !important;
    width: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
    min-height: 0 !important;
  }
  .elementor-45804 .elementor-element-c7a98c5 {
    display: flex !important;
    flex-direction: column !important;
    flex-wrap: nowrap !important;
    justify-content: flex-start !important;
    align-items: stretch !important;
    width: 100% !important;
    gap: 9px !important;
    padding: 0 !important;
    min-height: 0 !important;
  }
  .elementor-45804 .elementor-element-c7a98c5 > .elementor-element-efe922d {
    position: static !important;
    transform: none !important;
    inset: auto !important;
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex: 0 0 auto !important;
    width: 100% !important;
    max-width: 100% !important;
    min-height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    gap: 8px !important;
  }
  .elementor-45804 .elementor-element-efe922d .elementor-element-adb058f {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    flex: 0 0 auto !important;
    width: auto !important;
    justify-content: flex-end !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 0 !important;
  }
  .elementor-45804 .elementor-element-c7a98c5 > .elementor-widget-jet-listing-dynamic-field {
    flex: 0 0 auto !important;
    width: 100% !important;
    max-width: 100%;
    margin: 0 !important;
  }
  .elementor-45804 .elementor-element-efe922d .jet-engine-layout-switcher {
    display: flex;
    align-items: center;
    min-height: 40px;
  }
}
/* The Accordion embeds the canonical 46193 rate card; do not fork pricing data or its actions. */
.elementor-57381 .tour-native-package-tier,
.elementor-57381 .tour-native-package-tier .rate-positioned-by-date {
  width: 100% !important;
  max-width: 100%;
  min-width: 0;
  box-sizing: border-box;
}
.elementor-57381 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions {
  display: flex !important;
  width: 100% !important;
  min-height: 44px;
  gap: 0;
  margin: 8px 0 10px !important;
  padding: 0 !important;
}
.elementor-57381 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions button {
  width: 100% !important;
  min-width: 0;
  min-height: 44px;
  border-radius: 10px;
  font-size: clamp(13px,1.15vw,16px);
}
.elementor-57381 .rate-select-actions .rate-native-selected-label { display: inline; }
.elementor-57381 .rate-select-actions button[hidden] { display: none !important; }
@media (max-width: 600px) {
  .elementor-57381 .tour-native-package-tier,
  .elementor-57381 .tour-native-package-tier .e-con,
  .elementor-57381 .tour-native-package-tier .elementor-widget-wrap {
    min-width: 0;
    max-width: 100%;
  }
  .elementor-57381 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions button {
    min-height: 42px;
    padding: 8px 10px !important;
    font-size: 13px;
  }
}
@media (max-width: 767px) {
  .elementor-45804 .elementor-element-efe922d > .elementor-element-a25c631 {
    flex: 0 0 auto !important;
    width: auto !important;
    max-width: 100%;
    margin: 0 !important;
    padding: 0 !important;
  }
  .elementor-45804 .elementor-element-c7a98c5 > .elementor-element-efe922d { gap: 4px !important; }
  .elementor-45804 .elementor-element-efe922d .elementor-element-adb058f { gap: 6px !important; }
}
/* Global Elementor Full Width: only page surfaces using Elementor templates. */
body[class*="elementor-page-"]:not(.elementor-editor-active) #content.site-content > .ast-container {
  box-sizing: border-box;
  width: 100% !important;
  max-width: none !important;
  margin-inline: 0 !important;
  padding-inline: 0 !important;
}
/* Keep authored Elementor inner containers: Full Width is not Canvas. */
@media (min-width: 1025px) {
  .elementor-30843 .elementor-element-ff78100 {
    padding-inline: clamp(12px, 1.25vw, 22px) !important;
  }
  .elementor-30843 .elementor-element-7d67ce7,
  .elementor-30843 .elementor-element-84a8df9 {
    width: 17% !important;
    flex: 0 0 17% !important;
    min-width: 0;
  }
  .elementor-30843 .elementor-element-9a44016 {
    width: 66% !important;
    flex: 0 0 66% !important;
    min-width: 0;
  }
}
/* SEO: retain one visible archive H1, improve contrast and balance. */
.elementor-30843 .elementor-element-3c6d458b h1.elementor-heading-title,
.elementor-30875 .elementor-element-a85406ad h1.elementor-heading-title {
  display: block;
  width: 100%;
  max-width: 100%;
  margin-inline: auto !important;
  text-align: center !important;
  text-wrap: balance;
  overflow-wrap: break-word;
  line-height: 1.22 !important;
  font-family: var(--e-global-typography-primary-font-family, inherit);
  font-size: clamp(26px, 2.35vw, 39px) !important;
  color: var(--e-global-color-primary, var(--ast-global-color-0, #253548)) !important;
}
.elementor-30843 .elementor-element-3c6d458b h1.elementor-heading-title {
  text-shadow: none !important;
}
.elementor-30875 .elementor-element-a85406ad h1.elementor-heading-title {
  padding-block: clamp(8px, 1.1vw, 16px);
}
/* Native Jet search and availability submit: dynamic company Elementor Kit colors. */
.elementor .jet-date-range__submit.apply-filters__button,
.elementor .jet-ajax-search__submit,
.elementor .jet-ajax-search__submit-button,
.elementor .jet-smart-filters-apply-button .apply-filters__button {
  background-color: var(--e-global-color-accent, var(--ast-global-color-1, #d6d6d6)) !important;
  background-image: none !important;
  border-color: var(--e-global-color-accent, var(--ast-global-color-1, #d6d6d6)) !important;
  color: var(--e-global-color-primary, var(--ast-global-color-0, #253548)) !important;
  font-family: var(--e-global-typography-text-font-family, inherit);
  transition: filter .16s ease, box-shadow .16s ease;
}
.elementor .jet-date-range__submit.apply-filters__button:hover,
.elementor .jet-ajax-search__submit:hover,
.elementor .jet-ajax-search__submit-button:hover,
.elementor .jet-smart-filters-apply-button .apply-filters__button:hover {
  filter: brightness(.94);
}
.elementor .jet-date-range__submit.apply-filters__button:focus-visible,
.elementor .jet-ajax-search__submit:focus-visible,
.elementor .jet-ajax-search__submit-button:focus-visible,
.elementor .jet-smart-filters-apply-button .apply-filters__button:focus-visible {
  outline: 2px solid var(--e-global-color-primary, currentColor);
  outline-offset: 2px;
}
@media (max-width: 767px) {
  .elementor-30843 .elementor-element-3c6d458b h1.elementor-heading-title,
  .elementor-30875 .elementor-element-a85406ad h1.elementor-heading-title {
    font-size: clamp(24px, 6.8vw, 32px) !important;
    line-height: 1.26 !important;
  }
}

/* Passport AJAX options belong visually above the page, not inside the header scroller.
   The existing list keeps its own independent keyboard-scrollable max-height. */
.elementor-location-header .header-context-controls.is-open .header-context-panel:has(.travel-booking-passport-search__results:not([hidden])) {
  overflow: visible !important;
}
.elementor-location-header .header-context-panel .travel-booking-passport-search__results:not([hidden]) {
  max-height: min(220px, max(72px, calc(100dvh - 225px))) !important;
  overflow-y: auto !important;
  overscroll-behavior: contain;
}
@media (max-width: 1024px) {
  .elementor-location-header .header-context-panel .travel-booking-passport-search__results:not([hidden]) {
    max-height: min(220px, max(72px, calc(100dvh - 170px))) !important;
  }
}
</style>
<?php
}, 98);
