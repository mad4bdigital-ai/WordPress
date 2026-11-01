# Homepage WPML flag preload priority: live Staging remediation

Date: 2026-11-01. Exact scope: tours.dev.mad4b.com, staging WordPress environment and enrolled site UUID e5e64bca-ed62-48d2-b2b3-dff693cd702a. No Production mutation.

## User problem and verified findings

The supplied mobile Lighthouse audit lists an estimated 2360ms render-blocking request opportunity and possible 697KiB savings from cache lifetimes. These estimates apply to browser loading, not WordPress PHP TTFB. The live site already has LiteSpeed external CSS combine enabled (optm-css_comb=1 and optm-css_comb_ext_inl=0), leaving dynamic inline CSS separate. Current desktop/mobile browser tests observe just one combined CSS link on the homepage.

The current homepage HEAD also generated 24 repeated <link rel=preload fetchpriority=high as=image> entries for six WPML language flag URLs. Their 24 markup hints compete in the initial network scheduling queue even though the browser deduplicates image fetches to six actual flag URLs. They are not the actual LCP content. The separate city-slider image preload (MU 310) is the LCP-focused priority hint and must not be removed.

## Staging intervention

New, exact-scope MU:
wp-content/mu-plugins/329-mad4b-home-wpml-flag-preload-trim.php
SHA256: a159ebfcc154ff0d45c9a0950e2c89ba339318f00fb0ecc97e6b17a5b58e7f6c.

The code uses LiteSpeed's documented litespeed_buffer_after filter at priority 80 to remove ONLY link tags in HEAD whose rel is preload, as is image and href is an exact first-party WPML flag SVG asset. It changes neither actual img elements nor any Elementor, JetEngine, WordPress, WooCommerce, JS, CSS, navigation, or LCP image URLs. The source refuses unsupported environments, nonhomepage routes, logged-in users, previews, queries, cookies, authorization and unsupported WPML locales, and has explicit one-request native bypass header X-MAD4B-FLAG-PRELOAD-CONTROL: native-v1.

## Live browser acceptance

- EN baseline: 24 flag preloads, optimized 0; 24 actual IMG flag elements in both.
- AR/FR/DE/ES/IT normal Staging requests also show 0 flag preloads after the change.
- English desktop and mobile, Arabic desktop and mobile, French mobile: HTTP 200; one combined CSS stylesheet link in both baseline and optimized mode.
- Visible flags: 6/6 completed with valid natural image width in every browser trial, native and optimized.
- Network requests: 6 unique flag SVG resources in each mode (no assertion of download-byte saving).
- English LCP city-slider image preload remains present in both modes.
- Intermittent page JS errors occurred in both native and optimized browser runs; no consistent regression was linked to the flag-preload change.
- Static PHP contract checks HTML link removal, preserving the LCP preload, body IMG tags and CSS, and fail-closed behavior for unsupported or oversized content.

The change removes excessive high-priority hints. It is NOT established that it reduces FCP/LCP by 2360ms; user-facing field/Lighthouse metrics require fresh controlled measurement.

## Cold PHP scope remains separate

On the same Staging site, full un-cached Tour List and Featured HTML still require ~3-4s in the sampled request. The earlier MU328 request-local memo reduces redundant nested destination link work within the very first PHP request (47-99ms component savings across EN/FR/DE), but it does not remove core Elementor per-item rendering or dynamically priced WooCommerce components. Staging is intentionally not using global Elementor element HTML caching because source listings include dynamic prices and user context. No unverified shortcut was enabled.

## Evidence and rollback

Raw source HTML and live Chromium evidence are saved on the VPS:
 /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/home-flag-preload-20261101/

Scoped rollback:
 rollback-home-flag-preload.sh plan|apply
The plan verifies Staging exact URL, environment, enrolled site UUID and MU SHA256; apply removes only the new MU. Plan PASS; apply NOT executed.

PR #677 still requires an authorized GitHub source synchronization for MU328 and MU329 if no later commit contains them. A previously attempted push was rejected by connection security checks; no bypass or force update.

## JS deferral safety trials (both rejected and reverted)

Two short, guarded trials changed only the Staging LiteSpeed option optm-js_defer from 0 to 1. Both were restored before acceptance:
1. Default global JS deferral: mobile EN/AR homepage and EN/AR/FR Tours archive emitted repeated errors including JetSmartFilterSettings is not defined and filtersList undefined.
2. Global defer plus dedicated JetSmartFilters script/inline exclusions: JetSmartFilterSettings was no longer missing, but produced a new repeatable wp.hooks undefined error in all five tested views and some additional network errors on homepages.

Rollback was successful. Final value optm-js_defer=0. The exact original string-type 634-character option optm-js_defer_exc was restored and verified byte-for-byte against the original JSON snapshot. Follow-up EN homepage, EN Tours and AR Tours Playwright runs returned HTTP 200, rendered the original 8/10 tour items with prices, one combined CSS stylesheet and zero JavaScript page errors. CSS combination remained enabled throughout.

The temporary scripts and before/after browser evidence are stored under:
 /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/js-defer-trial-20261101/

Decision: do not enable site-wide JS defer without fixing the initialization ordering and separate native widget integration tests. This leaves some scripts render-blocking, but preserves functional filters, pricing and booking UX.
