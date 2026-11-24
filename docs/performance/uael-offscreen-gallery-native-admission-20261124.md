# UAEL offscreen image gallery: prevent early Slick/jQuery main-thread work

**Date:** 2026-11-24. **Scope:** published public Tour detail pages; first-party source+company MU plugin. **Source → company deployment.** No vendor plugin edits, admin/session changes, pricing/booking changes or full page caching.

## Reproduced browser root cause

On Wayfatours Tour #60623 (English and Spanish), an **Ultimate Addons for Elementor (UAEL)** gallery under widget `data-widget_type="uael-image-gallery.default"`, Elementor element `d3b8065`, is located ~10,168px below the viewport on Spanish mobile 390px (~9,275px on Spanish desktop 1366px), yet UAEL eagerly runs `jQuery.fn.imagesLoaded` and initializes its native Slick carousel during DOM ready. It prepares **22 original image slides** plus Slick clones, causing jQuery geometry/layout, DOM creation, and measurable long main-thread tasks before a visitor can see it.

A previous browser-only trial confirmed intercepting Slick alone was unsafe: UAEL invokes `slick('getSlick')` synchronously after `slick(options)`. The correct boundary is **before** UAEL's original `imagesLoaded` callback; postpone the entire original callback until the gallery approaches the viewport. Never hide images, discard callbacks, delete Elementor widgets, or change Slick itself.

## Exact first-party implementation

New `wp-content/mu-plugins/451-mad4b-offscreen-uael-gallery-admission.php` v1.0.1, SHA256 **`ee3cd737c854523387d646a800b6639f03bf8612ccbd78cb1a77a4ec7b9a6ee0`**, identical source and Wayfa live code.

The native `elementor-frontend` **before** inline script installs a narrow wrapper of `jQuery.fn.imagesLoaded` before vendor DOMReady handlers. It defers only a **single, far-offscreen** `uael-image-gallery.default` with a `.uael-img-carousel-wrap`; it uses an `IntersectionObserver` with `rootMargin:1100px 0px`, preserving callback order, original `this`, and Slick/lightbox event behavior. A WeakMap consolidates duplicate callbacks. It immediately runs the original native method for galleries near viewport, hidden, any other widget, inside booking/form/mega-menu, missing IntersectionObserver, and unqualified calls. Diagnostic `window.MAD4BUaelOffscreen` exposes `installed/deferred/activated` counters, not user data.

Admission is fail-closed outside anonymous public GET singular `tours-and-activities`: excludes logged-in/admin/checkout/cart/account, WordPress editor, preview, REST, AJAX, CLI/cron, authorization headers, POST, and query parameters. Company may opt out through `mad4b_offscreen_uael_gallery_v1=off`; enabled by default only within the strict public Tour guard. Does not alter commercial runtime, market/currency, Form 6, tour rates, or product prices.

## A/B measurements and live parity

**Controlled browser interception, same Wayfatours ES Tour #60623, 390px mobile, Chrome 3x CPU throttling; order baseline / deferred / deferred / baseline:**

| Arm | Long tasks | Estimated total blocking | DOM before reaching gallery |
| --- | ---: | ---: | ---: |
| Baseline 1 | 24 | 2,019ms | 5,200 |
| Deferred 1 | 20 | 1,535ms | 5,058 |
| Deferred 2 | 20 | 1,477ms | 5,058 |
| Baseline 2 | 24 | 1,683ms | 5,200 |

Mean **1,851 → 1,506ms**, ~**19% lower** long-task blocking in this four-sample A/B, 142 fewer initial DOM nodes; not a p75/p95 claim. Each sample preserved 44 Mega Menu cards, Form 6, price and no JavaScript exceptions.

**Actual live code installed source-first and company second**: source Tours.dev Tour #60623 has **no UAEL gallery**; plugin intentionally does not change its gallery or content. Wayfa ES 390px, EN 390px, ES 1366px live fresh Origin after deployment: UAEL wrapper installed, one offscreen gallery deferred, zero activated initially, Slick not initialized; at gallery approach original UAEL carousel initialized with exactly 22 real slides and **`slick('slickNext')` moved from index 0 to 1**. Mobile height 327px → 330px and page height 13989px → 13991px; desktop height 357px → 360px and page height 11665px → 11668px. All 44 menu cards, Form 6, price, TOC, no cloned forms preserved; zero sampled JS exceptions and failed internal assets. One desktop test encountered transient `ERR_NETWORK_CHANGED` and was rerun successfully; it was not considered an application failure.

**Native FastCGI scoped path invalidation:** versioned **221 Tours.dev +84 Wayfatours** public published Tour paths using `MAD4B_Cache_Control_V1::socket_purge` coordinated tool. All batch `status=ok`, versioned path count equals expected; **no global CSS, JS or Redis purge**. Subsequent *no diagnostic cookie* canonical browser EN/ES mobile/desktop confirmed new markup reached Wayfa public traffic and original commerce UI is intact.

## Tests and safeguards

- `tests/performance/test_offscreen_uael_gallery.js` extracts **actual inline JavaScript from the PHP plugin**, checks offscreen activation exactly once, duplicate callback preservation, native no-carousel/hidden/near/form/other widget fallbacks.
- `tests/performance/test_offscreen_uael_wp_gates.php` checks public-only enqueue, blocked admin, logged in, checkout, query-param, explicit company opt-out.
- PHP lint, Node syntax check and both fixtures pass on production-compatible PHP/Node.

**Rollback:** Atomically withdraw only `451-mad4b-offscreen-uael-gallery-admission.php` from MU directory (or set company option to `off` via authorized WP admin/WP-CLI when capacity available), then version only published Tour HTML paths. No vendor changes to revert and no broad cache purges required. Compare SHA to the above before removal. If a genuine UAEL gallery appears on an upstream source page, admission remains semantically based on widget type and proximity; unknown contexts use native rendering.

## Remaining scope outside this fix

This removes a measurable **client Main Thread** workload but does **not** achieve sub-1-second uncached Origin. The separately profiled source Tour #60623 PHP render remains ~5.1–5.4 seconds with 556–608 SQL queries, SQL wall ~245ms. The main server root is Elementor nested Tour Content `24784` (1.35–1.46s on nested widget), other JetEngine grids (~0.5s exclusive), Theme Builder CSS, bootstrap. A related-destinations fragment-cache experiment is **withdrawn** due to mismatched JetEngine dynamic CSS selector identity; its temp MU files removed and option disabled, not a production solution. Must never cache rates, nonce, Form 6 or visitor market state in shared HTML. Authenticated Chrome `Page Unresponsive` proof remains outstanding; do not claim total freeze closed from public samples.
