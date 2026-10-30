# Company Critical CSS Preset v1 — Staging-only source candidate

An opt-in, company-neutral WordPress MU plugin. Defaults to DISABLED for each new tenant. The PHP module has no tenant-specific domain, Elementor IDs or LiteSpeed CSS hashes; put verified site values in company-critical-css-preset.json.

## What it does
- On a reviewed Staging page only, moves the noncritical UAEL frontend stylesheet off the render-blocking path with media=print and an onload switch to media=all; includes a no-JavaScript noscript fallback.
- On the same page, optionally adds defer to LiteSpeed's WebFontLoader via the supported litespeed_optm_html_head filter. This retains WebFontConfig, Google Fonts request and WPML.
- Does NOT defer jQuery, JetSmartFilters, WooCommerce, WPML JavaScript, UAEL archive/product CSS or any generic CSS. Existing LiteSpeed CSS Combine/JS Defer settings remain unchanged.
- Template guard: exact hostname, locale, route, queried home document ID, UAEL plugin version and SHA256 of all reviewed Elementor _elementor_data documents. Any source change revokes the optimization automatically.
- Bypasses logged-in, editor, previews, admin, AJAX, query strings, non-GET, other locales and environments. No Production or tenant auto-activation.
- Test headers X-MAD4B-Critical-CSS-AB: control and X-MAD4B-Webfont-AB: control restore original per-request output in Staging for comparison.

## Deployment
1. Validate the target company's actual above-the-fold DOM at 390px and desktop, including logged-in/editor/guest, RTL and menus. Only defer UAEL if none of its required elements are above the fold.
2. Copy module into that company's Staging MU directory and copy preset.example.json to company-critical-css-preset.json (kept disabled).
3. Configure tenant host and checked Elementor page IDs / document SHA256, UAEL version, exact locale and route. Set defer_webfontloader independently after A/B fonts and CLS tests. Only then set enabled true.
4. Browser QA: FCP/LCP, no FOUC, Mobile/Desktop geometry, nav menu, UAEL gallery, dynamic CSS, images, JetSmartFilters, prices, booking CTA, checkout. Run JavaScript-disabled fallback and verify a revised template causes fail-closed fallback.
5. For other locales or pages, create separately certified routes, never blindly copy homepage rules.
6. Source-control via a PR; require owner-approved exact HEAD and Staging source parity before tenant rollout. No Production mutation from this package.

## Staging evidence (29 Oct 2026)
- Exact EN homepage has no UAEL element in the 844px first viewport on 390/1360 widths; image gallery is at ~15,209px on 390px and ~9,712px on 1360px and remains 39 slides.
- A/B browser parity: 44/44 mobile and 50/50 desktop Attractions card identifiers/titles, 55 Mega Menu links, 44 images, 10/10 visible loaded; gallery image dimensions and slide counts matched.
- In 4x CPU, 1.6Mbps, 150ms RTT controlled tests with third-party requests blocked, normalized FCP after TTFB was ~3,028ms baseline and ~2,147–2,170ms candidate (two candidate samples, one good baseline). Not a Lighthouse result, LCP or statistically established field improvement.
- External ChatbotX and Google fonts requests intermittently failed in both control and candidate. Do not attribute these network issues to the preset.
- Guard failure was tested by invalidating a reviewed header-template SHA: output reverted to ordinary blocking CSS + ordinary WebFontLoader; restored config returned both optimizations.
- No global JS Defer, CSS Combine, Elementor/UAEL vendor edits or real HTML page caching.

## Rollback
Set enabled=false in only the target Staging JSON; no database mutation and no Nginx rewrite required. For complete retirement, remove the MU module after verifying the site. Never copy a tenant's JSON into another company.
