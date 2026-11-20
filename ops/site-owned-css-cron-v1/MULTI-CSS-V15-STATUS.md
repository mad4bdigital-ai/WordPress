# First-party multi-source CSS — renderer v15

This extends the existing 421/422 CSS discovery/certificate workflow.
Renderer v14 is independently rebuildable from the original 119-file pinned
source snapshot; v15 has 129 pinned files. Neither version is implicitly
installed by the build scripts.

## Implemented

- Ordered stylesheet discovery from an exact first-party WordPress catalog route;
  HTML source SHA, trusted server WCML currency/market, CSS SHA, media rules,
  and ordered slots around inline styles are captured.
- Offline local CSS compile and immutable per-slot content-addressed assets,
  preserving CSS cascade boundaries; cross-tenant file sources, forged URLs,
  imports and unsupported syntax fail closed.
- Chromium per-slot CSS coverage at 320/390/414/768px, reusing the existing
  assemble.js CSS used-rule selector. Browser-side unit tests and original
  source integrity checks continue to bind every output.
- Browser layout parity and five-viewport Critical CSS FCP/LCP/CLS/interaction
  tests using original live CSS URLs, not only synthetic CSS URLs.
- Original public route runner can invoke these tools automatically under the
  established shared CPU/Chromium lock using MAD4B_MULTI_CSS_SOURCE_QA=1.
  That opt-in controls offline QA ONLY, and is OFF by default in existing
  site services. No new production-firing cron was created.
- public-ordered-critical-admission.py pins original HTML, candidate SHA,
  source manifest, market context, true native-URL five-viewport performance
  evidence, and critical CSS asset bytes before issuing review-only status.
- Existing certified single-bundle routes are preserved; new multi-source
  routes are multi_css_route_qa_pending, never prematurely declared READY.

## Observations — 20 November 2026

- Wayfatours Dolphin House: 5 original CSS links, 2 cascade slots, 615,020
  bytes of first-pass Critical CSS. Chromium layout parity: 5/5 at widths
  320, 390, 414, 767 and 768. True original-URL network QA passed 5/5.
  FCP improvements on the 4 mobile widths: 2704, 2648, 2744, 2644 ms.
  LCP improvements: 1776, 1684, 1816, 1788 ms. The 768px original-CSS
  side showed measurement noise: FCP -52 ms, LCP -68 ms.
- Tours.dev Arabic tour in AR/DE/EUR context: 99 original CSS links and 18
  cascade slots. Original source parity 5/5. Chromium extracted 251,045
  bytes of critical CSS across all 18 slots. One real-original-URL smoke
  check at 390px showed FCP +2220 ms but LCP regression +4984 ms.
  This remains a HOLD, not a five-viewport performance approval. The
  99 async CSS links need additional LCP optimization before promotion.
- Earlier CSS route.fulfill() QA returned weak FCP improvement; that
  measurement is kept as diagnostic, NOT used as release authority.
  The real original-URL test is a controlled Chromium sample, not a
  population Core Web Vitals guarantee.

## Explicit release boundary

This is still source qualification only. It has NOT installed a new
multi-source visitor-side 422 consumer, emitted a v2 public CSS certificate,
published any new CSS to the public WordPress asset tree, modified FastCGI
cache keys, or deployed the new renderer to live cron. Full visitor
HTML delivery, normal Chrome with legitimate preference cookies, and
MISS/HIT cache readback remain unproven. A route may not become READY on
the basis of compiler, snapshot parity, or FCP evidence alone.

Original pinned v14 remains reconstructable, and any deployed v15
observation is fail-closed to native CSS on insufficient evidence.
