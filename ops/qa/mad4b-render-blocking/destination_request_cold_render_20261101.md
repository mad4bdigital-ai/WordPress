# First-cold PHP: request-local destination-term reuse

Date: 2026-11-01. Scope: tours.dev.mad4b.com Staging, site UUID e5e64bca-ed62-48d2-b2b3-dff693cd702a. Production unchanged.

## Source evidence and bottleneck

Tour List widget 7739f090 (Listing #25102, Query #5) takes ~500–572ms own PHP; Featured 29f3807b (Listing #14962, Query #22) takes ~208–428ms. Both stay server-rendered with fresh prices, inventory and user-sensitive data. Native JetEngine nested destination widget 30e3360 invokes term link Listing #48562 / Query #182 repeatedly. The same destination's original Elementor HTML SHA-256 was identical across parent tours:
- EN: 13 native render calls, 4 distinct destination terms (9 duplicates).
- FR: 13 calls, 4 distinct terms (9 duplicates).
- DE: 14 calls, 4 distinct terms (10 duplicates).
- AR/ES/IT: zero #48562 renders, unaffected.

## Implementation

New Staging MU plugin: wp-content/mu-plugins/328-mad4b-destination-terms-request-memo.php.
Source SHA256: 35a42f5a0dacaa98b77162a9e1b711ac11883e52c144b6f531ddd6bfb8a06aee.

The original JetEngine renderer creates the first native link for a destination, and its output is reused for the same term only within the same PHP request. No Redis, transients, file cache, database update, tour-card HTML cache or price cache. The original outer JetEngine flow continues to run; Elementor template CSS is still enqueued on reuse.

Fail-closed scope: exact staging environment and UUID/hostname, six exact WPML archive routes, published source #48562 hash/size, verified original/MU323 filter callback, anonymous GET without cookie/auth/query/geo/currency/editor/AJAX/REST/preview, USD currency. Key includes locale, device, term ID and taxonomy ID, name, slug, description, parent/count and all term metadata. Non-target listings (including Featured) stay native. A request may force native behavior using X-MAD4B-DEST-MEMO-CONTROL: native-v1.

## Isolated component results from live Staging

Temporary read-only profiler, CSS cache disabled in both modes, measured native vs memo operation:

| Locale | Native term render | Memo render | PHP component time saved |
| --- | ---: | ---: | ---: |
| EN | 114.49ms | 21.97ms | 92.52ms |
| FR | 75.97ms | 28.25ms | 47.72ms |
| DE | 122.67ms | 23.67ms | 99.00ms |

The reductions apply within the very first origin request; they are not dependent on warming a cross-request cache. The profiler was removed.

One preliminary three-pair English whole-page A/B sample with CSS cache bypass showed native median 3.499s vs request-memo median 3.376s. Improvement appeared in 2/3 pairs. The sample is small and noisy; do not generalize to guaranteed per-request TTFB or p75/p95.

## Browser and test acceptance

Actual Chromium 1440px desktop, EN/FR/DE/AR/ES/IT native vs optimized (12 HTTP 200): identical Tour/Featured IDs and order, identical rendered price texts, links, CSS count/style count, script fingerprints and normalized card text. No observed page JS errors. Elementor ephemeral elementor-dcss numeric selector IDs vary between every request and were excluded from text comparison. PHP source contract tests verify the repeat-hit semantics, term/meta/locale/device segregation, non-target dynamic pricing and no persistent HTML writes.

Evidence directory: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/destination-render-memo-20261101/
Rollback there: rollback-destination-memo.sh plan|apply (plan verifies exact Staging URL/environment/UUID and current checksum, apply removes only MU328).

The separately supplied PageSpeed report lists around 2,360ms estimated render-blocking network improvement and 697KiB of possible cache-lifetime savings. These are browser/LCP networking opportunities, not measured origin PHP TTFB improvements. Full multi-second cold origin render remains open and should not be presented as solved.
