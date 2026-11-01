# Tour List and Featured: measured Staging CSS-only render optimization

Date: 2026-11-01
Scope: https://tours.dev.mad4b.com, WordPress Staging only. Production unchanged.

## Identified hot path

Live JetEngine render profile across all six WPML Tours archives confirmed that Tour List widget 7739f090 (listing #25102, Query #5) and Featured widget 29f3807b (listing #14962, Query #22) dominate first-origin work. Typical Tour List own time was 500-572ms, Featured own time 208-428ms, in overall 3.03-3.83s origin samples. SQL execution in a representative 3.64s request was 235ms: generic SQL tuning is insufficient.

Existing source-bound MU #324 accelerates native Elementor/JetEngine dynamic CSS generation while ALWAYS appending fresh per-request card HTML and pricing. Previous CSS-only trace found about 202-234ms CPU work for Tour List CSS and 124-147ms for Featured CSS when uncached. The existing lifetime was 35 seconds.

## Applied Staging change

File: wp-content/mu-plugins/324-mad4b-priceless-dynamic-css-cache.php, version 1.1.0.

- CSS-only object-cache TTL extended from 35 to 180 seconds in a new isolated cache group. It stores only a canonical CSS style prefix with the current Elementor dynamic selector replaced by a placeholder.
- Every request still calls the original Elementor/JetEngine card renderer and reuses its newly generated HTML. No price labels, pricing calculations, cart, inventory, nationality, cookie, session, WooCommerce FX values, query results, or visitor HTML are stored.
- Cache key is pinned to exact Staging site, each of six WPML source-template digests, listing ID, post ID and modification date, the SHA256 of that tour's entire postmeta, language and device family.
- To bound stale appearance when global design changes, key also hashes current Elementor Kit page settings, Kit data and modification time, Elementor version, CSS print method and theme version.
- When the same tour is in both List and Featured, its source meta fingerprint is calculated only once per PHP request. The output remains separately rendered for each listing identity and price context.
- Guest GET only, exact archive routes, default USD and no cookies, auth, query string, geo/currency overrides, previews or admin requests. Unsupported source or changed Elementor template hash fails closed.
- One-request native bypass: HTTP request header X-MAD4B-PRICELESS-CSS-CONTROL: native-v1. Full rollback shell is available under Staging QA and restores the exact original MU #324 file. No global configuration change was required.

## Acceptance

Real Chromium/Playwright for EN, FR, DE, AR, ES and IT on desktop: all 6 paths HTTP 200, zero observed page JS errors, identical first five listing-card CSS geometry, exactly equal live price texts, same CSS link counts and same dynamic style tag counts comparing bypass versus CSS-only v2.

Six alternating-order paired no-page-cache origin measurements on English Tours archive (native versus cached v2, seconds):

| Pair | Native | Cached v2 |
| --- | ---: | ---: |
| 1 | 4.041 | 3.299 |
| 2 | 3.385 | 3.116 |
| 3 | 3.307 | 3.171 |
| 4 | 3.524 | 3.090 |
| 5 | 3.664 | 3.040 |
| 6 | 4.070 | 3.274 |

Native median 3.594s, cached median 3.143s; observed 0.451s / 12.5% lower median, with six of six pairs faster. The sample is small and warm-cache; it is NOT a guaranteed first fully cold hit improvement or user-p75/p95 change. Actual generation of 20+ dynamic cards still costs seconds.

## Rejected interventions (remain disabled)

- Query #22 post-record cache for 20 seconds, with WPML-isolated groups: six alternating A/B pairs native median 2.89715s versus optimized 2.89756s and only 3/6 faster. No demonstrated gain. Trial MU #390 removed.
- Native JetEngine Featured lazy-load: looked faster in EN/AR TTFB but the Featured region begins at y about 596px within the first 900px viewport. Initial SSR omitted all 12 Featured cards, and the later AJAX introduced 8 extra CSS links. High risk for first paint, SEO and layout shifts despite eventually matching posts/prices, so trial MU #391 removed. Tour List stays server-rendered and Featured stays fully server-rendered.

Isolated source tests check CSS-only output, request ID regeneration, fresh dynamic price HTML, pinned 6-locale scope, Elementor Kit invalidation and 180-second TTL.

## Evidence and rollback

Staging VPS:
- QA samples and browser JSON: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/priceless-css-v2-20261101/
- Query experiment: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/query22-cache-trial-20261101/
- Lazy-load experiment: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/featured-lazy-trial-20261101/
- Safe rollback: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/priceless-css-v2-20261101/rollback-priceless-css-v2.sh plan|apply. Plan verified and not executed.

This is a proven short-lived CSS work reduction for normal guest rendering, not full closure of truly cold PHP TTFB. Eliminating the remaining first-cold Elementor/JetEngine per-item render overhead requires a deeper native renderer design and independent price, filter, WPML, CSS and SEO acceptance; it has not been changed in this commit.
