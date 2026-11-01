# MAD4B autonomous CSS — VPS-local, Staging only

Site: `https://tours.dev.mad4b.com`. WP environment `staging`, UUID `e5e64bca-ed62-48d2-b2b3-dff693cd702a`. No Production mutation and no GitHub/OAuth dependency.

## Cycle

1. Discover public WPML languages and recent published non-sensitive pages with `discover-routes.py`.
2. Under `flock` and low CPU priority, run Chromium `css-scout.js` for the selected route at desktop and mobile viewport sizes. Rules matched in either first frame, sticky/header/swiper/price/navigation/commerce families or ambiguous CSSOM rules are protected.
3. `policy-engine.py stage <path>` checks source SHA-256 and page/locale identity, and records only reviewable noncritical CSS handles in a local manifest (`status=candidate`).
4. `css-benchmark.js <path> 3` runs three paired native/candidate comparisons for each viewport, measuring HTTP/CSS load errors, render geometry, CLS/FCP/LCP and mobile scroll state.
5. `policy-engine.py verify <path>` promotes only PASS to `status=certified`; otherwise records HOLD, expires the candidate, learns failed combinations and explores smaller subsets at the next turn.
6. PHP MU335 reads only certified, unexpired, Staging-bound, source-hash-matching policy before splitting styles from LiteSpeed. Original CSS stays available and is never deleted.

The local root cron runs once per hour at :43 UTC. `nice=15`, I/O idle, Chrome capped at 1.5 CPUs and 1.5GiB RAM, and max execution 260 sec. This is a self-governing *policy*, not a claim that every CSS file or page improves. Unsafe or uncertain pages remain native.

## Commands

- Current status: `python3 policy-engine.py status`
- Read verdict: `cat last-verdict.json`
- Log: `tail -40 cycle.log`
- QA smoke: `./local-cycle.sh smoke`
- Emergency rollback: `./emergency-disable.sh plan` then `./emergency-disable.sh apply` if needed

Nginx Staging-only config `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/stack/nginx.conf` bypasses FastCGI cache for test headers and advertises no-store; saved previous config snapshots are in QA. Never remove this guard while running browser experiments. Existing MU334 rollout option remains 0.
