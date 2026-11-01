# Staging: dependency-preserving selective JavaScript deferral

Date: 2026-11-01. Exact scope: tours.dev.mad4b.com Staging UUID e5e64bca-ed62-48d2-b2b3-dff693cd702a. Production unchanged.

## Before and root cause

Earlier global LiteSpeed JS Defer (optm-js_defer=1) broke JetSmartFilterSettings, filtersList and wp.hooks, even with exclusions, and was reverted to optm-js_defer=0 with the exact original exclusions restored.

A read-only Staging script graph proved that wp-i18n depends on wp-hooks; elementor-pro-frontend depends on wp-i18n. JetSmartFilters depends on jquery, jet-plugins, datepicker and JetMaps initializers. The JetSmartFilters inline bootstrap is >12KB. Changing global ordering is unsafe.

## Implemented

File: wp-content/mu-plugins/330-mad4b-staging-leaf-script-defer.php
SHA256: 397621ea428ee447dbec5b42d7f6952dae2c7de557fb05da26da086b4082aaa8.

The module allows ONLY eight known noncritical leaf script handles to defer: uael-isotope, uael-element-resize, uael-fancybox, uael-slick, uael-social-share, mad4b-local-gallery-a11y, mad4b-local-archive-a11y and wpml-language-switcher-block-keyboard-navigation. WordPress wp_script_add_data(..., strategy, defer) runs only after checking the current registered dependency graph: enqueued + first-party content_url + footer group + no inline before/after/localizations + no existing strategy + no enqueued dependent. It never touches wp-hooks, JetSmartFilters, Elementor, Woo, the nationality switcher, booking or prices.

Guards: exact enrolled Staging host/UUID, six WPML routes for homepage/archive, anonymous GET, no query, cookies, auth, AJAX or preview. Optional native bypass: X-MAD4B-LEAF-DEFER-CONTROL: native-v1. No global LiteSpeed configuration changes. Localized home_url caused earlier non-English mismatch; corrected to stable content_url before acceptance.

## Actual six-locale browser checks

Playwright 390x844 compared request-level native bypass and selective deferral. All HTTP 200, zero observed page JS errors, wp.hooks and JetSmartFilterSettings initialized, price text and card counts unchanged.

Parser-blocking script counts:
- EN homepage: 64 -> 57; EN Tours: 71 -> 63.
- AR homepage: 52 -> 49; AR Tours: 61 -> 57.
- FR Tours: 61 -> 57; DE Tours: 67 -> 63; ES Tours: 67 -> 63; IT Tours: 67 -> 63.

EN and AR List -> Grid -> List roundtrips on the live archive passed in native and optimized modes without browser errors.

These are parser-blocking script counts, not measured Core Web Vitals FCP/LCP gains. No claim that the original Lighthouse estimated 2360ms savings have been delivered.

## PHP cold-render boundary

Native Tour List Listing #25102 / Query #5 and Featured #14962 / Query #22 still cost hundreds of milliseconds of PHP per page. Temporary profiler captured JetSmartFilters range/checkbox overhead as separate significant contributions. Existing MU328 reduces 9-10 repeated nested term-link renders within one PHP request for EN/FR/DE (~48-99ms isolated component savings). No price/visitor HTML cache, full-listing lazy load or production change has been enabled. Multi-second cold-origin PHP TTFB is still OPEN.

## Repro and rollback

Staging graph: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/oauth-signing/script-graph-20261101.jsonl
Browser and layout acceptance: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/leaf-defer-20261101/
Rollback: rollback-staging-leaf-defer.sh plan|apply inside that QA folder. The plan verifies exact Staging site URL/environment/enrollment/source hash and was run successfully. Apply removes only MU330 and was not run.

Source PHP lint and isolated source/allowlist contract pass. No Production changes or PR merge.
