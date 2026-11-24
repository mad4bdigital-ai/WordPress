# Public Tour term fragments — CSS identity and Woo tax-safe partition (24 Nov 2026)

## Incident / source-only reproduction

Tour #60623 uncached PHP Origin remains roughly 4–5.5s on Tours.dev/Wayfatours. A configured, **opted-in** first-party public JetEngine term fragment cache was not functioning. Its admission incorrectly required `woocommerce_calc_taxes=no`; both companies have `yes`, so the subsystem silently refused safe term content even though the live price and Form 6 are separate. Once the tax gate passed on exact-header staging test, `MAD4B_Stable_Listing_CSS_Identity::normalize_public_term_fragment()` rejected the output because it recomputed `is_singular()` **inside a nested JetEngine listing which changes the global WP_Query**. In addition, the global CSS normalizer could reuse `termId:67125` (header Mega Menu) for the same term shown in public related Destinations listing `45929`, whereas a Redis HIT's inline style might use `termId:listing`. A cache HIT without CSS-scoped parity can visibly break cards.

## Technical boundaries

`414-mad4b-stable-listing-css-identity.php` v0.4.1 (both sites SHA256 `3e73c95f94619ecf4792a1c68aeada3a19b332943d23af9a6c199855f37cd8e3`):
- Capture verified public canonical Tour ID on WordPress `wp`, before nested query changes.
- Replay that immutable and still-verified Tour admission **only** for public-term fragment CSS normalization. Do **not** globally enable Elementor document caching or bypass editor/session contexts.

`452-mad4b-jetengine-term-css-isolation.php` v0.2.1 (both sites SHA256 `016b5f319fa1cd33cf88ed53dfca77664cd1cee103a2874399d08ba9a6fb2b58`):
- Evaluate only the explicitly manifest-pinned JetEngine `destinations` term listing and canonical anonymous Tour request.
- In the finished LiteSpeed buffer, validate exact outer `jet-listing-grid__item` ID, matching term/data-post-id, inner Elementor listing document ID, matching scoped inline style and a single recognized original selector. Replace **both** outer class and inner style selector with `elementor-dcss-mad4b-<SHA256(term:listing-document)[:16]>`. Never replace other menu cards, prices, nonce, form, arbitrary selectors or CSS outside the item; unknown tokens are left native.
- Independently per-company `mad4b_term_css_scoped_v2` option defaults `off`; staging/production canary permits only exact `X-MAD4B-Term-CSS-Proof: strict-term-css-20261124`. `on` admits canonical public context; the existing company-level stable CSS policy must also be `on`.

`mad4b-render-query-governor.php`: Tours.dev **1.4.4** and Wayfatours **1.5.2**, **intentionally different**. Source repository standard path contains source v1.4.4 (SHA256 `48af87eab799bd982a77ee1d2e7bbf3f4ba96c242aa9943744ae951c34edb58e`). The Wayfatours v1.5.2 **company overlay** is included at `ops/site-runtime/tour-public-terms-v2-20261124/wayfa-mad4b-render-query-governor-v1.5.2.php` (SHA256 `702879f8643785563feb5c96206b00f6cf50a850f38eac4422fa31c218e0c547`) for exact fleet reconciliation. **Never replace Wayfa's live v1.5.2 with source's v1.4.4**, which omits signed-market-specific query handling. Both qualify `woocommerce_calc_taxes=yes` **only** when the target is an active, manifest-pinned public `jet-engine` `destinations` terms listing, the source hash matches, it contains no sensitive rates/booking/checkout semantics, and canonical public Tour route + language + WCML market/currency + anonymous GET + no unknown cookies/headers are verified. This does not change Woo tax settings, rates, pricing, payment or the Form 6 pipeline. `mad4b_tax_safe_public_terms_v2` defaults `off`; staging/production canary is exact-header; `on` qualifies the audited term listing. Fragment cache version becomes 1.3.0, separating previous Redis keys.

