# MAD4B Wayfatours first-paint geometry patch — 18 November 2026

Scope: source-backed selective JavaScript layout reads and render-safe site asset loading.
This is NOT a Critical CSS certificate, release attestation, or a guarantee of TBT = 0.

## Exact changes

- City image viewport gate v2.2.1: perform geometry reads before DOM class/attribute writes in scan and IntersectionObserver; reuse slider bounds within each pass.
- Navigation lazy listing v1.2.1: defer the interception drain to a paint frame and pre-read all visible root widgets before invoking JetEngine initialization. Preserve synchronous HEAD interception and JetEngine signed AJAX.
- The existing PHP presentation module is retained as a source-backed Wayfatours overlay; its tested script exclusion prevents the city image gate from joining the large LiteSpeed combined bundle.
- Do not delete navigation.ea09566dff9a.js; old cached HTML may still reference it.
- Do not enable unqualified desktop async CSS, disable Elementor/JetEngine, or cache personalized country/passport state.

## Validation

- python3 tests/test_mainthread_geometry_batch_contract.py
- PHP syntax of site overlay checked via PHP 8.5 in WordPress container.
- Staging EN/ES: new navigation JS loaded, header menu opening populated JetEngine listings without page errors/retry controls; city cards and slider smoke passed on desktop and mobile.
- Production EN/ES desktop: opening menu populated listings without errors/retries. Mobile test confirmed assets load with no errors; navigation menu toggle was not exercised at 390px.
- Production anonymous homepage FastCGI EN/ES HTML variants invalidated via existing generation file mechanism after site changes. Rollback snapshots kept on VPS.

## Measurements (not official Lighthouse scores)

Chromium desktop 1365x768 Long Tasks approximate sums:
- Before city fix: unthrottled ~99ms; CPU 4x slowdown ~1222ms.
- After city fix and unbundling: unthrottled ~72ms; 4x slowdown ~1033–1063ms.
- These runs are variable; no claim that Lighthouse 590ms or its score has been resolved.
- Main remaining candidates: Elementor/jQuery sticky/scroll geometry, large JS bundle startup and desktop render-blocking CSS. Collect source-mapped tasks and certified viewport-specific CSS before broader changes.

## Source and release boundaries

Git PR is for source review only. No bulk merge or automatic site deployment; independent review and release gates remain intact.

## Native UAEL offscreen gallery viewport v1.0.0 — follow-up

- New optional MU owner: 425-mad4b-uael-gallery-viewport-init.php with exact SHA-256-pinned inline JS. Runs only on public anonymous homepage with native jQuery already printed; never replaces Elementor, Slick or Fancybox.
- Intercepts registration of only frontend/element_ready/uael-image-gallery.default. Defers carousel callbacks over 1500px outside the viewport until IntersectionObserver sees the widget approaching. Non-carousel, nearby, invisible, edit mode and unsupported browsers retain native startup.
- Existing Elementor content, WooCommerce, booking, and translation stores remain unchanged. Original frontend hook executes when needed.
- Feature option mad4b_perf_uael_gallery_viewport_v1: off (default for unverified sites), canary (requires cache-bypass and exact header/query), on (anonymous homepage only).
- Disable with wp option update mad4b_perf_uael_gallery_viewport_v1 off; selectively invalidate homepage FastCGI keys to revert cached HTML.

### Verified QA, 18 November 2026

- Chromium A/B with CPU slowdown 4x on Wayfatours: two baseline approximate blocking sums 1095ms and 1110ms; two experimental runs 738ms and 884ms. Not Lighthouse outcomes.
- Production native PHP canary: interception, deferred initialization, native callback on approach, Slick arrows, Fancybox open/close, no JS errors on EN Desktop and ES Mobile. Cross-language runs not presented as equivalent performance comparisons.
- Staging EN/ES/390px: early hook installed, no galleries on those routes, zero intercepted handlers and no JS errors.
- Four source contract tests, Node runtime semantics test and original seven geometry checks pass.

### Remaining boundary

- Elementor nested runReadyTrigger and handler bootstraps occupy largest remaining startup CPU samples. No indiscriminate deferral of menus, price, filters or booking widgets.
- Desktop Critical CSS still uses native blocking stylesheet without a certified viewport-specific replacement. No CSS safety bypass or false readiness claims.

