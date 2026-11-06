# Current LiteSpeed CSS Bundle — Source Reconciliation and Mobile Canary QA

**Date:** 2026-11-06 UTC  
**Site:** `https://tours.dev.mad4b.com` — **Staging only**  
**Access:** authorized `registry.infra.mad4b` VPS SSH alias `shell_access`, WordPress container `php-wkgtkzta35wkkgouwt6ruljc`.  
**Status:** **CANARY ONLY / PUBLIC HOLD**. No Wayfatours Production changes, no repository master merge.

## Source-drift diagnosis and exact variant

Current live LiteSpeed combined stylesheet `753c892e7e8c5151228b4458057f42cf.css` is **3,002,020 bytes** with SHA-256 `ec6e183f54b276773619a41ecc53bda6a0de45290a50544960793ffc4d192d46`. Compared with the previously pinned `ffd870f3b090eac2bd2129fdcf3f6cdc.css`, **all 21,831 previous CSS rule fragments are preserved** and **eight new rule fragments** were added (+408 bytes), relating to `mad4b-policy-*` and `mad4b-customer-policy`. These results are from exact rule-fragment multiset comparison, not an inferred URL match.

The staging `wp-content/mad4b-predictive-css-v2/manifest.json` was backed up then atomically updated to include a new exact source-SHA **mobile canary** variant. It references the existing, SHA-checked critical CSS `1877af7c229630106379c2346166b750e9d6333fe422b38c02d177f600c0d3ee.css`; this critical CSS is **reused after append-only source comparison**, not regenerated from current Chrome CSS coverage. Variant `performance_gate.status = pending_current_bundle_lcp`; `verified_visibility_via_native_render=false`. **Do not mark it certified without new live coverage.**

Mobile 390px raw HTTP: both Native and Canary return HTTP 200. The native response has zero MAD4B CCSS/Async markers; Canary has one `mad4b-predictive-critical-v2` inline critical style and one `mad4b-predictive-full-css` async link. Public URLs remain Native.

## Controlled mobile browser comparison (preliminary)

Measured with Chromium, exact separately captured public/native versus canary HTML served from a fixed main-document route, live CSS/images/JS loaded from staging; browser network emulation ~220KB/s throughput, 150ms latency, CPU throttling 4x. Measurements recorded after ~19 seconds to ensure 45 JetEngine cards and Swiper were loaded in both modes. The initial browser test (which accidentally bypassed the fixed HTML route) was discarded; the corrected harness explicitly asserts Canary CCSS/Async markers. This is **NOT** a cold real-browser server TTFB test, CrUX, or six-pair acceptance study.

| Viewport | Mode | Lab TTFB ms | FCP ms | LCP ms | CLS | Cards | Swiper | JS errors |
|---|---|---:|---:|---:|---:|---:|---|---:|
| 390 × 844 | Native | 108 | 4,836 | 10,180 | 0.00075 | 45 | responsive | 0 |
| 390 × 844 | Canary | 880 | 2,696 | 8,328 | 0.00058 | 45 | responsive | 0 |
| 320 × 740 | Native | 147 | 4,616 | 5,112 | 0.00194 | 45 | responsive | 0 |
| 320 × 740 | Canary | 182 | 1,724 | 4,796 | 0.00101 | 45 | responsive | 0 |

For this **single pair per width**, post-TTFB LCP deltas (Canary minus Native) were **−2,624ms** at 390px and **−351ms** at 320px, while post-TTFB FCP improved ~2.9s for both. The 390px LCP element is the first destination background; at 320px it is the home video poster. Both variants had 202 listing links and a functioning Swiper slide-next in the final CPU-throttled runs. These are **exploratory observations**, not a release verdict. More AB/BA repetitions and explicit cold/warm device tests remain necessary.

## LiteSpeed cache invalidation

The native LiteSpeed `litespeed_purge_url` action was invoked via Staging WP-CLI for the tenant homepage, and the LiteSpeed plugin returned `Success: Purge url /`; the hook was registered. The site had **zero previously approved CSS variants**, so no legitimate prior approval could be withdrawn. A proposed synthetic prior approval fixture was rejected before execution; no such fixture was installed. **Therefore native purge dispatch was tested, but complete prior-release revocation + cache-hit readback was NOT tested.** Do not report that stronger claim.

## Remains blocked before public release

- 6 paired A/B measurements with 3 cold + 3 warm runs, >=2 mobile viewport widths, reliable full-page completion and visual parity, repeatable native/Canary comparable content.
- Follow-up current-bundle Chrome CSSOM coverage; the direct live browser coverage attempt timed out, so the saved critical CSS is still a canary reuse, not current-viewport certified coverage.
- All six WPML languages, Swiper and JetEngine/JetSmartFilters dynamic interaction, popups and WooCommerce checkout/booking state.
- A genuine previously approved-variant withdrawal tested through the complete cache layer, preferably on a safe dedicated Staging route.
- Recompute MAD4B CSS evidence for this **current SHA** and confirm LCP non-regression under repeated cold 4G/CPU and warm conditions. Previous source CSS had a mobile LCP regression, therefore do not override the current HOLD based on one new sample.

**Rollback:** Restore the previous staging manifest backup `/opt/mad4b-company-transport/css-drift-regeneration-20261106/manifest-before-current-canary.json` if needed; the global public CSS runtime remains OFF. The tenant-specific manifest is deliberately **not committed** to GitHub. This report documents the provenance of the Staging-only change.
