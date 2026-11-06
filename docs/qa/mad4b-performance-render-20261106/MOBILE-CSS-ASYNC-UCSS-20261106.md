# Mobile CSS Async / Critical CSS / UCSS — source QA, 2026-11-06

## Scope and actual activation state
- Exact site: `tours.dev.mad4b.com`, Staging; tested route `/`, language `en`, mobile browser widths 390px and 320px.
- **Working mobile Async + CCSS pilot:** `/?mad4b_predictive_css_probe=1` sends the existing MAD4B `415` local Critical CSS and loads the remaining exact LiteSpeed bundle with nonblocking print-media fallback. The full stylesheet remains accessible as a no-JS fallback. Desktop and other languages stay native; no changes were applied to Wayfatours.
- **Public release remains OFF:** `mad4b_perf_predictive_css_v2=off`, local manifest `release_state=canary_only`, mobile/desktop `performance_gate.status=hold_lcp_regression`. Do not turn on `litespeed.conf.optm-css_async` or `optm-ucss` globally.
- LiteSpeed's native CCSS/UCSS integration cannot currently run: `LiteSpeed\\Cloud::activated()` is false on Staging. MAD4B's own local CSS pipeline is the working pilot.

## Exact resource guards
- Mobile identification is the Nginx-injected `MAD4B_TRUSTED_DEVICE_CLASS`, not a mutable HTTP client header.
- Mobile route and locale, site host, Elementor document-set SHA, exact LiteSpeed bundle SHA, and local Critical CSS file SHA must all match the manifest.
- Canary bypasses only stale *site-wide* discovery/epoch evidence when the exact rendered documents and CSS content match. **Public** runtime retains the stricter discovery freshness requirements.
- CSS file and UCSS candidates are exact bundle variants, not generic classes or a reusable cross-company policy.
- A QA-only `X-MAD4B-CSS-AB` header selects the Native control, Async, or UCSS experiment on the fixed probe URL. It does not authorize public rendering.

## Browser measurements — Slow 4G + CPU 4×
Measurements are per-request lab observations, **not** CrUX percentiles. Server-side TTFB varied, so compare the post-TTFB values cautiously.

| Trial | TTFB (ms) | FCP (ms) | LCP (ms) | CLS |
|---|---:|---:|---:|---:|
| Native #1, before preload repair | 5092 | 9092 | 10296 | 0.00058 |
| Local Async #1, before preload repair | 5975 | 7340 | 11568 | 0.00058 |
| Native #2, after preload repair | 6831 | 10564 | 11716 | 0.00058 |
| Local Async #2, after preload repair | 9031 | 10236 | 14328 | 0.00058 |
| Full CSS preload high-priority pilot | 5380 | 6752 | 11216 | 0.00058 |
| Local UCSS candidate + Async | 5753 | 7044 | 11312 | 0.00075 |
| Local UCSS candidate + high-priority Async | 6097 | 7288 | 11364 | 0.00058 |

After preload repair, Native post-TTFB LCP = 4885ms vs Async 5297ms (about 412ms worse); FCP improved by ~2528ms. Thus **LCP gate: HOLD**. Do not silently promote by only checking FCP.

## Critical asset deduplication
Two separate modules preloaded the same first-visible city image at high priority: legacy `mad4b-city-first-slide-lcp` and the optimized WebP in `MAD4B_Shared_First_Paint_Performance`. `310` now checks for the exact same image stem and the optimized inline first-city CSS; if both exist, it removes only the duplicate legacy preload. HTML readback confirms old=0 and optimized=1. Other routes/assets retain fallback behavior.

## Local UCSS exploratory coverage
- CDP mobile CSS rule coverage with whole-page scroll, four Swiper instances and 320/390px widths: 2543 used rule usage entries, ~356,157 bytes covered in the original ~3,001,622-byte CSS source. This *does not* mean the remaining CSS is safe to remove.
- Conservative CSSOM removal (only absent Elementor document selectors): 332,545B -> 321,193B gzip, 358 removed rules, insufficient benefit.
- Aggressive **QA-only** CSSOM match pruning: 332,545B -> 197,335B gzip, 8545 of 19426 style rules removed. A second exact bundle variant yielded 332,540B -> 197,330B. The candidate had 45 cards, valid Swiper interaction, and zero page JavaScript errors in the sampled mobile home state.
- **Not eligible for Production:** DOM snapshots do not cover all AJAX-loaded filters, popups, every language, keyboard states, or price/booking flows. Public fallback remains native full CSS. The QA-only UCSS CSS artifacts and site-specific manifests are deliberately **not committed** to GitHub.

## Acceptance and follow-up
- Source and Wayfatours home: HTTP 200; PHP lint passed for `415`, `310` and the Control Plane Performance UI. Desktop and French mobile probe HTML contained no Async/CCSS markers; English mobile probe contained both.
- `MAD4B Control Plane -> Performance` exposes the mobile UCSS evidence, current release gate, and original Google CWV worker separately from the performance lab samples.
- To promote from Canary to public mobile use, resolve the first-city LCP/slider paint dependency, achieve multi-sample mobile LCP non-regression and CLS parity under cold first-visit and warm-cache conditions, and verify interactions/viewport across all 6 languages and dynamic booking/filter states.
- Existing Site Control Plane and all non-eligible routes fail open to native synchronous CSS. No Production/Wayfatours rollout was made.
