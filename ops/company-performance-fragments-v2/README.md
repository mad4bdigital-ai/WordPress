# Company Performance Fragments v2 — Staging-only review candidate

Generic WordPress MU-plugin components for per-company frontend Performance Presets. Tenant IDs/hostname are external JSON configuration. This is not a Production release.

## Guardrails

- The sample preset is disabled. Replace the company's site_host, language, menu and card Elementor document/widget IDs, JetEngine listing ID and taxonomy in its own staging setup.
- Require Staging, anonymous GET, / path, no query string, no Cookie, no Authorization, no WP admin, REST, AJAX, preview or logged-in identity. Other languages and hostnames do not use the cache.
- This conservative no-cookie rule bypasses return visitors carrying tracking, WPML and WooCommerce cookies; do not weaken it without separate audience, geo, currency, session and privacy verification.
- Memoize Elementor Mega Menu SSR and JetEngine individual attraction card content for only 5–60 seconds (default 20). Injected Fluent Forms slides and product/price/cart content never enter the cache.
- Cache keys include company origin, locale, WPML language, Woo currency and mobile/desktop. Menu entries also use document modification time. Term entries include taxonomy, term ID and both Elementor template modification times.
- JetEngine context (setup_data, object-done, required assets) is replayed on cached item HIT to maintain the correct post/term identity and classes.
- Cacheable markup is screened for dynamic form, nonce, checkout, price, cart and customer markers before writing and reading.
- Local temporary file storage only. Multi-container installations require tenant-isolated shared caching and an explicit invalidation contract.

## Safe installation and tests

1. Install the three PHP files into only the target company's Staging MU plugins directory.
2. Copy preset.example.json to company-performance-fragment-preset.json and set exact tenant values; keep disabled until identity and content checks pass.
3. Use anonymous GET requests for baseline versus warmed requests. The request headers X-MAD4B-Fragment-AB: baseline and X-MAD4B-Card-AB: control temporarily force original-render comparison in Staging.
4. Compare complete card IDs, titles, URLs, count labels, slider slides, menu links/images and interaction on all supported devices and languages. Recheck currency, market and geo conditions.
5. Confirm cookie, auth, query, alternate locale, Cart, Checkout and REST bypass; run php -l, the local contract test and owner-governed CI.
6. No Production or other tenant rollout before the exact source build, release/owner approval, separate tenant QA and rollback readiness.

## Reference Staging evidence (29 October 2026)

- Homepage cold about 4.0–4.5s TTFB, warmed public anonymous request about 2.8–3.3s in limited trials; official Lighthouse/Core Web Vitals and statistically significant speed improvement are not yet certified.
- Browser A/B on 390px and 1360px: element IDs, card titles/counts, links, slides, and menu links/images matched original. 10/10 visible Mega Menu images loaded after menu opening. Some transient external ERR_NETWORK_CHANGED errors required retries.
- Negative HTTP/route cases: 12/12 passed, including tracking/WPML/cart cookie, Authorization, query string, Arabic, Tours Archive, Cart, Checkout and REST. Checkout redirected (HTTP 302), as expected for its state.
- A first experimental JetEngine card cache had a wrong wrapper object identity; repaired by setup_data and strictly limiting to the intended Elementor widget/document. The broken experiment was removed.
- Full HTML Nginx cache is still restricted to a synthetic public test endpoint. No real WP HTML page cache was enabled by this change.

## Rollback

Set enabled=false in each company's JSON, verify HTTP 200, and then remove only these two modules and helper if retiring. No WooCommerce database or global setting rollback required. Previously generated short-lived /tmp fragment files expire.

## Source governance

This source is a review candidate. GitHub is version control, not a substitute for independent owner authority. Do not automatically deploy to Production from the branch or from this package.
