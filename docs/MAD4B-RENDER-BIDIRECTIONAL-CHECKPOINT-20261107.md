# MAD4B — Cross-company Render / CSS and Cache Reconciliation (2026-11-07)

Environments: tours.dev.mad4b.com (Staging), wayfatours.com (Production). Live files audited on the enrolled VPS, not inferred from GitHub only.

## Outcome

1. Both sites run company-portable `MAD4B Shared First-Paint v1.0.4` (identical source hash). Staging's tested Elementor city widget `95d1c9c` and brand widget `d570071` were migrated to **local WordPress options** on each company. Wayfatours existing HTML showed these selectors active, so this preserves its LCP preload and city CSS instead of disabling them during generalization. The code no longer embeds the widget IDs and uses canonical WordPress home + the shared company Site Scope rather than WPML-translated home_url for authorization.
2. Both run `Stable Listing CSS v0.2.2`: company scope guard, no public Production query-string QA override. Normal live option values preserved.
3. Both run `Critical CSS / Predictive CSS v0.3.15`: separate request-level manifest cache per blog and canonical company URL, not a single global static manifest. Rejects a manifest belonging to another company, an unbound tenant, or a wrong site_host. WPML host checks use stored canonical home. Production `mad4b_perf_critical_css_v1=off`, predictive `off`, no valid local predictive manifest.
4. Both run `Page Dependency Graph v0.5.1` and `Published Content Scope v1.1.1`. Production schema tables `wp_mad4b_pd_pages` and `wp_mad4b_pd_edges` were created and were empty immediately after creation. Production remains `page_only / collect=off / refresh=off` and Scoped Cache hybrid eligibility false. Its Query Governor v1.1.3 retains conservative legacy fallback, while source stays on v1.2.5. No unsupported cross-environment governor overwrite.
5. Browser HTML and JetBlog Mobile Lazy remain OFF by default on Wayfatours until explicit browser / price / FastCGI parity checks. Existing Wayfatours FastCGI caching is untouched.

## Isolated tests

- First-Paint: 19 scoped checks on each PHP runtime (WPML, 2 company identities, selectors, no import, user cookie, QA query, access guard).
- Predictive CSS: 9 checks on each PHP runtime including switch_to_blog, URL change, unbound profile and avoidance of localized home_url.
- Stable CSS: 12 safe-scope checks on each PHP runtime.
- Verified Critical CSS: 10 QA guard checks on each PHP runtime.
- Page Dependency existing standalone fixture (on both runtime variants), source policy and safety checks.
- Actual PHP lint and SHA256 equality on deployed common modules.

These are isolated code contracts; they do NOT certify real browser LCP, CLS or a live signed native Broker 313 receipt.

## Performance snapshot — diagnostic only

Identical server, no controlled quiet-host benchmark. Normal cached anonymous desktops: Stage 0.02 s TTFB and Wayfa 0.015 s TTFB in one sample; both are FAST on cache hits. Source HTML about 884 KB, Wayfa 899 KB; Stage 160 script tags vs Wayfa 131. Mobile Stage cached request once timed out after 9 s, while Wayfa served cached mobile at 0.097 s. Noncached public PHP origin requests on both sites timed out after 12 s during elevated server load; neither tenant has proven good uncached performance. This is not evidence that newer code is faster or slower. The earlier host sample had PHP-FPM CPU in the hundreds of percent, plus shared host pressure and long-running other WordPress jobs.

Key architecture difference: Wayfa FastCGI uses 75 s public-page TTL and background refresh with stale-while-updating/error handling for verified public routes. Stage uses shorter 30 s home / 15 s tours archive TTLs and language/device/UA-family partitions. Do **not** copy all Wayfa HTML caching options into Stage without validating public eligibility, commerce/market safety, content freshness and cache invalidation.

## Next acceptance gates

- Controlled mobile browser trace (LCP/CLS/requests) for EN/ES Wayfa and multiple WPML Stage languages, under bounded host load.
- Graph/Query/Fragment end-to-end invalidation for post publish, edit, taxonomy, Elementor template, rates, WooCommerce private content, default/market currency.
- Nginx FastCGI selective purge or measured TTL parity; database/Redis proof is not equal to HTML purge.
- GitHub CI must run and be reviewed; existing draft PR is not release-approved.
- No staging-only manifests, copied media IDs, rates/orders or tenant UUIDs allowed into Production.

## Evidence and rollback

On VPS: `/opt/wayfa-rebuild-20261102/qa/deep-runtime-reconcile-20261107/`. Original module snapshots in `before/`, tested portable candidates in `candidate/`, per-tenant HTTP summaries in `perf/`. Preserve unrelated concurrent modifications and compare current file SHA before any rollback. Production Was not made dependent on Staging availability.
