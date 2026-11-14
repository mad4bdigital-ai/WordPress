# MAD4B Native Critical CSS — full intermediate breakpoints v2

**Live production:** Wayfatours homepage, WPML EN (`/`) and ES (`/es/`), verified on 14 November 2026. This record supersedes the historical 768–991px-only admission in `MIDBAND_CRITICAL_CSS_SCOPE_20261114.md` without erasing the earlier readback.

## Native ownership

The existing `MAD4B_Verified_Critical_CSS_V1` PHP runtime remains unchanged. The **only production write** updated two SHA-bound manifest entries and the matching two immutable Critical CSS assets. The existing WP/Elementor templates, full LiteSpeed combined CSS, FastCGI logic, and mobile hero plugin were not altered. Other source CSS hashes and company languages remain untouched. Original313 signature **not** issued.

The first admission covered 768–991px, where missing first-paint header rules and a 47.3125px-to-48px top-bar geometry reservation prevented late reflow. The second covers 992–1024px. Here an initial visible-element-only CSSOM scan had missed Elementor **hidden header nodes** required for responsive navigation; include all descendants of native header containers when collecting CSSOM. The upper band uses the same audited 48px top-bar reservation and conditionally includes 189 EN or 286 ES stylesheet rules discovered from the **actual matching full-CSS bundle**.

The generator `derive_verified_midband_css.py` accepts source hash, original critical hash, browser CSSOM evidence and measured viewport + reservation policy. `--append-to` plus `--append-to-sha` compose an additional QA band onto the **exact currently verified CSS asset**. It refuses changes to source, prior admitted file, unsupported external resources, or out-of-range CSSOM. It **never** writes WordPress options, manifests, caches or approved receipts. The origin runtime's exact-hash manifest and native cache provider remain the sole production selectors. Automated build and fail-closed regression checks pass **14/14**, regular and Python `-O`.

## SHA-pinned production state

| Locale | Full LiteSpeed CSS SHA | Current admitted Critical CSS SHA |
|---|---|---|
| EN | `292adbae92f0762f7e3e053c47e094f67f38d3ca238662d1cec4b6e853805021` | `f169d55325ce1f9c0f98de87c331d8420a674dc59947302bf1e43209c8bdc968` |
| ES | `e18e57ce594076ebea409dd63046abf4b385c8fbc50dfcdbe7f10b6e38fb065e` | `84539358db5448bae3e8a6a38ec55b87d89fcb8ebd8146664cdb776fa732bc01` |

Production manifest: `946930797212564cd29d69c465adcb9c6d3d5faa946525650ed002d852b6b52e`. Previous manifest backup SHA: `284404871a74df7218e3c644b1ceb8a64dd045a0201095bfd53267c41710df9d`. Both homepage paths were invalidated through `MAD4B_Cache_Control_V1::socket_purge` only, then verified with `X-MAD4B-Mobile-CSS-Ready:1`, `X-Wayfa-Origin-Cache:HIT`, and native `critical_css_live_admission_readback.py` status `ready`.

## Browser QA and boundaries

Chromium Android emulation, CPU throttled 4x and ~1.6Mbps network:

| CSS variant and width | EN CLS | ES CLS |
|---|---:|---:|
| Isolated candidate, 390px | 0.015821 | 0.005187 |
| Isolated candidate, 820px | 0.079349 | 0.066192 |
| Isolated candidate, 991px | 0.070478 | 0.071267 |
| Isolated candidate, 992px | 0.041130 | 0.036080 |
| Isolated candidate, 1024px | 0.041551 | 0.036348 |
| **Production 390px** | **0.000654** | **0.000931** |
| **Production 992px** | **0.041028** | **0.035475** |
| **Production 1024px** | **0.041485** | **0.036270** |

On all production QA pages: `HTTP 200`, FastCGI `HIT`, CSS-ready header `1`, full stylesheet loaded, no JavaScript errors, no failed resources in the tracked requests, no horizontal overflow. The live EN/ES popup, interactive date calendar and existing responsive hero also passed after rollout. 820/991px production was verified in the immediately preceding admission; the second band only matches `min-width:992px`.

**Not certified:** independent Google Lighthouse scores and real-user Core Web Vitals; TBT remains variable (1024px EN 1194ms in one production test), and city-card slider produces smaller late CLS (~0.03–0.08). Source CSS regeneration or changes to Elementor/JetEngine/header templates invalidate the source-hash admission until requalified. This report is **not** a fleet rollout or signed Original313 authorization.

## Safe follow-up

1. Resolve the current company/source CSS hashes; re-run browser CSSOM capture including hidden/native container descendants for each affected viewport and language. Never reuse prior CSSOM on a changed source SHA.
2. Run the existing builder with SHA-pinned base, CSSOM and measured reservation. Compose upper band only against a validated previous asset SHA.
3. Run build negative tests and browser regression for 390, 768, 820, 991, 992, 1024 and desktop (if relevant), including native interaction and final layout geometry. For source-neutral live checks use `node verify_critical_css_midband_browser.js https://company.example/page/ 1024 <exact-critical-css-SHA256> 0.1` with Playwright installed and Chromium available. It does no writes and exits nonzero on a missing CSS hash, CLS regression or browser error. Reject CLS regressions rather than hiding them with global CSS.
4. CAS-save production manifest preimage; create immutable hashed candidate assets. Update **only** matching full-source hash entries; leave other site/language/page keys unchanged. Invalidate affected route cache and verify both live `HIT` and CSS byte SHA via the installed native independent readback.
5. On failure, restore the exact saved manifest only if the current SHA still matches this release and selectively invalidate the two routes. Do not delete historic Critical CSS files or flush Redis globally.

**Audit evidence:** `ops/company-code-release-v1/reports/wayfatours-critical-css-midband-v2-20261114.json`. Complete CSSOM snapshots and immutable rollback copy retained under `/opt/mad4b-company-provisioning/reports/critical-css-midband-20261114/` on the authorized VPS.
