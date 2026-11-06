# MAD4B Current CSS — six-pair mobile A/B and first-viewport visual QA

**Date:** 2026-11-06. **Environment:** `https://tours.dev.mad4b.com` Staging. **No changes to Wayfatours Production.**

## Scope and reliability safeguards

Source LiteSpeed combined stylesheet `753c892e7e8c5151228b4458057f42cf.css` has SHA256 `ec6e183f54b276773619a41ecc53bda6a0de45290a50544960793ffc4d192d46`. The current-source manifest has a **canary-only** mobile variant with reused, SHA-validated critical CSS and `performance_gate.status=pending_current_bundle_lcp`.

The initial A/B implementation mistakenly compared native and canary responses that contained different JetEngine trip ordering/text. **Those runs were archived and excluded.** The corrected browser harness pins the **same HTML body bytes** (SHA256 `4e67710bbd2f4cf211d3d71be15db0e5375152bdec5fd820631bfc457d147a29`) for both modes and changes only the live-generated head: Native blocking CSS versus MAD4B inline CCSS + async full CSS. Tests require 45 cards, functioning Swiper, complete document, no JS errors and correct CCSS/Async markers. Runs use Chrome mobile UA, emulated approximately 220KB/s downstream, 150ms network latency and 4× CPU slowdown.

The runner uses a file lock and atomic completion-file replacement so partially written measurements cannot be mistaken for final observations. Earlier contradictory values were traced to reading in-flight results and were **not accepted**.

## Actual six valid paired browser samples

All LCP/FCP values are **milliseconds from navigation start**; reported delta subtracts **each sample's own browser-observed TTFB** (Canary minus Native). Negative delta means Canary improved.

| # | Cache cohort | Width | Order | Native LCP | Canary LCP | Post-TTFB LCP delta | Post-TTFB FCP delta | Result |
|---:|---|---:|---|---:|---:|---:|---:|---|
| 1 | Cold / fresh browser context | 390 | AB | 16444 | 6064 | -10361 | -3577 | Valid |
| 2 | Cold / fresh browser context | 320 | BA | 5424 | 4884 | -750 | -2382 | Valid |
| 3 | Cold / fresh browser context | 390 | AB | 6944 | 4332 | -2300 | -3152 | Valid |
| 4 | Warm / revisited session | 320 | BA | 5476 | 4300 | -1497 | -3021 | Valid, browser cache hit observed |
| 5 | Warm / revisited session | 390 | AB | 9020 | 3680 | -5192 | -3424 | Valid, browser cache hit observed |
| 6 | Warm / revisited session | 320 | BA | 6848 | 4560 | -2018 | -3462 | Valid, browser cache hit observed |

Nearest-rank **p75 post-TTFB delta: FCP -3021ms, LCP -1497ms**. All six samples had zero JavaScript errors; the measured CLS delta (Canary minus Native) did not increase in any pair. These are staging **lab** results, not CrUX field percentiles or an assertion that mobile public Async is ready.

Browser CSSOM inspection at 320px and 390px confirmed that the stylesheet used by Chrome exactly matches source SHA256 above, with 2366 and 2375 used-rule entries respectively; this is first-viewport **coverage evidence**, not a proof that rules unused by dynamic menus/popups are safe to remove.

## Basic visual parity

Separate CSS-settled first-viewport tests used the same pinned HTML body and equivalent 320×740 / 390×844 viewport geometry. Native and Canary both loaded 45 cards, 4 initialized Swiper instances and document fonts, with no horizontal overflow or JS errors.

- The computed layout boxes (header, H1, main slider/grid and featured elements) were exactly equal in x/y/width/height. Their sampled CSS computed properties also matched.
- At 320px, RGB screenshot **pixel content matched exactly**.
- At 390px, the screenshots differed in only **0.001215% of color channels** with mean absolute channel delta **0.000227 on a 0–255 scale**. This is tiny settled-viewport drift, not proof of complete animated/dynamic state parity.
- This certifies only **basic first-viewport geometry**. It does *not* authorize setting every pair's `visual_state=pass` or `interaction_state=pass` for filters, dialogs, bookings, currency, keyboard and WPML routes.

## Policy ingestion and actual release state

Staging WordPress option `mad4b_perf_css_browser_evidence_v2` now preserves **two records**: prior CSS evidence plus new current-source six-pair evidence. `MAD4B_Company_Performance_Intelligence::adaptive_css_decisions_v2()` was corrected to select the **latest exact bundle SHA + Elementor graph + epoch evidence**, rather than blindly taking the first record with matching path/locale/device. The updated WP-CLI policy suite passes **19/19**.

The current chosen record produces:

```
state: needs_evidence
candidate: critical_async
lcp_post_ttfb_delta_p75_ms: -1497
fcp_post_ttfb_delta_p75_ms: -3021
pairs: 6, cold_pairs: 3, warm_pairs: 3
reason_codes:
  - js_or_interaction_not_proven
  - visual_parity_not_proven
  - content_discovery_stale
  - public_runtime_not_enabled
receipt: native_hold
approved_count: 0
```

The discovery warning is grounded: `mad4b_adaptive_presentation_scan_needed_v1` is later than `discovery.scanned_at`, and the route discovery digest differs from the globally discovered graph. **Do not erase this warning** or bypass it for public release without a fresh route-level source confirmation.

Anonymous homepage HTTP/HTML checks were also performed for EN, AR, FR, DE, ES and IT. All six rendered native combined CSS, without MAD4B public Async. These are only smoke checks, **not** six-language Async visual/performance certifications.

## Remaining release blockers / rollback

1. Full functional/visual interaction tests across JetEngine/JetSmartFilters AJAX, mobile menu, Swiper, popups, WooCommerce booking/rate/currency/market states, across all six WPML languages.
2. Refresh the native discovery graph and reconcile the route's actual document fingerprint and current CSS plan without accepting unrelated global drift as a false pass.
3. Verify repeatable current-origin first-visit cache behavior (pinned HTML removes server/edge response-body variation and is not real full-stack first-visit proof).
4. Test genuine prior-approved-policy withdrawal through the complete cache layer, when a legitimately approved Staging variant exists. Native `litespeed_purge_url` was dispatched successfully in previous QA, but that is not the same claim.
5. Keep `mad4b_perf_predictive_css_v2=off`, `release_state=canary_only`, and native CSS as the public fallback. Do **not** propagate this staging-only manifest or CSS to Wayfatours.

**Decision:** **HOLD PUBLIC RELEASE**, despite positive current-source six-pair performance evidence. The remaining gates are functional/visual discovery and policy lifecycle, not FCP/LCP for this constrained mobile test.
