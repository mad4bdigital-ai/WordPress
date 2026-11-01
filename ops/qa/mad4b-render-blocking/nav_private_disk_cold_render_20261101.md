# Cold PHP: Staging public destination navigation private snapshots

Date: 2026-11-01.
Site: https://tours.dev.mad4b.com. Environment: Staging only. UUID e5e64bca-ed62-48d2-b2b3-dff693cd702a. Production unchanged.

## Root cause

Original JetEngine Elementor destination navigation template 67125 draws 45 cards for 44 distinct destinations in EN Tours archive. An isolated first-origin profiler found 585.64ms in 45 native term-card callbacks; another sample found 821.41ms. Its 44 image widgets each fetch their own dynamic destination featured image; repeated term memoization within one request does not save much because nearly every term differs.

The existing safe source-bound MU 323 cached only this public navigation HTML, with an object-cache TTL of 45 seconds. After expiry or an object-cache flush, the entire static navigation set required another expensive Elementor rendering pass. Tour List template 25102 and Featured template 14962 contain visitor-dependent price and product data and are NOT eligible for this HTML cache.

## Live Staging change

Updated wp-content/mu-plugins/323-mad4b-nav-term-fragment-cache.php to version 1.1.0. Deployed/source SHA256 6bd699af7f5f1ed1c81f1bfdab74ced6b03f80d71035c42acb5415755bc73d3f.

New bounded private, VPS-persistent public navigation snapshot layer under /run/mad4b-oauth/mad4b-public-nav-term-v1 (directory mode 0700, files mode 0600; not in web document root). The first native JetEngine render creates each snapshot; subsequent origin PHP requests can read the static term card without rendering Elementor again, even when the 45-second object cache has expired.

- Source term HTML is allowed only for exact approved template SHA256 079e0deb..., WPML locale, mobile/desktop class, term attributes and full meta, term permalink, WPML/site/Elementor/theme/kit settings and attachment metadata where the featured image is an attachment.
- Atomic writes, SHA256 file-integrity validation, maximum card size 128KiB, refusal to store script/form tags and refusal to follow symlink/path traversal entries.
- Seven-day sliding life for actively viewed, unchanged public destination cards. Changing term, template, image metadata, permalink or display context changes the hashed key; missing, corrupt, stale or unsupported entries fall back to original native render.
- Explicit header X-MAD4B-NAV-CACHE-CONTROL: native-v1 forces native; disk-v1 bypasses the object cache and directly tests private disk reads. Default uses original 45s in-memory cache before private snapshot.
- All six exact public WPML Tours archive routes, default USD, anonymous GET, and exact Staging URL/UUID guards. No cookies, authentication, geographic or currency overrides, filtered queries, REST or editor.
- The same original JetEngine/Elementor callback and CSS enqueue semantics remain, preserving MU328 compatibility. No prices, inventory, bookings, customer/session data, filter results or full Tour List/Featured cards are persisted.
- A daily guarded Staging WP-Cron event mad4b_nav_private_snapshot_prune_v1 removes ONLY old, matching private files (max 250/run, no symlinks). It executed successfully in 0.014 seconds; all valid 148 snapshots remained, and the event rescheduled.

## Measured component impact

In a single paired live, page-cache and CSS-cache-bypassed EN archive test:
- 45 original navigation item render callbacks: 585.64ms total.
- 45 callbacks with forced disk-only snapshots: 13.91ms total.
- Isolated reduction: 571.73ms in this component, approximately 97.6% of its native cost.

Full-page origin TTFB fluctuated heavily with Staging server load; six alternating pairs yielded native median 8.847s, disk median 6.131s, optimized faster in 5/6, but native outliers reached 13.5s. DO NOT claim that the 2.716s difference is solely this feature. The isolated render reduction is defensible; overall 3-5s origin TTFB remains unresolved.

## Browser acceptance

Real Chromium native-vs-disk route tests for EN, FR, DE, AR, ES and IT. HTTP 200; no page errors; same destination navigation card order/count, images/srcsets, hrefs, geometry and text; same Tour List and Featured post data/order, displayed price texts and links, stylesheet/style count and first-party scripts. Elementor transient dynamic numeric CSS identifiers were normalized. One initial German Featured posts result changed between requests; a later native/native check and a repeat native/disk comparison both matched, demonstrating unrelated data variability. No query was changed by this MU.

Mobile browser with iPhone UA and 390px viewport: EN and AR native-vs-disk comparisons preserved navigation images, geometry, hrefs, card order and dynamic price texts. EN changed only two externally generated Google Tag Manager gtm query markers; first-party scripts remained unchanged.

Twelve HTTP warm-up requests for desktop and iPhone-UA archives, six locales each, populated 148 valid public snapshots totaling approximately 732KiB. Native source parity, private filesystem and CRC/SHA tampering, expiry, script/form rejection, symlink and traversal regressions were tested.

## Files and rollback

Staging evidence and rollback:
 /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/nav-disk-20261101/
 rollback-nav-private-disk.sh plan|apply

Rollback plan verified the exact Staging URL/environment/UUID and source plus original backup checksums, PASS. Apply is NOT executed. Apply restores the former MU323 and removes only its scheduled Staging cleanup event; private snapshots remain harmlessly unused.

Source test file: ops/qa/mad4b-render-blocking/tests/nav_private_disk_snapshot_contract.php. Existing nav_term_fragment_cache_contract.php remains passing.

## Remaining boundary

This solves repeated expensive STATIC destination navigation rendering in a cold origin PHP request when source-bound private snapshots already exist. The very first render of an entirely new/changed destination still uses native Elementor. Per-card Tour List/Featured pricing, filter calculations, Elementor composition and other WordPress startup cost remain and overall origin TTFB is not closed. No Production mutation or PR merge.
