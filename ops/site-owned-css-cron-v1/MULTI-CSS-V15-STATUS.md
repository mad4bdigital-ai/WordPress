# Multi-CSS source admission — renderer v15 (non-publishing)

This is an incremental enhancement of the existing 421/422 source-owned
public CSS pipeline. It does not introduce another public certificate issuer.

The v15 renderer is a separately pinned 124-file artifact. The v14
rebuild/manifest are unchanged. The v15 rebuild selects the original
run-public-route.py rather than the historical v10 override; otherwise
the compiler observer would not run.

## Current phases

1. WordPress WP-CLI catalog selects one authenticated site route. Home remains
   under its own independent certificate controller.
2. Existing certificate resumes first, only after original exact
   WordPress/CSS/currency and source integrity checks.
3. The source-only observer reads anonymous first-party HTML for that route,
   requires server-owned WCML market/currency, and records CSS document order.
4. The dependency compiler pins file bytes and immutable cascade slots,
   preserving inline style boundaries, media queries, UTF-8, resource URLs,
   and same-company root paths. Unsafe sources or unsupported @imports fail closed.
5. More than one CSS file gets queue HOLD multi_css_route_qa_pending. The
   legacy single-bundle capture cannot publish READY while CSS is unmeasured.
   An inspection failure also keeps original CSS with a bounded HOLD.
6. Unchanged single-source routes retain the existing QA/certification path.
   Previously certified routes may resume by existing independent evidence.
7. Compiled chunks are stored in private reports. No visitor-time compile,
   FastCGI purge, 415/421/422 mutation, cross-route CSS transfer or promotion.

## Observed evidence — 20 November 2026

- Wayfatours Dolphin House tour: 5 CSS files, 2 cascade slots,
  5/5 Chromium layout parity at 320, 390, 414, 767, 768px.
- Tours.dev Arabic tour, context AR / DE / EUR: 99 CSS files,
  18 cascade slots, 5/5 Chromium layout parity.
- This is layout parity, not complete FCP/LCP/CLS or normal Chrome-cookie
  FastCGI MISS/HIT certification.

## Release boundary

Renderer v15 is a candidate only, not deployed. CI and production readback
must still validate resource budgets, market isolation, HTML cache delivery,
and CSS optimization quality. The source-only observer is not a public
Critical CSS certificate issuer. Multi-CSS async delivery and FastCGI
rollout are separately governed.
