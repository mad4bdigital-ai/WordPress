# Native city cards: preserve Swiper geometry before JavaScript

## Live sites and ownership — 14 November 2026

The original `419-mad4b-city-card-shape-policy.php` MU-plugin remains the **sole owner** of city card shapes. v1.1.0 adds a **small scoped first-paint geometry rule** for an existing JetEngine `jet-listing-grid` Swiper. No duplicate slider, custom Elementor widget, AJAX transport, CSS bundle replacement, or generic user-agent cache split was introduced. Tours.dev and Wayfatours have **byte-identical code**, but independent **tenant-bound WPML+Elementor SHA-256 proofs**.

Why: The first HTML-rendered Swiper slide was temporarily 100% of its container, then shrank to 50% when native Swiper initialized with two tablet columns. Because the city card uses an aspect ratio, this changed carousel height and caused late CLS. The new CSS applies only to a native `.jet-listing-grid__slider:not(.swiper-initialized)` containing the **proved** `jet-listing-grid--{translated-listing-id}` template. For the proved Elementor kit breakpoint 767/991 and 2 columns, the noninitialized slide width/flex-basis are `calc(100% / 2)`. On `.swiper-initialized`, control returns fully to Swiper.

## Permanent admission policy

- Company flag: `mad4b_city_slider_firstpaint_geometry_v1` = `on`/`off`; default `off`.
- Tenant proof: `mad4b_city_slider_firstpaint_geometry_proofs_v1`, contract `mad4b.native-city-slider.ssr-geometry-proof.v1` with site host, base Elementor library listing ID, actual Elementor kit ID, mobile and tablet breakpoints and EN/ES entries.
- Each translated entry supplies the **WPML-resolved** `elementor_library` listing ID, native city-image widget ID, 2-column count, measured 0px spacing, sampled widths (768, 820, 991), SHA of its Elementor `_elementor_data`, SHA of its generated Elementor stylesheet, and recorded QA evidence.
- On each request, WPML resolves the base listing for the current locale and checks the proof. Missing translation, updated template/stylesheet, changed kit boundaries, wrong tenant host, unproven locale or disabled flag **fails closed**. Other templates/languages are not automatically opted in.
- The native company CSS Shape Policy still controls circle/landscape/wide shape independently. This change does not edit Elementor templates, dynamic tags, source images, popups, Fluent Forms, or pricing.

## Measurements and differential limits

Wayfatours, Chromium at 820px and CPU x4 + network throttling: EN original CLS **0.0773**, after **0.0036**; ES original **0.0642**, after **0.0047**. Tours.dev ES at 820px after **0.00045**. At Wayfatours mobile 390px CLS **0.00065**; 1024px ES CLS **0.0407**. EN/ES post-fix native slider slide widths equal half the 820px wrapper. No JavaScript errors, no horizontal overflow. English and Spanish route HTML confirmed correct `jet-listing-grid--45927` and `jet-listing-grid--68823` selectors, respectively.

Regression tools: `test_city_slider_ssr_wpml.php` runs **12/12** in each site's PHP without WordPress bootstrap or DB writes; `verify_city_slider_ssr_browser.js` is a read-only Playwright gate that verifies exact translated listing ownership, Swiper initialization, real layout shift, slide count, half-width geometry within Elementor tablet range, JavaScript errors and overflow. Example:

```sh
node verify_city_slider_ssr_browser.js https://wayfatours.com/es/ 68823 820 0.1
```

**NOT a TBT release:** Wayfatours TBT fluctuated between ~0.5s and 1.5s across throttled tests, dominated by the ~1.2MB LiteSpeed combined JS and native Elementor/jQuery callbacks. TBT was measured but not corrected by first-paint geometry. `admin-ajax.php?action=mad4b_visitor_preference_state` responded asynchronously and is not the entire CPU blocking cause. Tours.dev had a cold/miss homepage and much longer FCP/LCP, so cross-site timing scores are not directly comparable. Do not disable core Elementor initialization or globally exclude JS based only on these traces.

The direct per-company repair is **not** an Original313 signed release and is not fleet-wide automatic approval. Native file preimage backups are under `/opt/mad4b-company-provisioning/backups/city-slider-ssr-20261114/`; targeted page cache revisions were invalidated only for `/` and `/es/`. To rollback without replacing code, switch the per-company option to `off`, then invalidate those two page cache keys through the existing cache provider. Any future source code release must reconcile with both live code SHA and company-specific proofs before deploying.
