---
name: wordpress-mobile-css-delivery
description: Maintain MAD4B's company-neutral mobile first-paint CSS contract across public WordPress pages, device caches, WPML routes and companies. Use for mobile CSS Async, Inline Async Loader, Critical CSS, LiteSpeed and first-paint cache changes.
---

# MAD4B mobile Critical CSS + Inline Async Loader

This is a dynamically discovered CSS-delivery workflow, not a static list of supported operations, sites, pages, languages or plugin versions. Inspect actual WordPress runtime/manifest and site ownership before recommending or invoking a mutation. The Skill does not grant write authority.

## Permanent first-paint contract

1. For an eligible anonymous, public, trusted-mobile, cache-safe GET request, MAD4B Verified Critical CSS is the single rendering owner. Inline the manifest-certified first-viewport Critical CSS in the document head, without deleting any full CSS rules.
2. Load the original exact-hash LiteSpeed combined CSS as one external media=print stylesheet and switch it to media=all on completion. A small MAD4B inline Async Loader supplies the load and bounded timeout fallback. Preserve original stylesheet inside noscript.
3. An optional stylesheet preload is permitted only if the company/locale/route/source-bundle manifest independently approves it. Do not load a second stylesheet from JS. Do not combine a separate LiteSpeed loadCSS preload polyfill with the media=print link: this can produce duplicate requests in legacy browsers.
4. A detached MAD4B mobile External CSS Async module must yield to Verified Critical CSS. Never allow two buffers to rewrite the same stylesheet. Do not enable the global LiteSpeed Async or UCSS switches to accomplish mobile-only delivery.
5. Derive eligible routes, languages, device class, company host, content hashes and CSS epoch dynamically from the site manifest and request. No fixed EN/ES-only operations or environment-wide exclusions: unverified variants must use the original blocking stylesheet.
6. The site's existing FastCGI cache varies correctly by mobile/desktop and canonical URL. Rewritten HTML may only enter a device-appropriate, market-safe cache bucket. Never cache sensitive WooCommerce cart, checkout, visitor preferences, pricing, login, customer, currency or GeoIP variants under a shared public key.
7. If CSS SHA, Elementor document fingerprint, WPML language, page ID, route, CSS epoch, original stylesheet count, source owner, feature policy or loader provenance disagree, fail to the native original. Never make an unverifiable page look faster by returning empty/partial CSS or removing Elementor/JetEngine markup.

## Adaptive change lifecycle

Discover, observe, classify, and reconcile changed bundle and content fingerprints via the existing company-owned source/publisher workflow, not a hardcoded job list. Invalidation is scoped to affected canonical language URLs and mobile cache families, with CAS and bounded retries. Browser QA/receipt is necessary for new, previously unverified critical coverage, but the owner's explicit immediate rollout of the loader can reuse already verified critical bundle evidence. A Skill alone is not an execution grant.

Preserve original per-site manifest bytes, current runtime fingerprints, backup paths, exact CSS SHA/HTML delivery readback, and any required Original 313 release receipts. Do not claim a Git merge or signed production receipt from a VPS host edit.

## Diagnostics and rollback

Inspect MAD4B_Mobile_CSS_Inline_Contract_V1::status(), the local mad4b-mobile-css-delivery-policy.json, mad4b_perf_critical_css_v1, the source/critical bundle SHAs, actual generated HTML, and the trusted Nginx device cache key. Verify exactly one #mad4b-critical-css-v1, one #mad4b-css-rest-v1, and one data-mad4b-css-async-lib when a certified mobile variant is used; otherwise the page should retain its original stylesheet. Desktop and logged-in/private contexts must remain unmodified.

Rollback the company-owned delivery policy or its runtime/manifest to their saved preimages; if Critical CSS must be disabled, use the local mad4b_perf_critical_css_v1=off option and target only affected canonical public paths for invalidation. Never Purge All, clear Redis indiscriminately, force an OAuth/certificate gate for ordinary readback, or assume the source site's CSS manifest is portable to another company.
