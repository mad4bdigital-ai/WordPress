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
