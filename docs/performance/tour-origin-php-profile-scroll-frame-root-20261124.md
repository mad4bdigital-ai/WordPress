# Tour #60623 Origin CPU and browser Main Thread — source profile, 24 Nov 2026

## Production problem
Browser `Page Unresponsive`/long JavaScript tasks remain after successful native Elementor Mega Menu memo and Form 6 Sticky spacer cleanup. The **cold (FastCGI BYPASS/MISS)** WordPress Origin on both source Tours.dev and Wayfatours does not satisfy a <1-second rendering target. Do not conflate a FastCGI HIT (~20–30ms historically) with an actual Origin render.

## Direct PHP profiling (Tours.dev; no writes to commercial data)

Temporary read-only, exact-request-header-only MU profiler (removed after samples) measured the ordinary English public Tour #60623 URL, through the existing real Elementor/JetEngine/WordPress runtime. Three in-request hot/cold receipts:

| Measurement | Origin PHP wall | SQL count | Query wall | Peak PHP memory |
| --- | ---: | ---: | ---: | ---: |
| warm 1 (no SAVEQUERIES) | 5,092ms | 608 | n/a | 143MB |
| warm 2 (no SAVEQUERIES) | 5,138ms | 556 | n/a | 158MB |
| repeat with scoped SAVEQUERIES | 5,366ms | 568 | **245ms** | n/a |

Origin PHP time on repeat was roughly 1.1s up through page/header hooks, 0.9–1s through preparation before the loop, **2.4s in Elementor Tour content**, ~0.4s in footer/shutdown.

The matching `_elementor_data` source tree includes `Tour Single Page #45149` → nested widget `f150fa2` → **Tour Content #24784**, with document-specific live rates and other widgets. Under timed traces its Tour Content rendering subtree was **~1.35–1.46 seconds**; the related JetEngine grids added ~0.49–0.51s *exclusive*. The source currently loads ~396 MU PHP files (Wayfa ~432), though runtime OPCache is enabled; do **not** assume the raw count is a duration by itself.

SQL is not the primary hot path: 568 queries total ~245ms. Slowest single timed query ~13ms; major repeated query families were short WP options, WPML translation lookups and taxonomy parents. Do not choose another broad MySQL index/Redis flush as a substitute for cutting Elementor widget execution and frontend reflow.

Scope: this report does **not** claim Origin <1 second. A safe long-term solution must split/cache only verified **public, noncommercial** fragments of `24784`, keep prices, Form 6, nonce, visitor country/nationality, tax and session state evaluated live, and require SSR/AJAX parity before deferring any JetEngine grid. The existing safety plugin `mad4b-rates-elementor-dynamic-cache.php` intentionally disables full-document Elementor caching when `tour_rates` exists because past caching removed rate children. **Do not remove that guard or serve a shared Tour HTML/rate fragment across commercial contexts.**

## Direct Chrome 3x CPU, Wayfatours ES Tour #60623
After removing the 1,457-node duplicated Form 6 spacer (merged before this fix), the first-party Elementor Sticky lifecycle still recalculated viewport geometry immediately on **every** scroll event:

`var onWindowScroll = function() { checkPosition(); };`

Browser-only reversible A/B (same page/viewport/scroll sequence, sequential baseline/RAF/RAF/baseline):

| Arm | Main-thread long tasks | Total blocking estimate | Longest task | Booking/Form6/Menu |
|---|---:|---:|---:|---|
| baseline | 28 | 2,308ms | 431ms | intact |
| RAF coalesced | 24 | 2,221ms | 390ms | intact |
| RAF coalesced | 26 | 2,275ms | 412ms | intact |
| baseline | 28 | 2,658ms | 466ms | intact |

Two samples per arm: average blocking **2,483ms → 2,248ms** (~9.5% reduction). This is a limited test under variable host load, not broad production percentile evidence.

## Patch
New first-party, content-addressed asset:
`wp-content/mu-plugins/assets/mad4b-elementor-sticky-lifecycle-f4c5da1f8939002f.js`

SHA256 **`f4c5da1f8939002f3df3b6ec4754dc57c41bea141281d038132bd59cbd3ca532`**.

The current Elementor/JS function body is unchanged except for:

- schedule `checkPosition()` via at most one `window.requestAnimationFrame` callback per paint; keep the original logic and return values
- cancel an outstanding animation frame and clear its token **on resize and on destroy/reinit**; no stale scroll calculation after teardown
- retain the previously reviewed responsive-hidden/visible Form6 empty-shell Sticky spacer, exact geometry, all other visible Sticky behavior, and Elementor's existing documented lifecycle.

The vendor Elementor library, booking form, prices, Woo gateway, currency, session, JetEngine listing query and existing `407-mad4b-elementor-sticky-lifecycle-guard.php` are unchanged.

The canonical MU source QA manifest pins the exact new digest, preserves the immutable PR #895 original asset receipt, and verifies that ONLY the reviewed `addSpacer`, `onWindowScroll`, `onWindowResize` and `destroy` bounded function segments differ from PR #895. Updated original Form6 test verifies the new name; new hermetic Node test asserts one scroll check per RAF, resize/destroy cancellation and retention of Form6 safety.

## Site-by-site verification and rollback
Installed on Tours.dev first, then Wayfatours, **same SHA**. Browser tests (English/Spanish, 390px mobile and 1366px desktop, scroll deep, normal menu) passed HTTP 200, 44 Mega Menu cards, Form 6, price, native TOC, zero duplicate Form6 and zero JS errors in tested samples. LiteSpeed built a content-addressed bundle from the new first-party JS, but the cached Tour HTML held legacy bundle references until **305 published Tour paths** were versioned with native `MAD4B_Cache_Control_V1::socket_purge`: 221 Tours.dev +84 Wayfa. The versioned batches all succeeded; **no global CSS/JS/Redis purge**.

Backup copies of exact predecessor JS, on both companies:
`/opt/mad4b-backups/sticky-scroll-coalesce-20261124/{source,wayfa}/original-9ebb.js`.

Rollback: atomically restore original filename `mad4b-elementor-sticky-lifecycle-9ebb20e0bb022722.js` with its exact SHA256 `9ebb20e0bb022722b5b10cf9bc7372edfcd80bd26671498f81208f67f127899d` and remove `f4c5...` from the MU assets path, so Elementor 407's content-addressed glob sees exactly one candidate. Then only version affected Tour HTML paths. Do not disable release owner attestation or alter CSS V2 certificates.

## Open truth
The **real logged-in user-admin Chrome session** has not been exercised because Chat mode only permits read access to the connected browser. Neither this patch nor the prior menu memo eliminates the remaining ~4–5s Origin PHP bottleneck or every long main-thread task. Completing root-cause redesign of Tour Content #24784 requires explicit, separately proven dynamic-fragment boundaries and preservation of commercial runtime behavior.
