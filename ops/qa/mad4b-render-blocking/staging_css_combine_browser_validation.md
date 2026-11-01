# Staging external CSS combine: browser acceptance — 2026-11-01

Scope: tours.dev.mad4b.com, WordPress staging only, enrolled UUID e5e64bca-ed62-48d2-b2b3-dff693cd702a. Production untouched.

## Current live configuration

LiteSpeed optm-css_comb = 1, optm-css_comb_ext_inl = 0, CSS minify remains 1.
External stylesheets are combined. Per-request dynamic Elementor/JetEngine inline CSS is NOT combined.
Previous state: combine 0, external-inline 1.
Site-bound command: bash ops/qa/mad4b-render-blocking/staging_external_css_combine.sh verify|apply|rollback
VPS fallback: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/cold-render-and-css-20261101/rollback-css-combine.sh

## Chromium/Playwright acceptance (headless isolated container)

| Locale | Original CSS links | Combined links | Card geometry | Price text | Console page errors |
|---|---:|---:|---|---|---|
| en | 78 | 1 | equal | equal | none |
| fr | 78 | 1 | equal | equal | none |
| de | 78 | 1 | equal | equal | none |
| ar | 79 | 1 | equal | equal | none |
| es | 78 | 1 | equal | equal | none |
| it | 78 | 1 | equal | equal | none |

Per-request style tag counts were unchanged. At 390px mobile, EN and RTL AR card and SVG dimensions, price strings, and computed display styles matched. Interactive EN/AR List to Grid to List round trips preserved button state, price strings, item and SVG bounds. The switcher legitimately loads extra stylesheets on demand; List and Grid stay different. Homepage EN/AR dropped 76-77 CSS links to one, with stable price and box geometry; one animated opacity value differed by sampling time, not dimensions.

On English Tours: sum of original 78 external CSS assets was approximately 2,204,180 raw bytes and 276,595 independently gzip-compressed bytes; consolidated asset 2,204,293 raw bytes and approximately 260,608 gzip-compressed bytes. Combined CSS is served as text/css with gzip, immutable cache, HTTP 200.

Raw browser JSON and PNG comparison evidence:
 /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/cold-render-and-css-20261101/

This resolves the stylesheet REQUEST COUNT explosion observed in the supplied Lighthouse audit. The audit's estimated 3,020ms savings are NOT a certified user-perceived performance result; two throttled browser FCP measurements were very noisy and do not establish p75 or p95. The truly cold 3-4s origin PHP/JetEngine Tour List and Featured cost remains an independent, unresolved source-refactoring task. Price HTML and per-visitor data were never cached. Production deployment requires its own acceptance.
