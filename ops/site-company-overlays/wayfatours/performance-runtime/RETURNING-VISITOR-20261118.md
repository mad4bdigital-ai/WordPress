# Wayfatours Returning Visitor Performance — 18 November 2026

## Reproduced behavior
- The user's PSI Mobile screenshot (Moto G Power Slow 4G): Performance 42, FCP 3.7s, LCP 8.6s, TBT 960ms, CLS 0. This is a Lighthouse simulated cold visit, not a trace from their Chrome profile.
- A new anonymous public home page is FastCGI cached, often with warm TTFB in the tens of milliseconds. Sourcebuster-only cookies switch browser Cache-Control to private,max-age=1800, which STILL PERMITS browser-local storage; it is not no-store.
- Saving a country creates a 90-day tenant-HMAC-signed mad4b_country_selected_v1 cookie. A signed visitor's homepage responds private,no-store, with ~3.5–4 seconds server-side time and ~2,100–2,230 SQL queries. Full private HTML must NOT be admitted to the anonymous shared cache. Full Elementor and JetEngine render dominates. An operator-only profiling MU plugin was removed after reading anonymized aggregate timings.
- Android user-agent with Chrome confirmed certified Critical CSS plus its deferred full CSS rest file. A second matching link is in a noscript fallback; it does not double-load in JavaScript-enabled Chrome.

## Signed country, private Top Cities term fragments
- Source: wp-content/mu-plugins/429-mad4b-signed-home-destination-card-cache-v1.php, option mad4b_perf_signed_home_cards_v1.
- Default off; canary with X-MAD4B-PRIVATE-CARDS: v1; on for exact anonymous signed-country GET / and /es/ only.
- EN source graph: Elementor document 25909, widget b25dbff, JetEngine destination listing 45927. ES WPML source: 34605, b25dbff, 68823.
- Uses the existing MAD4B shared renderer for term-card fragments only; no price, nonce, WooCommerce, form, checkout or full HTML cache. Native listing item hooks/asset enrollment remain intact. Per-exact verified HMAC-cookie+country+language SHA-256 scope, plus the existing renderer's content timestamps, terms generation, currency and device key. TTL 45 seconds.
- Fail-closed for non-home paths, anonymous missing/invalid/duplicated country cookie, unknown visitor cookie, signed-in sessions, Woo cart/session, location and market headers, custom currency, passport, AJAX/REST/editor, and unsupported source graphs.
- EN and ES: first eligible render 6 writes, next 6 hits; 8 visible city cards, identical text/link fingerprints, no browser page errors. Unknown extra cookie and custom currency header produced no canary admission.
- In one isolated widget comparison, 162.04ms and 38 queries fell to 51.16ms and 11 queries on cache hit. Full-page TTFB still varies (roughly 3.5–6.4s); no claim of a sitewide speedup or TBT=0.
- Rejected earlier Top Attractions target had no listing item filter calls under the signed-country test, so its candidate was not enabled.

## Rollback and scope
- Set the separate option to off through WordPress CLI; it does not alter full HTML FastCGI keys. Short-lived object fragments expire in 45 seconds and cannot be shared across signed-country cookie identities.
- Original source and canary backup: /root/mad4b-backups/20261118-signed-home-cards-v1 .
- Performance bottleneck remains the other ~30 JetEngine grids and Elementor nested templates. Any full market-specific HTML cache requires authoritative market/currency keys, exact rate invalidation and private nonce/booking separation first.
- A new official Mobile Lighthouse measurement remains necessary; this incremental fragment optimization alone does not certify improved Total Blocking Time.
