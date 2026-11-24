# MAD4B Compiled Tour Template Family — First Vertical Slice (2026-11-24)

## Problem addressed
Tours.dev and Wayfatours use Elementor Theme Builder 45149, nested Tour Content template 24784. Rates, Fluent Form 6, prices and booking must remain live. Elementor Element Cache for rate-sensitive documents is intentionally disabled, and this release does not change that guard.

The first independent noncommercial slice is Related Destinations widget 6190bab, child template 48366, in family 24784. It contains audited public JetEngine destinations listings. The new first-party MU plugin reuses a per-tour, per-language, per-market and per-revision SSR snapshot on eligible anonymous requests. Elementor still owns the authored design; the original widget is retained for all fallback contexts.

## Source-first live proof
Tours.dev: effective runtime family SHA256 654c8423fab10f7ab97f1d6f9d7b27eaec76a586f714ab0187b266e0cb53d44d, child SHA256 491eb9ee7106f295745e99df11a5a5823adca6b37f055f34a07941e36f5daedd.
Wayfatours: effective runtime family SHA256 b9844d2aac825ee8ddcf085768b396218c6ae596dbb60ed98587c853045e32f1, child hash same. Effective hashes are taken with normal WordPress and WPML plugins active, not using skip-plugins.

Component-only measurements from limited live samples:
- Source Tour 60623: first native render 139.9ms, compiled HIT 25–30ms.
- Source Tour 57562: first native render 749–961ms, compiled HIT 27.7ms. Its HTML is different from Tour 60623 and is never cross-reused.
- Wayfa Tour 60623: first native render 137–274ms, compiled HIT 49ms.

Browser native-vs-compiled HTML/CSS/link parity: source EN/ES mobile390 and desktop1366; Wayfa EN/ES mobile390 and desktop1366. Eight pairs, 16 variants. Exact Related Destinations HTML, stylesheet lists, CSS selectors, links and live commerce visible on all checks. All 44 Mega Menu cards, Form6 fields, price and TOC persisted; no tested JS page errors or asset 4xx. Separate source Tour 57562, six related destination cards and 33 links, passed byte-equal native-vs-compiled checks.

Cold Origin remains several seconds due WordPress/Elementor/live commerce. DO NOT claim overall Origin <1s from this release. Measurements are component-only, not p95.

## Security, partition and revision
Main plugin 453-mad4b-compiled-tour-family-v1.php and widget subclass includes/mad4b-compiled-tour-template-widget-v1.php. No vendor Elementor modifications.
- Default option mad4b_compiled_tour_family_mode_v1=off; canary only with exact X-MAD4B-Compiled-Tour-Canary header; on is explicit after CI acceptance.
- Canonical published public Tour GET only. Editors, logged-in users, previews, REST, AJAX, cron and WP CLI are denied. The Elementor widget registry is substituted only after an eligible Tour route is captured.
- Manifest pins site host, environment, Elementor parent and child IDs and effective SHA256, plus the widget element ID. Any family/template change fails closed until recertification.
- Strict current language and market checks. Unknown request parameters, cookies and untrusted market headers fail to native.
- Snapshot keys include site, Tour ID, language, locale, validated country/currency, device/UA, post meta and modified time, related terms plus meta, Elementor family/listing/CSS revisions, posts/terms generations and destination termmeta epoch.
- Cached HTML must match its SHA and is rejected if it contains script, form, input, nonce, pricing, checkout, payment or customer information; no global rates/Form6 changes.
- On cached HIT, re-enqueue the child Elementor CSS and captured script/style handles. If any asset cannot be safely restored, render native.
- TTL 120s. This is request-time compilation on an eligible first MISS, not a separately running asynchronous warm-up service. Existing page dependency graph and hooks are integrated for invalidation; no global Redis/CSS/JS purge.

## Approval and rollback
Install site-specific manifest JSON as option mad4b_compiled_tour_family_v1. Activate only after exact-head owner attestation and GitHub CI release gates. Roll back immediately by setting mad4b_compiled_tour_family_mode_v1=off; Elementor's original widget remains intact.

Follow-on Template Family slices requiring separate certification: public Hero 24780, itinerary, FAQ, plus independent PHP/bootstrap and admin browser long-task work. Do not disable the rate-sensitive Elementor document cache guard.
