<?php
/**
 * Plugin Name: MAD4B Shared Responsive Presentation
 * Description: Brand-neutral responsive archive tools and Tour Rate card/accordion parity.
 * Version: 1.0.0
 * License: Proprietary
 *
 * Source: packages/mad4b-responsive-presentation/mu-plugins/mad4b-responsive-presentation.php
 * Safe: style-only; no queries, pricing, company settings or template mutations.
 */
defined('ABSPATH') || exit;
if (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) return;

add_action('wp_head', static function () {
    if (is_admin() || !(
        is_tax('destinations')
        || is_post_type_archive('tours-and-activities')
        || is_singular('tours-and-activities')
        || is_home()
        || is_singular('post')
    )) return;
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
</style>
<?php
}, 98);
