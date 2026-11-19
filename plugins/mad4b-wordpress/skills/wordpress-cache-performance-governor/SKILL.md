---
name: wordpress-cache-performance-governor
description: Govern MAD4B's company-scoped FastCGI and browser cache, trusted cookie/device/locale partition, content-generation invalidation, HTML prewarming, Redis/object cache and Critical CSS performance safely.
---

# MAD4B Adaptive Cache Performance Governor

Use this Skill automatically when a MAD4B company needs cache optimization, unexplained MISS/BYPASS, mobile performance, preload/warmer tuning, or cache coherence. The Skill is source-managed and company-neutral: discover the actual enrolled site, current source bundle, WPML locales, allowed public routes and runtime capabilities rather than relying on an operation list or fixed company names. This Skill grants no mutation, credentials, OAuth, release or execution rights.

## Separation of the five cache owners

1. The trusted edge decides mobile/desktop, canonical URL, locale, content generation, and known-benign cookie partitions. Never include raw marketing cookie values in keys.
2. WordPress decides whether rendered HTML is actually public and market-neutral. A public Page route alone is not permission to cache it: validate WPML, WCML effective currency, pricing and user session. Respect Cache-Control private/no-store, Set-Cookie and WooCommerce opt-out.
3. LiteSpeed optimizes its CSS and issues content-change purge events. MAD4B's exact-hash Critical CSS + single Async CSS remainder are one first-paint owner, with the inline fallback loader. Keep global LiteSpeed Async and UCSS independent.
4. Scoped FastCGI generation markers version only changed public paths. Do not suppress an actual content-change purge to improve HIT statistics. Root language-redirect suppression flags need their own cache key.
5. Redis handles transient WordPress objects, not as a substitute for HTML FastCGI. Never Flush All, increase maxmemory past its cgroup ceiling, or change maxmemory/OPcache without admission, rollback and restart lifecycle proof.

## Adaptive warming algorithm

Admit an active, root-owned, per-company policy with exact company ID, safe canonical routes, CSS-verified warmer receipt, registered warmer executable, trusted Nginx container and directory, and bounded TTL. Use tools/cache/mad4b-adaptive-html-cache-warm.py as the common code. Its policy MUST be separate from executable code; do not copy another company's site path, WPML locale or CSS manifest.

Each scheduled pass hashes the exact route-generation marker stat, Nginx config, warmer implementation and Nginx container lifecycle. If the signature is unchanged and last certified warming is younger than refresh_seconds (which must be safely less than FastCGI TTL), skip the request entirely. If content/edge lifecycle changes or TTL is nearing expiry, run the existing company-owned warmer under a single flock, never inline with the public request. Bound load, duration, retry and receipt size. Persist the ledger only after HTTP 200 for every required public variant, CSS proof, no private/BYPASS, and no concurrent generation change.

Track GENErATION_WARMED, UNCHANGED_SKIP, GENERATION_CHANGED_DURING_WARM, DEFERRED_HOST_LOAD and ORIGIN_PRIVATE_VETO independently. A MISS after a legitimate publish is expected; repeated MISS for one stable generation is a potential bug. A warm worker must not alter the pricing context to manufacture public eligibility.

## Dynamic cookie and variant admission

Treat exact nonpersonal analytics/Sourcebuster, URL-consistent WPML language and the one-time locale_seen flag as safe only when WordPress and edge contracts agree. Deny unknown cookies, mixed currency, WooCommerce sessions, logins, orders, payments, favourites, nonce and sensitive market headers. One-time root redirect flag changes the response identity: partition the root key. Each business site supplies its own finite device/locale/redirect class warm manifest. Do not add an unbounded cookie-value Vary explosion.

## Derived metadata, first-wave mobile primer, and HIT continuity

Source-backed diagnosis from Wayfatours: repeated FastCGI generation bumps came from the existing Cache Lifecycle authority processing derived Elementor post meta writes, not from missing warm jobs. Cache Lifecycle v1.0.5 now ignores exact known non-rendering _elementor_controls_usage and _elementor_element_cache while preserving content-edit, page/document, real taxonomy, prices and publication invalidation. Do not use a broad wildcard for Elementor fields or skip unknown meta. Reuse the original installed plugin; the patch in tools/cache/patches is an exact-base delta, NOT a parallel implementation.

