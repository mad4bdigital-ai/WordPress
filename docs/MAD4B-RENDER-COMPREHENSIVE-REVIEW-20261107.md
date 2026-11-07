# MAD4B Tours.dev — Comprehensive Mobile Render Acceptance Review

Checkpoint: 2026-11-07, Tours.dev **Staging only**.
PRs: #770 (server render), #769 (JetBlog mobile AJAX).
**Verdict: PARTIAL ACCEPTANCE.** Brand request memo visually passes, content/Fragment state remains safe, but full performance/SLO, six-language real-browser interaction and GitHub Actions are not certified.

## Evidence and bottlenecks
- Mobile DevTools captures supplied by operator recorded main HTML requests 53.89s Home, 15.63s Tour Archive, 34.77s Tour Detail. Those were single captures under uncontrolled VPS load.
- Host has 8 vCPUs. During fresh QA, load average repeatedly exceeded 10–19, several Chromium and WP-CLI sessions overlapped, and PHP-FPM emitted max_children=6 warnings.
- Of the last 150 overlapping PHP slowlog snapshots: 106 touched Elementor render, 32 WPML, 28 Elementor CSS. These numbers are NOT exclusive and must not be turned into percentages.
- A specific repeated hotspot: Company_Brand_Context_v1::company_brand_context() hashes the full Look & Feel options and media bundles per Elementor element. Slowlog captured repeated json_encode()/hash() in this path.

## New Staging optimization — Brand Request Memo
Path: wp-content/plugins/mad4b-platform/modules/operations/company-brand-context.php

- For anonymous Staging GET only, resolve once per blog, language and in-request settings revision; return a request-local result on further calls. Production, admin, logged-in, signed previews, AJAX, REST and WP-CLI still follow original path.
- Relevant WordPress option and Elementor Kit updates invalidate the request memo. Signed preview parameter presence bypasses it. No persistent cache, no cross-language or cross-tenant shared HTML, and no WooCommerce rate/quote changes.
- Staging default ON after screenshot/DOM parity. Rollback via WordPress option mad4b_brand_request_memo_v1=off, or QA-only ?mad4b_brand_memo=0 to force original path for that request.
- Anonymous live Home Canary: 1 context build and 318 reused calls. Archive Canary: 1 + 418. Tour Detail Canary: 1 + 639. This is directly measured eliminated repeated work; does not by itself prove an end-to-end TTFB improvement.
- PHP fixture 8/8 passes: same context, avoided re-read, WPML locale partition, blog/company partition, option-update invalidation, signed-preview skip, admin native path.

## Real browser mobile parity
Playwright Chromium, Pixel 10 emulation 412x924, mobile/touch, JavaScript and network enabled, browser screenshot 824x1848. Tests used the same X-MAD4B-Bypass-Cache header and only varied ?mad4b_brand_memo=0 vs =1.
- Home: title, H1, 111 listing elements, 2 Smart Listings, one filter group, 40 images and zero broken images. 13/13 selected DOM, CSS and WPML link features matched. Screenshot PNG SHA256 is identical across control/Canary.
- Tours Archive: H1, 84 listing elements, 15 filter elements, zero broken images. 12/12 selected DOM/CSS/link features matched, screenshot PNG identical.
- Tour Detail: an initial native capture returned HTTP 200 and showed 28 listing elements. A repeated native navigation exceeded 68s and overwrote its JSON result, but the preserved native screenshot PNG and Canary screenshot PNG have identical SHA256. Canary capture HTTP 200, 28 listing elements, 38 images, zero broken images and no JS errors. Do not claim fully repeatable control navigation.
- Actual TTFB samples are NOT a controlled, statistically sufficient AB/BA comparison. Home native 25.4s vs Canary 16.8s; archive native 17.8s vs Canary 12.3s; detail successful native 51.0s vs Canary 53.1s, with another native browser timeout. Host load differed. **No approved p75 TTFB or Core Web Vitals reduction claim**.

## WPML and filters
- DB confirms published translation sets for EN/AR/FR/DE/ES/IT for front page 35138 and tour 60629.
- Public Home EN and AR returned HTTP 200. AR HTML dir=rtl, 7 correct alternate hreflang entries. FR first mobile fetch timed out at 21s, while a later warmed desktop request returned HTTP 200 / FastCGI HIT at ~0.02s; another locale requested as MISS showed ~10.8s origin TTFB. This is not full real-browser parity for six languages.
- Nginx homepage locale cache already has background_update+use_stale updating and 30s TTL. It is not absent; cold and missing microcache entries still require slow PHP generation. The query/device/currency/cookie/auth bypass rules were left unchanged.
- The mobile Tours Archive Filter drawer was opened successfully. H1 remained and 84 listing elements stayed rendered. The real JetSmartFilters sorting control was set to "By Recommended"; the resulting admin-ajax.php request returned HTTP 200, and the 84 card containers/H1 remained. This **proves the sorting AJAX interaction completed**, not the correctness of each order. No visible checkbox was available to automate a destination/category filter, so full filter predicate/result parity remains UNVERIFIED.
- The separate JetBlog responsive offscreen initial AJAX patch remains present; the current browser harness strips query strings from captured request URLs, therefore its "jetblog_xhr_count=0" must not be treated as proof the widget did or did not send a POST.

## Existing PR #770 live checks
- Query Governor v1.2.5, public destination Fragment Cache TTL=300 (runtime WP-CLI confirmed previously), per-language/company keys; generated Elementor CSS timestamp is not a content mutation.
- Read-only destination ancestry memo, opt-in-only dynamic-static CSS bypass (0/114 compatible static candidates on sampled Home), and optional two-task warmup ExecCondition admission have prior PHP/JSON/Python fixture PASS.
- Original Symfony/WordPress Broker 305/313, Production, rates and booking unchanged.
- GitHub PR #770 contains the local CI workflow; GitHub currently exposes no Check Runs for this PR head. Connected GitHub app has no workflow-dispatch action; GitHub Actions execution cannot be claimed. The exact workflow tests are run directly in PHP/Python in the VPS container.

## Remaining acceptance gates
1. Obtain stable, noncontended server slots; run repeated cache-controlled Lighthouse or browser traces for Home/Archive/Tour and separate LCP/CLS/INP, at least 3 cold/warm samples per page and compare p50/p75.
2. Sorting AJAX completed with HTTP 200; still verify the resulting order and perform a non-sorting JetSmartFilters predicate selection in EN/AR/FR without stale result/locale leakage.
3. Test translated FR/DE/ES/IT URLs and Arabic RTL interactions in a real browser; SQL linkage alone does not prove frontend parity.
4. Resolve repeated live PHP-FPM max_children saturation and native Elementor/WPML render slowness. New 30s FastCGI MISS paths can still take 10–50+ seconds. Do not increase worker count or globally lengthen cache without controlled load and invalidation proof.
5. Trigger GitHub Actions through an authorized Actions-write path and review results. Keep PR Draft and Production unchanged.

All screenshots, JSON evidence, source backups and control scripts are retained under /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/render-comprehensive-20261107 on the VPS.
