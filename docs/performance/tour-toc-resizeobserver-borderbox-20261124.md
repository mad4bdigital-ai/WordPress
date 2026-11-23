# Tour TOC mobile dock: avoid forced layout reads (24 Nov 2026)

## Scope
Wayfatours tour #60623 and the corresponding Tours.dev page exhibit main-thread Long Tasks even after the Elementor anchor observer and taxonomy-parent fixes in PR #1066 and PR #1067. A direct Chromium mobile CPU profile on the current live route identified the first-party `mad4b-tour-toc-native-20261118-mobile-layout.js` `measure()` function as a measurable hotspot (44 profile samples in one unthrottled run). The original function reads `getBoundingClientRect()` during initialization, inside a `ResizeObserver`, and on every window resize.

This PR reconciles the **exact reviewed first-party live JS** into the source repository; it was not present at this path on `master` before the PR. It changes only the mobile dock height read, not native navigation, price, form, Elementor vendor code, JetEngine queries or FastCGI.

## Change
- When supported, use `ResizeObserverEntry.borderBoxSize.blockSize` for an already-computed border-box height instead of forcing a synchronous layout read.
- Keep the CSS variable update in the observer delivery phase, only when the rounded height actually changes. A rejected intermediate variant scheduled the CSS write in a new animation frame and produced a large rise in `getScrollPosition` samples.
- Fall back to a DOM measurement if the browser has an older ResizeObserver implementation or no ResizeObserver at all; include a persisted bfcache pageshow reconciliation.
- Preserve the native `<details>` TOC behavior and real Elementor sticky dock; no duplicate dock or extra endpoint.

## Controlled Chromium comparison (directional only)
Mobile 390×844, 2× CPU throttle, five-position scroll, ABBA order, two runs per arm:

| Metric | Baseline runs | Final variant runs |
|---|---|---|
| Long Task blocking above 50ms | 1409ms / 1100ms | 1233ms / 1144ms |
| Longest task | 459ms / 439ms | 344ms / 324ms |
| `getScrollPosition` CPU samples | 103 / 73 | 32 / 50 |
| Dock value, rounded | 91px / 91px | 91px / 91px |
| Form 6, TOC and new JS exceptions | Present / no exceptions | Present / no exceptions |

These four browser runs are **not** a population result, and the total blocking ranges overlap. No stable percent improvement or elimination of `Page Unresponsive` is claimed.

## Regression verification
- `node --check wp-content/mu-plugins/assets/mad4b-tour-toc-native-20261118-mobile-layout.js`
- `node tests/performance/test_tour_toc_resizeobserver.js` — modern `borderBoxSize`, older ResizeObserver without supported box options, and missing ResizeObserver.
- Live anonymous Chromium mobile 390×844 and desktop 1366×900: HTTP 200, asset content SHA matches, Form 6 and TOC present, zero uncaught JS exceptions.
- Separate asynchronous toggle/scroll smoke: opening the native TOC and scrolling 1300px closes the drawer on both sites.

## Exact live deployment receipt
Source-first rollout, then Wayfatours, with per-site checked backup and atomic replacement. Original SHA256:
`f43647026463a9826aa77b752cdcaedba4a5fe7a2b8a853cf96c7117476ce248`.
Live/current SHA256 on **both** sites:
`c65d83cc2e551a817a1277b34a8aebe76b458a01eb883d5631b8160dbb025051`.

Protected original backups:
- `/opt/mad4b-backups/tour-toc-resizeobserver-20261124/source/original.js`
- `/opt/mad4b-backups/tour-toc-resizeobserver-20261124/wayfa/original.js`

Rollback: verify the original backup SHA, restore only the single JS asset on the affected site and confirm its publicly served bytes. Do not delete Elementor core files or flush unrelated site content.

## Open issues (separate)
- Origin MISS still ~4.2s on Wayfatours read-only-cookie request; cached HIT was ~19ms. The updated TOC cannot fix server-side template render time.
- The Wayfatours tour page still exposes 1240 select options (1004 from two repeated header market/nationality pairs); a safe lazy/header catalog design must preserve choices and accessibility before production rollout.
- `mad4b-secondary-listing-lazy.php` differs by site: Tours.dev 1.0.16 has SSR/AJAX parity gating, while Wayfatours 1.0.10 lacks the same verifier. Wayfatours has four layout proof entries for listing 45938 but no AJAX market parity proof. Do **not** simply remove the logged-in guard or turn on all native lazy loading.
- This run did not reproduce the user's authenticated Chrome `Page Unresponsive` dialog; no commercial checkout/Stripe transaction was attempted.

Keep the PR Draft until exact-head CI and authorized release review.