# MAD4B | Bidirectional Reconciliation 2026-11-07

Source: tours.dev.mad4b.com Staging. Tenant: wayfatours.com Production.

## Applied live on both sites
- MAD4B Experience 1.2.7. Its Presentation and Visitor modules share a canonical Site Scope class instead of comparing WPML-translated home_url. Guard checks WordPress stored home, actual environment, production HTTPS, declared tenant origin and UUID including Company Site Profile. No cross-blog cached authority.
- Native Brand Context v1 from Wayfatours is now available to Tours.dev without replacing the MAD4B Platform native provider. Brand image/name/Elementor Kit options always come from the current company's WordPress installation.
- JetBlog Mobile Initial AJAX Defer 0.3.0: identical PHP/JS source on both sites. Its previous Staging-exclusive gate was replaced with company-bound Site Scope, anonymous mobile GET, pinned unchanged vendor SHA and a company-local option. On Staging default ON; on Production default OFF pending browser/AJAX acceptance. Public query parameters cannot activate it.
- Secondary Listing Lazy 1.0.3: semantic Query Has Items guard copied from Tours.dev to Wayfatours. Prevents automatically deferring listings in conditionally visible Elementor sections. Explicit native JetEngine lazy settings remain respected.

## Copied from Wayfatours to Tours.dev
- Distribution Connectors 1.6.2: exactly 8 added/updated code and CSS files, six PHP lint PASS; two new runtime classes Catalog Data Epoch and Distribution Activation were confirmed loaded on Tours.dev.
- Actual channel/provider activation still requires rights, real verified provider receipt, approved account and real send adapter; the isolated 6-case fail-closed contract passed. No external sends performed.

## Runtime evidence
- Shared Site Scope fixture: 12 Staging / 13 Production guards passed including language switching, cross-blog/tenant, wrong domain, environment, UUID and HTTPS.
- Native Brand Context has the previous 18-case test suite; English/Spanish Wayfatours footer already verified.
- JetBlog policy/asset fixture passed in both PHP containers with exact vendor SHA and in fail-closed vendor-absent test. Real production mobile browser acceptance remains pending, so production option is OFF.
- Secondary query-visibility regression: 7/7 PASS.
- WordPress live readback on both sites after deployment: Experience 1.2.7, Presentation true, Visitor true, company_brand_context available, Distribution Activation class loaded, Spanish locale switch succeeds and separate WordPress home identities are preserved. All 8 PHP/Nginx/Redis/MariaDB site containers healthy.
- Wayfatours Home EN and ES HTTP 200, without sampled new PHP Fatals. Tours.dev public TTFB remains variable and sometimes timed out before and after this work; no performance SLA is claimed.

## Intentionally not copied
- Source-specific City Visible Image and Mobile Dock code uses pinned Elementor widget IDs and viewport behavior; target retains tested local variants pending company-configurable widget selection and browser parity.
- Source Query Governor 1.2.5, Page Dependency Cache and Published Scope have DB, Redis, FastCGI and company-specific template/route manifest dependencies; Wayfatours Governor 1.1.2 preserved.
- Critical CSS 0.3.13, first-city CSS, stable listing CSS QA probes, cross-company broker and Central Policy need separate compatibility/tenant approvals. Never blindly overwrite UUID, DB/options, WPML, WooCommerce, rates, orders, booking, domain or Nginx config.

## Rollback and artifacts
- Original Experience source/target files: /opt/wayfa-rebuild-20261102/qa/bidirectional-portable-20261107/source and /wayfa (suffix .before)
- Distribution 8-file fingerprint manifest and Source backup: .../distribution-wayfa-to-source
- JetBlog original source PHP and JS: .../jetblog-mobile-lazy.source.before.php and .../jetblog-mobile-lazy.source.before.js
- Secondary Listing Lazy target prior version: .../performance-source-to-wayfa/mad4b-secondary-listing-lazy.before.php
- Reconciliation evidence: /opt/wayfa-rebuild-20261102/qa/source-target-parity-20261107
Only restore a file after matching its deployed SHA; no database or broad cache purge performed.

GitHub CI must run before draft PR merge. Browser/mobile production opt-in, remaining company-specific selectors and advanced cache/Commerce reconciliation remain release gates.
