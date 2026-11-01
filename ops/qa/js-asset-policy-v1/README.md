# MAD4B Adaptive JavaScript Combine — Staging

Site: `tours.dev.mad4b.com` only. LiteSpeed Cache 7.9.2 remains the only JS bundler. No LiteSpeed source modifications. **Do not enable a global defer/delay.**

## Control plane

`337-mad4b-device-js-combine-policy.php` uses LiteSpeed's native `litespeed_conf_load_option_optm-js_comb` and `litespeed_conf_load_option_optm-js_comb_ext_inl` filters, not a second independent JS compressor. The `MU330` scoped leaf defer yields to LiteSpeed on requests where dynamic combine is active. Both CSS optimizers and QUIC VPI retain their separate single-owner contracts.

Independent policy key: `sha256(locale|route)` + `desktop/mobile`. Only `certified` + exact Guest Mode, `mad4b_css_ai_epoch_v1`, LiteSpeed and Elementor version, unexpired cert can affect an ordinary visitor. No route, locale or device fallback. Native is always safe fallback.

## Native/Files/Full isolated A/B

For lab requests only: `X-MAD4B-JS-POLICY: native-v1 | files-v1 | full-v1` **requires both** `X-MAD4B-BYPASS-CACHE: 1` and `X-MAD4B-DYNAMIC-CSS: native-v1`. Those existing Nginx staging guards bypass FastCGI and emit no-store. They prevent lab HTML from poisoning shared cache.

- `native`: LiteSpeed's saved settings, JS Minify=1, Combine=OFF, External+Inline=OFF, Defer=0.
- `files`: LiteSpeed JS Combine=ON with External+Inline=OFF. This is an **unapproved candidate**, not the default.
- `full`: Both toggles ON. Rejected for now based on first two paired Desktop/Mobile rounds.

## 2026-11-01 measured outcomes

Two browser visits per device/variant, with Guest Mode disabled temporarily to avoid its distinct `guest.vary.php` reload. Guest Mode restored to ON afterwards. Median values are illustrative and **not statistically sufficient for release**:

- Desktop baseline JS eval ≈1319ms, task ≈3242ms; full combine ≈1483ms/3340ms; files-only ≈924ms/2191ms in recorded samples.
- Mobile baseline eval ≈1263ms/task 3204ms; full combine ≈1399ms/3383ms; files-only ≈1082ms/2677ms, but LiteSpeed didn't emit the expected combined bundle in observed Mobile HTML. Do not claim a proven Mobile benefit.
- Some Elementor/JetEngine listing DOM counts varied by one element. Guest Mode first-visit parity checks were inconclusive due overlapping browser sessions. No variant was promoted.

## Verification and rollback

`test-js-policy.php` tests isolated canary, no-cache requirement, production/cross-site exclusion, device/Guest/epoch scope, global-delay conflict and certified-only logic. `test-js-policy-engine.py` enforces five paired observations, critical globals, loaded JS, Main Thread and post-TTFB LCP parity, CLS, DOM, and fail-closed incomplete evidence. `js-benchmark.js` collects dynamic evidence using Playwright under local VPS resource limits; `js-policy-engine.py evaluate` returns a verdict without changing WordPress. `apply` is restricted to completed passing evidence. Existing WP notifications record a HOLD for the unsuccessful full combine trial.

Do not activate the full variant by default. Always check actual executed bundle on each device; device cache separation does not by itself mean LiteSpeed executes the same optimization on both.
