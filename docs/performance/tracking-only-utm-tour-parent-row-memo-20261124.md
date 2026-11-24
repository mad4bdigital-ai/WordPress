# Tracking-only UTM GET: request-local canonical Destination parent-row memo

Date: 2026-11-24. Scope: the existing file `020-mad4b-company-country-operating-scope-v1.php` on Tours.dev and Wayfatours. This branch starts from the exact head of PR #1067 and includes its original production-tested read-only Tour parent-row cache behavior.

## Root cause and bounded change
The existing canonical taxonomy-parent row memo correctly admits ordinary anonymous/cookie-carrying Tour-detail GETs, but refused any query string. Real campaign links such as `?utm_source=...` thus issued thousands of repeated immutable `SELECT term_id,parent FROM term_taxonomy` reads for the same term IDs. These SQL parent rows do not vary with UTM source or the PHP session, but sale permission and public publication decisions must **never** be memoized.

One helper, `read_only_tracking_query()`, admits **only** <=12 scalar, <=256-character tracking parameters whose names match `utm_[a-z0-9_]{1,60}`, `gclid`, `fbclid`, or `msclkid`, and only within the already bounded public Tour-detail GET `$read_only_tour_display` row memo branch. It does **not** change the strict `$safe_public_get` branch, WPML mapping refresh, country term metadata re-evaluation, all final public/paid/authorization decisions, or any business logic. Unknown parameters, date/booking/filter/price query parameters, POST, admin, AJAX, REST, CLI, Cron, preview, bearer auth and nonce-bearing requests retain fresh row reads.

## Test and live proof
- Both site original first-party live file hash: SHA256 `78a78978ed12b75057d17ca016abed4f4060560b47cb3875a8aa1a4e42ff835a`; candidate installed identically on both: `2411bd78f428464965f1db8da52dd9fde7a2ee0078bdd61425b7792d01f18ec5`.
- 16 hermetic PHP 8.5 cases passed, including Tour tracking allowed, tracking query with date rejected, nested tracking arrays rejected, tracking with authorization rejected, tracking within AJAX rejected, and the 11 original safety cases. `php -l` passes both sites.
- Source first: one public Tour #60623 GET with `?utm_mad4b_perf=1` and unknown benign cookie/guarded profiler: overall DB count **4,105 before / 1,018 after**; Country Scope-origin traced SELECT calls **3,041 before / 64 after during wp_head** (plus 7 during template_redirect). This is one diagnostic sample with additional profiler overhead, not a production traffic percentile.
- Wayfatours after staged rollout: analogous full DB count **2,261 before / 1,203 after**.
- Request time does **not** meet Origin TTFB < 1s: source profiled ~6.997s before / ~5.447s after; Wayfatours ~4.869s before / ~4.994s after under different host conditions. Do not claim an unconditional latency gain.
- Without tracking parameters, canonical Tour #60623 already used the original row memo and measured approximately 635 SQL on Tours.dev / 828 on Wayfatours. This amendment does not solve normal canonical Elementor CPU costs.
- The PHP source file of PR #1067 was string-identical to the live original before amendment. The amended PR may supersede #1067 after governed merge, but #1067 must not be merged independently afterward without checking current master.
- Site-specific rollback copies are under `/opt/mad4b-backups/origin-utm-raw-parent-memo-20261124/{source,wayfa}/original.php`. No plugin activation changes, pricing policy edits, CSS/JS/Redis/fastcgi purge, or database schema/record writes were performed in this amendment.

## Open incident requirements
Further work is needed for Elementor template 24784 and Mega Menu SSR CPU (canonical Origin processing 4–5.5 seconds in measured samples), a genuinely reproducible authenticated WordPress user Chrome interaction trace, and user-owned GitHub exact-head release approval on the final commit. Any caching of market/booking/price data is outside this patch.