## Live source-first and company canary receipts

Tours.dev original hook: `context=false` with `woocommerce_calc_taxes=yes`, despite `tour_fragment_route=true`, current market `DE/EUR`, `partitionReady=true`. After source fix, anonymous EN canonical Tour #60623 with QA headers and valid WPML cookie:

| Request | Native fragment builds | Cache HIT | Rejects |
| --- | ---: | ---: | ---: |
| first | 4 | 1 request-local | 0 |
| second | 0 | 5 (4 Redis, 1 memo) | 0 |
| third | 0 | 5 | 0 |

Each native MISS had 4 verified persistent writes and readback in scoped staging profiling. Exact HTML for related destinations widget `6190bab`: **20,254 bytes, SHA256 `1315dcacf641c0dff3efaa3229101f0c4509b10d630f219044ed8a2cc73e59a0`**, identical native vs Redis HIT. Same 5 links and 44 Mega Menu entries; Form6, price, TOC unchanged. Chrome EN mobile390, EN desktop1366, ES mobile390 **6/6** native-vs-cached positive, 4 verified CSS-scoped related term cards, no duplicate Form6 or JS page errors.

Wayfa originally `context=false` due tax yes, market `DE/USD` (WCML multi OFF). Company-specific v1.5.2 preserves all previously installed signed-country market functionality. Wayfa exact-header production canary:

| Request | Native fragment builds | Cache HIT | Rejects | Origin TTFB |
| --- | ---: | ---: | ---: | ---: |
| first | 4 | 1 request-local | 0 | 4.71s |
| second | 0 | 5 | 0 | 4.55s |
| third | 0 | 5 | 0 | 4.27s |

Wayfa native-vs-cache HTML of related destinations `6190bab`: **16,372 bytes SHA256 `ec48d1623cf7674a` (prefix)**, 5 links exact, 4 CSS corrections in the LiteSpeed buffer. Separate Chrome comparison EN mobile390 and ES mobile390 found native/cached fragment HTML, links and computed CSS equal; Form6, price, TOC and menu 44 all present, no JS errors. Wayfa's LiteSpeed may strip internal `<style>` by final HTTP; comparing CSS after LS, not only raw PHP, is required.

## Safety tests and rollback

`test_jetengine_term_css_isolation.php` — deterministic identity across MISS and Redis HIT, exact outer/inline matching, idempotence, excluded Mega Menu, unknown mismatches fail closed, no CSS changes on missing inline styles and no removal of commercial markup. `test_public_term_tax_gate.php` — option off denied, unsigned canary denied, exact staged canary admitted, raw source hash alteration/rates denied, non-Tour route denied, explicitly on public template admitted. Both tests passed against PHP8.5 live code on both sites. Additional real Chrome and curl receipts retained on host in `/opt/mad4b-company-provisioning/work/term-css-v2-20261124/`.

Backup of both governors and 414 unchanged originals at `/opt/mad4b-backups/public-terms-tax-safe-20261124/{source,wayfa}/{original.php,414-original.php}`. Rollback `mad4b_tax_safe_public_terms_v2=off` first, `mad4b_term_css_scoped_v2=off` second, then atomically revert versioned files after hash check, and remove new 452. Do **not** purge all CSS/JS/Redis, alter site commerce options or delete element cache by force; use native Tour-path HTML versioning only if public CSS normalized output changed.

## Remaining truth / non-certifications

These fragments eliminate redundant **public destination term card** builds and CSS selector mismatch; they do not cache complete Tour Content `24784` or its commercial rates. In public staging/company samples, PHP Origin still takes ~4–5.5s. The user-requested **uncached Origin <1s** and logged-in browser freeze are **NOT closed** by this narrow fix. Further architectural redesign must partition additional static Elementor widget families, with per-family exact SSR/CSS/JS parity and signed WPML/market policy; preserve `mad4b-rates-elementor-dynamic-cache.php` guard at all times.
