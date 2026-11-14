# MAD4B native Elementor device-mode first-paint cache — dual-site release record

## Root cause and exact native owner

Native Elementor `elementorFrontend.getCurrentDeviceMode()` calls `getComputedStyle(#elementor-device-mode, '::after')` for each responsive setting lookup. Chrome CPU sampling identified this as one of the largest synchronous functions during native Elementor widget initialization. The same homepage called it **1,355 times** at 820px, with significant forced style/layout overhead. LiteSpeed bundling is *not* the only cause of main-thread load; Tours.dev with JS combining disabled still had high TBT.

The patch adds a single **feature-gated hook** to the existing `406-mad4b-shared-performance-parity.php` owner and reuses Elementor's method. A tiny inline JavaScript adapter retains the *native result for no longer than one animation frame*, revalidates width/height/devicePixelRatio and invalidates on resize/orientation/visibility/pageshow/pagehide. Elementor still handles all widget handlers and responsive breakpoints. No global `getComputedStyle` override, no patched Elementor plugin, no separate slider, no new external JS fetch and no change to LiteSpeed combine/defer options.

## Release coverage and company profile

Enabled on **both** `tours.dev.mad4b.com` and `wayfatours.com`, WordPress public EN/ES homepage routes `/` and `/es/`. The existing site owner decides whether the page is the company's translated home; no activation on unrelated pages, admin, Elementor edit-mode or non-admitted companies. The default is **off**. Each company opts in via `mad4b_perf_elementor_device_mode_frame_cache_v1 = on` and an independent exact source hash in `mad4b_perf_elementor_device_mode_frame_cache_sha256_v1`.

- Shared JavaScript asset SHA-256: `336e797e1327d5f55fec91f1d8e8b33401b1968544daf5e1b20ceebf29e4e4a3`. Source is `wp-content/mu-plugins/assets/mad4b-elementor-device-mode-frame-cache-v1.js`; PHP embeds it as a head inline script before Elementor's native event. The emitted HTML removes just the final trailing newline; executable text otherwise matches the checked source byte for byte.
- Tours.dev patched `406` SHA: `ddc35fe2860e453c0fe9da9d9a0205450e518608040b5f0e2a5a34462d4409a1`.
- Wayfatours patched `406` SHA: `6b95452e862906b09a98916f4eb593ea021a2826894537cbe7b80fa5c27900cc`.
- **The two `406` files are intentionally NOT identical.** Tours.dev uses the source mobile-city preload policy; Wayfatours has a newer company-specific `mad4b_perf_mobile_city_preload_v1` branch. The deterministic `patch_native_elementor_frame_cache.py` applies the same extension to either exact existing source while preserving those ownership differences. The tracked `406` PHP is the **Tours.dev source**; it must never be blindly copied onto Wayfatours.
- Immutable original `406` snapshots are under `/opt/mad4b-company-provisioning/backups/elementor-device-mode-frame-cache-v1-20261114/` on the authorized VPS. The `patch` tool defaults to dry run and requires an exact old SHA + backup on `--write`; original independent Original313 release/signoff was **not** issued.

## Measured improvement and safety

A/B browser instrumentation at 820px on Wayfatours ES, CPU throttled 4x, 1.6 Mbps / 150ms network, reported **1,355 original method calls → 8** for the identical set of 1,355 responsive lookups. In the first matched pair, TBT was **781ms → 534ms**, CLS stayed **0.005004**. A further pair varied (native 1,220ms and cached 879ms), and other runs shifted under host contention; therefore **no stable site-wide TBT score is certified**.

Live browser checks after activation:

| Site / viewport | Cache | CLS | TBT (one run) | Functional gate |
|---|---|---:|---:|---|
| Wayfatours ES 820px | HIT | 0.005004 | 536ms | Native mode matches source on 390/820/1024/1440px |
| Wayfatours EN 390px | HIT | 0.000654 | 663ms | Mobile native mode and CLS |
| Wayfatours ES 1024px | HIT | 0.03578 | 534ms | Tablet-extra native mode and CLS |
| Tours.dev ES 820px | MISS | 0.000455 | 927ms | Native mode matches source on 390/820/1024/1440px |

Independent site-neutral Playwright verification subsequently passed on both sites with full rendered JS SHA and responsive-mode parity, including Wayfatours ES 820px TBT **448ms** and Tours.dev ES 820px TBT **890ms**. EN/ES public HTML has a single original-admitted inline script; there were no JavaScript errors or horizontal overflow. The existing mobile hero, search popup, and date picker continued to function on both companies.

Hermetic test: `node ops/company-code-release-v1/tests/test_elementor_device_mode_frame_cache.js` passed **13/13**, including same-frame reuse, next-frame invalidation, viewport and devicePixelRatio changes, hidden tab, preview bypass and native source truth. PHP syntax passed both live PHP environments. Live gate: `node ops/company-code-release-v1/tests/verify_elementor_device_mode_frame_cache_live.js https://wayfatours.com/es/ 820 <asset-sha256>` with Playwright/Chromium. It makes no production changes.

## Known bottlenecks and safe rollback

- **TBT remains above the excellent Lighthouse range** in some runs. Other Elementor, jQuery, Swiper and third-party functions still execute on the main thread. Do not disable their handlers or force LiteSpeed JS Combine OFF without isolated, paired CSS/JS/interaction testing.
- Tours.dev often serves cold/MISS HTML, and VPS had load average >14, 8GB swap used, many long-lived headless browsers and concurrent WP-CLI tasks. Do not interpret a one-run TBT difference between sites as a controlled A/B. None of these unowned processes were terminated during this work.
- Company can independently rollback *without replacing code*: set `mad4b_perf_elementor_device_mode_frame_cache_v1` to `off`, then invalidate only homepage `/` and `/es/` through `MAD4B_Cache_Control_V1::socket_purge`. If native PHP rollback becomes necessary, compare current source SHA with this release receipt and restore the company's own original `406` snapshot, not the other company's file.
- No signed Original313 receipt, automated fleet rollout, independent Lighthouse certificate, or guarantee that site-wide TBT has reached 200ms was issued.

Detailed hash evidence and measured data: `ops/company-code-release-v1/reports/native-elementor-frame-cache-dual-site-20261114.json`.