The Mobile Cache Primer in tools/cache/mad4b-mobile-cache-prime-v1.py is a bounded first wave for a small per-company list of anonymous/locale safe mobile variants. It runs under the existing full warm service flock; the existing Adaptive Cache Warmer still owns all 14 Wayfatours classes. On generation changes, confirm 200, one Critical CSS + async rest + inline loader, explicit trusted WordPress public-page proof, and a persisted origin FastCGI HIT. If content changes midflight or a second request is still MISS, leave readiness unproven and retry later. Stable generation checks produce no HTTP traffic.

Benign-cookie browser Cache-Control private is acceptable only when the WordPress origin positively certifies a public HTML response, Nginx actually serves a safe partitioned FastCGI HIT, and no no-store, Set-Cookie, session, cart or personalization is present. An origin-private currency/market veto must never be overridden. Host-level inotify watchers may exhaust available instances; use a low-cost, bounded generation-signature timer when a new systemd.path is not admitted rather than adjusting host-wide limits.

The 92/100 Wayfatours mobile Lighthouse screenshot on 2026-11-12 was taken with warm cache; this is a historical data point, not a universal guaranteed score. It requires full browser A/B evidence for performance release decisions.

## Returning-browser representation keys, CSS-ready proof and Docker mounts (v5)

For a published, server-certified anonymous mobile page, analytics identifier values are not independent HTML representations. A normal browser carrying only approved Sourcebuster/Google tracking identifiers or WPML language matching its URL should reuse the canonical device+route+generation key, NOT create per-analytics-value or locale-presence cold variants. The mad4b_locale_seen root redirect flag may change the response and must be partitioned. Currency, country, market, favourites, login, checkout, Woo session and conflicting language always BYPASS shared HTML: never relax private data protections just to force HIT.

Before accepting a mobile homepage FastCGI fill, require proof from the FINAL LiteSpeed buffer after async loader injection: exactly one substantial Critical CSS, one rest CSS link, one inline loader, and upstream X-MAD4B-Mobile-CSS-Ready: 1. Missing or zero proof implies FastCGI no-cache and private no-store; invalid HTML must never become a cached HIT. A previously cached HIT cannot itself prove the render is complete: verify bytes and selectively quarantine corrupt home objects. Keep this specific guard on approved mobile home routes, not unrelated pages or desktops. Reuse the original Critical CSS and Nginx authorities.

Docker single-file read-only bind mounts (host nginx.conf -> container /etc/nginx/conf.d/default.conf) do NOT follow host atomic rename. A successful nginx -s reload may reload the old inode while the host path has new bytes. Verify host/container SHAs and inode identity, write in place through tools/cache/mad4b-mounted-nginx-config.py using explicit expected old SHA and --apply, nginx -t and live readback. Fail closed on mismatch. Restart only with a verified tmpfs boot procedure and a cache backup; a nested tmpfs temp directory must not be assumed to survive restart. Wayfa uses client_body_temp_path /var/cache/nginx/client_temp. Only fresh, CSS-certified objects may be restored.

See docs/MOBILE-NORMAL-BROWSER-CACHE-KEY-V5.md. Its per-site PHP patches are independent exact-base deltas; never overwrite the newer source provider with a Wayfa version. The Wayfa Nginx patch is host-specific, not a universal static site list. A local patch or Git commit is NOT an Original313 company release receipt.

## Lighthouse mobile score volatility — verify before optimization

When PageSpeed Insights Mobile score falls despite verified FastCGI HIT, compare complete historical Lighthouse JSON (same URL/device/version) before attributing a regression to new code. Use tools/cache/compare-pagespeed-mobile-runs.py: compare JS/CSS/image asset URLs, byte totals, host benchmarkIndex, TTFB, actual AJAX HTTP request duration vs modeled critical request chain, and LCP resource versus element render delay. Unchanged files and request counts are important negative causal evidence; a lower benchmarkIndex may show test environment variability but does not excuse bad LCP or prove sole cause.

Wayfatours 2026-11-12: Mobile PSI 92 at benchmarkIndex 1301 vs 63 at 996 (-23.4%) had identical hero, CSS and 334KiB JS asset; 47 requests/1642KiB in both; real visitor-state network duration 350ms vs 303ms; total script evaluation ~885ms vs 881ms; TTFB 35ms vs 52ms. The important difference was hero element render delay 171ms vs 2028ms. Attribution remains INCONCLUSIVE_LAB_VARIANCE_AND_RENDER_DELAY. Never disable the Elementor/JetEngine JS bundle or publicly cache visitor preference data to chase one low Lighthouse run.

