# Wayfatours Tour #60623 — broken Elementor device-mode memoization under deferred JS

**Date:** 2026-11-24. **Source first:** Tours.dev → Wayfatours. **Scope:** public frontend presentation only.

## Root cause verified by actual browser

The existing first-party MU plugin `000-mad4b-elementor-device-mode-cache.php` attached an inline script at `elementor-frontend-js-after`. On the Wayfatours Tour page, the optimized LiteSpeed/Elementor main bundle was moved later than the inline script. The inline script ran with `window.elementorFrontend` undefined and returned permanently. Repeated Elementor `getCurrentDeviceMode()` calls then performed computed-style reads and created main-thread work.

Public Chrome proof **before**:
- Tours.dev 390px: `window.elementorFrontend.__mad4bDeviceModeCache === true`.
- Wayfatours 390px: **false**, despite the inline script being present in HTML.
- Wayfatours HTML script order: early `elementor-frontend-js-after` at script index 49, later `wp-content/litespeed/js/*.js` combined bundle at script index 107.

## Minimal fix in the exact upstream asset

Update `000-mad4b-elementor-device-mode-cache.php` to version **1.1.0**. If Elementor exists at execution, activate immediately just as before. If not, install the **same original memo** on the actual object through Elementor's init event and native DOMContentLoaded/load/pageshow, with **16 bounded retries** and timer cleanup. Do not create an independent CSS breakpoint policy. Invalidate its memo on width change, orientation, pageshow, late stylesheet load and viewport resize; forward unknown parameterized calls to the untouched original API.

**No auth, booking, pricing, tax, form, country, nationality, currency, WordPress editor, or Elementor vendor changes.** Existing public GET/preview/checkout/admin gating remains unchanged. Never use `requestAnimationFrame` or a repeated timer after installation.

New source file SHA256 (identical to source and Wayfa live):
`bb7d486c56c68b91b6765848265192be88a90f2afdf8702f0c0a198419d759f0`
Original per-site SHA256 and preserved backups:
`1dd1fb49e122e838c4d914c01a02ce42eab5d6ecb96d3c88bfde34f45491927b`,
`/opt/mad4b-backups/elementor-device-mode-late-init-20261124/{source,wayfa}/original.php`.

## Browser behavior and A/B

**6/6 public no-cookie canonical** Chromium cases on both sites: EN/ES, 390 and 1366 px; all HTTP 200, `__mad4bDeviceModeCache === true` (prior Wayfa was false), CSS/device mode correctly transitioned mobile→tablet and laptop→mobile, 44 native menu cards, Form 6, price, TOC intact, zero duplicate sticky forms, zero reported JavaScript exceptions.

**Sequential Wayfa ES mobile Chrome 3× CPU throttle**. The old code was substituted *only in a browser interception* of the same canonical HTML for the two legacy controls (no rollback of live site); new code was active in both candidate runs:

| Metric | Legacy 1 | New 1 | New 2 | Legacy 2 |
| --- | ---: | ---: | ---: | ---: |
| `getCurrentDeviceMode` self CPU | 348ms | 50.4ms | 35.4ms | 651.4ms |
| estimated total blocking (all long tasks) | 2,350ms | 2,389ms | 2,352ms | 2,923ms |
| memo active | no | yes | yes | no |

Average function CPU: **~500ms → 43ms (~91% reduction)**. Average total blocking **~2,637ms → 2,371ms (~10% reduction)** over just 2 samples per arm. Variable host and browser contention prevent broad percentile claims. Menu, Form6, price, Sticky and script errors showed functional parity.

Native coordinator selectively re-versioned all published singular Tour HTML: **221 Tours.dev +84 Wayfatours**, without global CSS/JS/Redis purge. Final both live MU file hashes verified equal. Query-only temporary fragment caches and profiling MU probes removed; no preview-only artifacts active.

## Regression tests

- `tests/performance/test_elementor_device_mode_late_init.js`: extracts the **actual** inline JavaScript from this PHP file and simulates ready, delayed and never-loaded Elementor, timing cancellation, 100 cached calls, viewport resize, stylesheet invalidation, editor safety and bounded retry.
- `tests/performance/test_elementor_device_mode_wp_gates.php`: WordPress-free PHP fixture verifies script enqueue on public GET only, no admin, logged-in, checkout or company opt-out; valid Elementor handle/placement.
- PHP lint and JS syntax checks pass.

## The remaining server-side Origin bottleneck (not claimed fixed)

A separate exact-request read-only profiler, now removed, measured Tour #60623 Origin PHP on repeated requests at **~5.1–5.4s**, **556–608 SQL queries**. With isolated `SAVEQUERIES`, 568 SQL queries consumed just **~245ms**. Most time is WordPress+Elementor page composition: ~1.1s bootstrap, ~0.9–1s header, ~2.4s Tour Content template (`#24784` nested inside `#45149`), ~0.4s footer. `elementor-pro` Theme Builder `Locations_Manager::enqueue_styles` alone ~251ms, while related-destinations template subtree ~320–380ms and other live JetEngine grids consume hundreds of milliseconds.

A **separate optional 20s server fragment-cache experiment was deliberately withdrawn and remains OFF**: the dynamic JetEngine CSS selector identity differed between a direct render and a stored fragment (`elementor-dcss-mad4b-cd6cc8338db50b63` vs `elementor-dcss-mad4b-ca7d27826730eddd` in an actual term). Reenqueuing CSS stylesheets alone does not prove safe styling. Both `450-...` and `498-...` temporary MU implementations were removed; `mad4b_related_destinations_fragment_v1=off`. Do not reinstate fragment caching until source fragments have deterministic term/document scoped CSS identity, semantic HTML+CSS+link SSR parity, and event-driven invalidation. Preserve `mad4b-rates-elementor-dynamic-cache.php` and never cache quotes, Form6 tokens, live prices or visitor/session state across contexts.

**Do not report Origin <1s or logged-in Chrome Page Unresponsive fully closed** from this client JS fix. Completing backend architectural improvements requires separate source-first, verified static-vs-commercial Elementor fragment boundaries (or a public shell with live commerce islands) and authenticated browser acceptance.
