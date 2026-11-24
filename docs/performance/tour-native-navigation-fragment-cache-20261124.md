# Source-first native Elementor Mega Menu render memo

Date: 2026-11-24 | WordPress sites: Tours.dev (source) and Wayfatours (target)
Status: Live on both sites; review branch independent of release-authority bypass.

## Root cause

Original Tour #60623 cold Origin profiling consistently identified Elementor Mega Menu element `6072b3f` as a main CPU consumer (approximately 1.1–1.4 seconds, often hundreds of JetEngine queries). The source site already has Elementor native Element Cache active (TTL 1 hour). However its Header document `26212` stores **14 dynamic `[elementor-element]` shortcodes**, including the *entire* Mega Menu with nested JetEngine listings. Elementor re-renders this shortcode on each Origin execution; increasing native cache TTL does not fix it.

The native Mega Menu output on Tour #60623 was approximately 226–236 KB, included **44 listing items**, and had no form, nonce, cart or price strings in inspected sample. Its markup remained structurally stable across normal/unknown-cookie requests; a separate route had different active-state markup, requiring strict page-key isolation.

## Implementation

New first-party MU plugin: `wp-content/mu-plugins/449-mad4b-native-nav-fragment-cache.php`.

- Intercept `pre_do_shortcode_tag` only for `elementor-element` where the signed native Elementor shortcode unique ID matches, decoded JSON is exact widget `6072b3f`, widget type is `mega-menu`, and semantic CSS class includes `navigation-editor-menu`.
- Admit only frontend singular `tours-and-activities` GET with no query parameters, body, Authorization, HTTP WP nonce, preview, admin, REST, AJAX or CLI. All other contexts use native shortcode rendering.
- Retain normal callback and `do_shortcode_tag` filters. On a MISS render the original callback and capture newly enqueued WP scripts and styles; on HIT serve only the captured widget HTML and re-enqueue the exact assets.
- Cache **only the Mega Menu fragment**, never the full page or any tour pricing, commercial decisions, Form 6, cookie responses, payments or WooCommerce checkout.
- Cache key contains the source shortcode data digest; site URL + host + theme; queried Tour ID; WP locale + WPML language; user ID and digest of the logged-in session token; hashed raw inbound cookie and PHP cookie state; browser/accept-language/device; visitor IP/forwarding/GeoIP and relevant market headers; origin and referer. Never reuse an anonymous cache entry for another authenticated user/session. Fail closed if a logged-in session token is unavailable.
- Safeguard against any HTML with `nonce`, WooCommerce, cart, price, form, user/admin, checkout, payment, email or customer markers, missing navigation class, unreasonable size, or a corrupt stored content SHA digest.
- Redis/object-cache group is isolated (`mad4b_native_nav_fragment_v1`), TTL **60 seconds anonymous / 15 seconds logged-in**, and caches nothing on failure. No global cache purge needed.

## Focused verification

Hermetic PHP guard test `tests/performance/test_native_navigation_fragment_cache.php`: **21 PASS cases**, including first MISS, same-context HIT, script/style replay, cookie and GeoIP isolation, per-user/session separation, logged-in missing session denied, admin/AJAX/preview/other page/POST/date query/Authorization/nonce bypass, wrong Elementor ID/signature rejection, and sensitive payload rejection. PHP lint successful on PHP 8.5.12; code avoids `str_contains` for PHP 7.4 compatibility. WordPress/Elementor in-place browser regressions succeeded.

On a single guarded unknown-cookie Tour #60623 run at different times under variable VPS load:

| Site | Cold before fragment ready | Origin with fragment hit, sample 1 | Origin with fragment hit, sample 2 | Widget work MISS vs HIT |
| --- | --- | --- | --- | --- |
| Tours.dev | 5.751s | 3.786s | 3.940s | ~1088.7ms → 0.40/0.38ms |
| Wayfatours | 5.063s | 3.729s | 3.968s | ~1254.2ms → 0.30/0.53ms |

A separate controlled canary A/B before normal deployment on source measured 5.712s / 4.053s / 4.026s (MISS/HIT/HIT), and external JS/CSS references were *identical*: 295 URLs and no missing or new assets.

Playwright Chromium source and target, desktop 1366px + mobile 390px: HTTP 200; **44** menu cards, 6 main nav items, 16 nested tabs; 40–41 Form 6 fields, price and TOC all present, normal menu click works on mobile, 0 uncaught JS errors and 0 failed asset responses. Source options ~255, Wayfatours 249–253. The test-only Canary MU plugin `448` was removed before normal deployment.

Exact production file SHA256 on both: `9ff21bbdcfbc082d2e8d55f2c07fc80ab32a2b14e9d5187a21af47f6e3bca26c`.

## Limits and rollback

An Origin HIT near 3.7–4s is **not** the target below 1s. Tour Content template `24784`, post-specific dynamic rates, PHP init/asset queues and frontend JS still need separate work. The automated public browser does **not** exercise the user's existing logged-in WordPress session; an authorized Work/Computer Use browser test remains necessary before declaring Chrome `Page Unresponsive` fully eliminated.

Rollback: remove only `449-mad4b-native-nav-fragment-cache.php` from the affected environment. The Elementor original shortcode callback takes over immediately; existing cache entries expire in at most 60s and are never read without the plugin. Do not globally purge Redis/LSCache/FastCGI/CSS V2. Backup of the temporary canary only: `/opt/mad4b-backups/nav-native-ssr-fragment-20261124/source/448-probe-original.php`.

Release changes must pass exact-head CI, owner attestation and repository feature-boundary rules before merge. SSH/root live installation is not itself a signed release receipt.