Use at least three matched mobile network/CPU runs, inspect median FCP/LCP and variance, and require functionality proof for header WPML/country/passport, popup, date filter, itinerary and booking before promoting CSS/JS optimization. Existing critical CSS + inline loader + safe-cookie FastCGI must stay intact. Review docs/WAYFA-MOBILE-PSI-63-ROOT-CAUSE-20261113.md.

## Native Elementor below-fold background policy v2

Use the ORIGINAL MAD4B 412-mad4b-lazy-jet-backgrounds.php module (v1.1.0) for lazy mobile backgrounds, not a separate image renderer or widget. Preserve existing JetEngine-card lazy loading. Each company enrolls typed Elementor IDs + one mode per offscreen section (self, before, after, cta-bg) in mad4b_perf_home_section_background_policy_v2, at most 16 entries. The controller validates identifiers, scopes to public homepage and mobile only, emits an early CSS/JS IntersectionObserver gate and restores original Elementor backgrounds near viewport (450px). No-JS, desktop, admin and non-home fail open. Never enroll hero LCP or unverified above-fold widgets; do not rewrite Elementor templates, dynamic tags, WPML links or booking.

Wayfatours live 2026-11-13: EN/ES first mobile open each went from 18 images (~658KB) to 15 (~307KB), saving precisely 350,702 transfer bytes (53.3%), by withholding 3 original offscreen Elementor backgrounds. They reappear on scroll, no JS errors, mobile critical/rest/inline CSS contract unchanged, desktop untouched. Scoped Cache Lifecycle home invalidation and original mobile primer returned HIT. This is a measured transfer and functional result, not a new Google PSI score.

The existing LiteSpeed 334KB JS bundle and early native navigation bootstrap remain unmodified because the signed JetEngine listing interceptor must load before widget initialization. Additional JS/CSS per-widget pruning needs separate coverage and EN/ES interaction tests, not universal defer/unload. See docs/MOBILE-SECTION-BACKGROUNDS-LAZY-V2.md for typed policy, SHA patches, rollback and evidence.

Validated six-run matched browser comparison on Wayfatours V2: the median mobile LCP fell from 3772ms (unmodified page) to 3240ms (typed lazy sections), 532ms or 14.1% faster, with FCP improving from 1388 to 1296ms and the same hero LCP. This is not a Google PSI score. Keep original Critical CSS, desktop and no-JS fail-open, and require the same repeatable measurement before rolling out a new company policy.

## Native widget-owned optional JavaScript admission

Reuse the ORIGINAL Elementor/JetEngine/UAEL widget owner, not a second JS composer. Native optional scripts may be omitted on an approved public homepage only when Elementor native before_render did not show the widget. Keep original WP handle and vendor source, dequeue only the optional script before footer print, and install a small detection fallback to load the original script if compatible widget markup is added later by AJAX or an Elementor Popup. If a widget was rendered, on nonhome/preview/admin, or when tenant policy is off, preserve original enqueuing. No static Elementor IDs, no changes to WPML, country selection or booking.

Wayfatours 2026-11-13 v0.2.0 of original 345-mad4b-shared-social-rail.php: opt-in mad4b_perf_home_social_rail_on_demand_v1 on. EN/ES original homepage has no Jet Dropbar, so optional 8,393-byte JS stays off until a native widget is inserted. PHP 12/12, live EN/ES plus nonhome 3/3, dynamic share links, clicks and ARIA passed. Original cache primer returns HIT and CSS-ready. A small six-run frozen-HTML study found median LCP 3260 -> 3128ms, but this is not PSI proof; saved about 2.2KiB initial compressed transfer and an independent request. Original LiteSpeed 334KiB combined JS is NOT altered.

V8 first-screen coverage of combined JS ~25 percent used, CSS bundle ~14 percent used. DO NOT prune unexecuted JS or offscreen CSS without complete native widget dependency/interaction coverage (home, tour, archive, popup, bookings, languages, desktop/mobile). Document: docs/MOBILE-NATIVE-WIDGET-JS-ADMISSION-V1.md. Exact-base patch is for the company provider, not an Original313 signed fleet release.

## Native Fluent Forms phone input script on-demand

Use the existing company MU provider 348-mad4b-fluent-phone-brand-and-dial.php (v0.2.0) as the optional script-admission authority, not a new phone or intl-tel-input renderer. Company option mad4b_perf_home_phone_helper_on_demand_v1 defaults off. For verified public homepage only, once the original WP handle is enqueued, the owner may dequeue its helper before footer script print and emit a tiny bootstrap. On native .fluentform input[type=tel] already present or dynamically inserted, load unchanged original script exactly once. Fluent Forms and intl-tel-input retain ownership of country metadata, phone validation and serialization. Nonhome, off-policy, admin and preview retain native eager behavior; no form-ID lists and no critical/rest CSS edits.

