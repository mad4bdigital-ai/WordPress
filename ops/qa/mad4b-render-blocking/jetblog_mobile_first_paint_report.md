# JetBlog duplicate initial mobile AJAX: Staging fix (2026-11-01)

## Root cause

The user's Lighthouse network waterfall showed the JetBlog endpoint /?nocache=...&jet_blog_ajax=1 at about 2,559 ms. Real mobile-width browser traces showed two POST requests on the homepage, both using action jet_blog_smart_listing_get_posts with about 5.5 KiB of settings each.

JetBlog 2.4.10.1 initSmartListing sends the initial POST whenever the Elementor device is not desktop, even though the two current widgets b44fd5b and 1330604 use identical desktop and mobile post counts. Their server-side HTML already holds the visible articles. The alternative wp-admin/admin-ajax.php transport was compared and returned identical JSON without a material performance improvement, so transport routing was left native.

## Accepted Staging-only intervention

New MU module: wp-content/mu-plugins/325-mad4b-jetblog-mobile-server-first-paint.php.

- Exact site / environment / site UUID, anonymous GET, six WPML path guards, locale template JSON SHA-256 pins, JetBlog version 2.4.10.1 pin, current default currency USD. German intentionally uses the English Elementor blog section fallback.
- Runs for the two known widgets only, when no responsive per-breakpoint post-count override exists AND the server HTML is already nonempty. Prevents redundant initial mobile POST by temporarily setting the existing JetBlog processing marker during the synchronous initial branch; removes the marker before later interactions.
- Does NOT alter plugin files, backend query, per-visitor HTML, currency, pricing, blog post markup, AJAX signature validation or widget pager/filter behavior. No transient/object/page cache is introduced.
- Explicit native-control header X-MAD4B-JetBlog-First-Paint-Control: native-v1. Rollback: remove only MU #325 from Staging. Widget edits, template changes and a future JetBlog version fail closed.

## Browser acceptance

Headless Playwright Chromium at 390px viewport, native-control versus default-on Staging:

| Homepage locale | Initial JetBlog POSTs native | Default-on | Blog text | Localized links | Image/card sizes |
|---|---:|---:|---|---|---|
| EN | 2 | 0 | equal | equal | equal |
| FR | 2 | 0 | equal | equal | equal |
| DE | 2 | 0 | equal | equal | equal |
| AR | 2 | 0 | equal | equal | equal |
| ES | 2 | 0 | equal | equal | equal |
| IT | 2 | 2 | equal | equal | equal |

Italian widgets had empty content in native server HTML, so the strict nonempty condition declined the optimization, preserving normal JetBlog requests. It is NOT falsely counted as fixed.

Final six-locale comparison had HTTP 200 and zero observed JS page errors. Intermittent generic external network errors occurred in some earlier trials both with and without the canary; those cannot be attributed to this fix.

Functional regression proof: a synthetic orientationchange after an optimized English load still triggered two normal JetBlog AJAX requests and both widgets left their processing state. Negative live probes confirmed no injection on cookie/session requests, alternate currency, geographic overrides, filtered URL, other routes, and the explicit native-control header.

Full browser diagnostic scripts and the test measurements are on the Staging VPS at:
 /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/cold-render-and-css-20261101/

This removes redundant mobile initial requests and reduces origin traffic. The 2,559 ms number belongs to the user's Lighthouse screenshot; on isolated origin tests the POST response took roughly 0.5-0.6 seconds. No fixed real-user FCP/LCP improvement or full cold PHP TTFB closure is claimed. Production unchanged.