### Public rollout verification — 18 November 2026

- Company mode changed from canary to on for Wayfatours. Generic plugin default stays off for other unverified sites.
- Only FastCGI anonymous EN and ES home generation file mtimes updated; no global asset or commerce cache purge.
- Native public Chrome proof, CPU 4x: EN desktop approximate startup blocking sum 894ms, ES desktop 751ms, EN 390px mobile 1130ms; each loaded the pinned owner, deferred native carousel at first paint, initialized it when scrolled into view, with two native Slick arrows and zero page errors.
- In separate canary interaction tests, EN desktop Fancybox and ES touch-mobile Fancybox opened and closed correctly. Public site remains subject to later actual Lighthouse and field INP measurement; no TBT=0 claim.
- Production rollback snapshot path: /root/mad4b-backups/20261118-uael-gallery-view-port-v100 . The stored site option and core asset were not removed.

### CI nonregression canonical navigation reconciliation

- Unified Source Nonregression failed on the original PR #851 head because build-navigation-asset.py --check compared the new SHA-pinned navigation loader with the **old** canonical source JS. The release-component catalog still listed the former SHA.
- Corrected canonical source bytes to match the immutable navigation.c9315f96239b.js and updated exactly its component catalog path.
- Historical integration evidence is left unchanged; the former immutable hashed asset remains present for cached HTML compatibility. No minifier manifest alteration or site write is introduced by this source correction.
- Verified read-only --check builder, navigation immutability contract, portability catalog, 7 geometry tests and 4 UAEL gallery tests. Build --write is intentionally not invoked because it would prune prior immutable assets that cached HTML can still reference.

## UAEL carousel dependency admission — 18 November follow-up

The existing UAEL viewport MU owner (425) now includes a guarded layout-specific script dependency selector. No new standalone MU plugin remains. The upstream UAEL Image Gallery widget registers Isotope, Justified Gallery, Slick, Fancybox and support scripts for all modes. The verified Wayfatours EN/ES home templates contain exactly one UAEL Carousel gallery and no other UAEL widgets, so only the Isotope and Justified Gallery handles are dequeued at the footer print stage. Slick, Fancybox, imagesLoaded, element-resize, UAEL frontend, jQuery and Elementor continue loading.

Fail-closed admission requires an anonymous public GET homepage, an audited Wayfatours or Tours.dev hostname, one or more observed UAEL Carousel-mode image galleries, no other UAEL widgets, and enrolled native support handles. Mode mad4b_perf_uael_carousel_assets_v1 defaults to off, supports canary with two explicit request headers, and on for the certified public homepage. Other routes and companies retain native dependencies. The prior standalone experimental MU plugin 432 was removed after moving its guards into owner 425; release and rollback files are backed up on the server.

Exact public same-URL results on Wayfatours: old 1,462,169-byte combined JS / 391,661-byte gzip; new 1,405,958-byte JS / 377,066-byte gzip (56,211 bytes fewer decoded, 14,595 bytes fewer gzip). This transfer reduction is deterministic; approximate CPU Long Task measurements remain variable, so the change is not certified as TBT = 0.

Validated real Chrome EN desktop and ES mobile before and after public promotion: UAEL native viewport script still intercepted one carousel and deferred it until scroll. Slick arrows work, next slide changes, Fancybox opens/closes and Escape dismisses it. Mega menu desktop and mobile toggle still work, nine tour cards and Top Cities render, and zero browser JS errors were observed. Canary also passed EN 320px, ES 1024px and EN 1440px responsive checks with no horizontal overflow. Site-specific FastCGI EN/ES homepage generation keys were refreshed and earlier immutable JS remains available for cached HTML. To roll back, set the separate selector option to off and refresh only EN/ES homepage generation keys; do not disable the established UAEL viewport module.

Source tests: test_uael_gallery_dependency_admission_contract.py, test_uael_gallery_source_contract.py, test_mainthread_geometry_batch_contract.py, navigation immutability and portability contracts. No WooCommerce, quote, pricing, Stripe, country/language preference, or CSS certification rules were modified.