Wayfatours EN/ES home: originally enqueued 3,813-byte helper (~1,558 gzip) despite no phone input. New typed opt-in holds script until a genuine input exists. PHP tests 12/12; EN/ES browser canaries verified +44 switches country to GB while preserving input; LIVE home EN/ES + Contact Us and a real tour 4/4 PASS with no errors; scoped cache re-prime returned CSS-Ready and HIT. Tours.dev lacks this Wayfatours-only portable provider: never copy it to source just to create false fleet parity.

Keep jquery-chosen (declared dependency of Jet Search) and Swiper (visible city cards); do not trim the ~334KB bundled LiteSpeed JS or ~2.2MB CSS by unused-byte percentages. For new modules follow actual owner/widget dependency graph, matched interactive QA, then company-specific opt-in. See docs/MOBILE-FLUENT-PHONE-ON-DEMAND-V1.md.

## Native CSS admission and dynamic Elementor widget restoration

Use only the ORIGINAL MAD4B CSS Demand Gate v0.3.1, not a parallel CSS loader. A native style excluded from the initial mobile homepage must be restorable when its real native Elementor widget arrives later by AJAX, Popup or DOM insertion. First-paint exclusion remains permitted ONLY with company identity, public-cookie safety, WPML/route/mobile scope, current Adaptive Presentation Discovery and CSS epoch, original registered WP stylesheet hash, transitive dependency checks and exact certified Critical CSS bundle SHA. Invalid evidence means keep the original stylesheet on the page.

On approval, dynamically derive the widget names from Elementor widget manager and ReflectionClass source under the SAME plugin owning the original CSS handle. Do not hardcode widget or company IDs. If the native owner is unknown, do not remove the style. Emit an early no-optimize MutationObserver, load the original versioned same-origin HTTPS CSS exactly once on native widget insertion, and include a no-JS CSS fallback. Preserve the source stylesheet, Elementor templates and Critical CSS.

Wayfatours 2026-11-13: JetBlog CSS admission saved 11,239 gzip bytes EN, 11,208 ES. The original six JetBlog widget types were identified from native registration. EN+ES LIVE browser checks confirmed zero initial JetBlog stylesheet requests, one request when a Smart Listing widget is inserted via DOM, computed flex/wrap styling restored, no extra request for a second widget, no JS errors, HIT and CSS-Ready:1. JavaScript-disabled browser retained native CSS via noscript. 13 WordPress owner/safety tests PASS incl cross-origin refusal. This is production functionality proof, not a new PSI score.

Do not claim automatic restoration for arbitrary custom AJAX markup lacking the native widget wrapper. That requires a separately verified native asset owner. See docs/MOBILE-NATIVE-CSS-DYNAMIC-RESTORATION-V031.md. The local exact-hash canonical source plus replay patch are not a signed Original313 fleet release. Verify `tools/cache/native-css-admission-v031/source-manifest.json` and run `tools/cache/test_native_css_release_source.py` before submitting through the original signed CAS lifecycle; never auto-enable on other companies.

## Capacity and pressure

Read real Redis evictions, cgroup memory, current client counts and OPcache free_pct. If a staging Redis container is near its cgroup ceiling, mark CAPACITY_AT_RISK and keep HTML sharing fail-closed; don't simply increase Redis maxmemory under the same limit. Use CPU/load and WP-CLI admission so warming cannot starve PHP-FPM, especially when source TTFB is cold or pricing is personalized. Prefer fewer, evidence-driven warm jobs over per-minute broad HTML rendering.

## Verification and release

Check PHP and systemd syntax, isolated governor tests, Nginx config admission (for changed maps), real Android browser reload with safe cookies, cache-status headers, CSS proof and transaction BYPASS. Scope cache-key updates and rollback to the exact company, path and active generation; record before/after SHA and state receipts. Explicit Original 313 release remains separate: a host patch, Skill, Git commit or test is not a signed company rollout.

On 2026-11-12 Wayfatours Production received an adaptive warm service, 14 finite verified variants, 14/14 isolated governor tests, the first generation-warmed receipt and a subsequent unchanged-skip. Tours.dev is not admitted to shared FastCGI HTML: effective WCML EUR vs configured USD still causes a correct origin veto. The example policy is deliberately disabled until company-specific verification.
