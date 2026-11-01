# Cold-origin Elementor listing JSON request reuse — Staging 2026-11-01

Scope: https://tours.dev.mad4b.com, enrolled WordPress Staging UUID e5e64bca-ed62-48d2-b2b3-dff693cd702a. No Production mutation.

## Diagnosis

Temporary Staging profiler on English archive found 23 Elementor listing content calls: 12 Tour List and 11 Featured. Data preparation before print totaled 305.63ms, native print 548.80ms and after-print hook/CSS handling 347.75ms. JetEngine still performs native rendering of every tour's price and HTML.

WPML's original get_post_metadata filter for Elementor metadata can translate query IDs and returns processed source as a string. Reading the raw meta directly would break localization. This optimization respects WPML's output and only avoids repeated JSON decoding for the same verified source.

## Source and safeguards

New MU: wp-content/mu-plugins/331-mad4b-elementor-request-json-memo.php, version 1.0.0, SHA256 c4479d40b7f1e51227f23edceade3a13bb07631a823636dd0fea61caae2d4b80.

For exact anonymous Tours archive GETs in EN/AR/FR/DE/ES/IT, pre-decode two source-pinned published Elementor templates per locale, once per PHP request. The metadata filter runs after native WPML processing and substitutes a predecoded array ONLY if a single _elementor_data read comes from Elementor Core Base Document get_json_meta AND the actual WPML-returned raw source SHA256 exactly matches the pinned template. If content differs or any guard fails, return the native value unchanged. Do not interfere with raw metadata reads by CSS generators. Source pinned to Elementor 4.3.5, WPML 4.9.5, Staging URL and UUID, USD default, zero cookie/auth/query/geo changes, and 12 exact listing JSON hashes.

No tour HTML, dynamic prices, availability, filter results, currency, nationality, cart, session or customer data stored. No persistent Redis/DB/transient storage. Native comparison via request header X-MAD4B-TEMPLATE-DATA-CONTROL: native-v1.

A trial originally saw zero hits because WPML already returned metadata, then was corrected to respect it and confirmed 23 hits in an English first-origin request. All trial disk logging was removed.

## Live browser acceptance

Headless Chromium 1440px across six locales compared bypass vs optimized default (12 public archive requests): HTTP 200, zero page JS errors, identical Tour List/Featured post IDs and order, exactly equal displayed price text, anchor hrefs, normalized card text, stylesheet link count, style count and JS URL fingerprints. Only the normal per-request Elementor dynamic CSS numeric selector varied.

EN and AR real List -> Grid -> List interactions passed in both modes, with zero JS errors. A real EN JetSmartFilters destination change selected Egypt, updated the filter URL, returned HTTP 200 native Ajax and no JS errors in both modes.

Standalone PHP contract checks single-metadata array semantics, changed WPML source hashes, raw/CSS readers, unrelated document reads and no persistent cache.

## Six alternating A/B pairs, CSS cache bypassed in both

Pair | Native TTFB (s) | Request memo (s)
--- | --- | ---
1 | 3.781087 | 3.006973
2 | 3.264311 | 3.242695
3 | 3.391694 | 3.148048
4 | 4.061398 | 3.229493
5 | 3.343951 | 3.918592
6 | 3.459576 | 3.321533

Native median 3.425635s, optimized median 3.236094s. Observed median gap 0.189541s (5.5%); optimized faster in 5/6 pairs. Noisy, small sample including one slow optimized pair. This is measured first-origin improvement without a warmed CSS cache, not a guaranteed p75/p95 or full TTFB closure.

## Evidence and rollback

QA: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/elementor-json-memo-20261101/
Rollback: rollback-elementor-json-request-memo.sh plan|apply in QA. Exact URL, Staging, enrolled UUID and current source hash checked; plan PASS, apply not run.

Truly cold 3-4s PHP TTFB remains OPEN: native Elementor per-card creation and JetEngine processing still cost hundreds of milliseconds. Full close requires further source-level redesign and measured price/filter/locale safety checks, not broad HTML caching. No Production changes or PR merge.
