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

## Native WordPress integration and notifications

- Active module: `wp-content/plugins/mad4b-adaptive-asset-intelligence/` (independent from the Control Plane release lifecycle; requires MAD4B Site Control Plane for its admin submenu).
- Admin: `MAD4B Control Plane -> Adaptive Assets`. Authenticated `GET /wp-json/mad4b/v1/adaptive-assets` requires `manage_options` or `mad4b_manage_company_operations`.
- Native notifications live in `mad4b_adaptive_asset_notification_feed_v1`. They are visible through the Control Plane submenu, admin bar and dashboard notice. Individual messages can be acknowledged via WordPress nonce-protected `admin-post.php` action.
- Shared **internal** event: `do_action('mad4b/notifications/recorded', $envelope)`, contract `mad4b.notification.event.v1`, category `adaptive_asset_intelligence`. Delivery to email/WhatsApp is OFF; no SMTP or external notification side effects are implied.
- WordPress authoring and source changes enqueue bounded jobs in `mad4b_adaptive_asset_job_queue_v1`, bump `mad4b_css_ai_epoch_v1` to fail closed, and emit `queued` notifications. The local event poll (every 5 minutes) gives those jobs priority over the periodic hourly discovery sweep.
- `notification-bridge.py` feeds `certified`, `hold`, `native` and `failed` verdicts back into the WordPress notification inbox via local WP-CLI, without remote OAuth or a public write endpoint.
- `cache-isolation-check.sh` validates Nginx bypass/no-store, emits a critical notification on failure and updates `mad4b_css_ai_cache_proof_v1`. MU335 refuses dynamic policies if the proof is absent or older than two hours.
- Ordinary visitor requests do not bootstrap management hooks; MU335 alone controls selective CSS loading.

## Acceptance evidence (Staging)

- The native PHP notification contract passed in isolation (scoped staging identity, deduplicated queue and notification events, worker acknowledgment, production and cross-site rejection).
- In WordPress, admin submenu registered, authorized REST GET returned HTTP 200, and an unauthorized request returned HTTP 401.
- Actual `save_post` event hook created a route-specific queued job. The local worker read the exact permalink, recorded a `hold` notification and cleared the queue. Published content was not modified during the hook test.
- Earlier two-file A/B candidate failed the FCP/LCP/CLS gate; it was rejected. Subsequent one-file candidate is NOT certified for automatic site visitors.
- Nginx proof removed: canary deferred CSS count=0; proof restored: canary count=1. Staging-only source config syntax passed.
- The source repository branch is `feat/mad4b-adaptive-assets-notifications-20261101`, Draft PR #704. CI/release acceptance is separate from local Staging installation.

## Real 2026-11-01 five-pair CSS outcome and Guest Mode isolation

- Previous 3-pair CSS-only verdict was a **false PASS** due server TTFB imbalance and a permissive mobile regression threshold. It was removed from public serving immediately.
- A complete 20-observation, five-pair Desktop/Mobile test with LiteSpeed Guest Mode temporarily disabled ended **HOLD**. On Desktop median total LCP 5948ms native vs 5316ms candidate, but post-TTFB LCP 497ms native vs 478ms candidate (**19ms** only). On Mobile total LCP 5344ms native vs 6304ms candidate; post-TTFB 506ms vs 545ms (**39ms worse**).
- Guest Mode was restored to **ON** (original `litespeed.conf.guest=1`), Guest Optimization remained OFF. No CSS candidate has been promoted; ordinary traffic uses native CSS.
- A first-visit Chromium Guest ON test saw a `guest.vary.php` request and an additional Mobile navigation; Guest OFF made one navigation, without the vary request. Guest's UX and real Nginx FastCGI caching must be evaluated separately. Do not extrapolate one guest first-visit sample to a universal speed score.
- CSS Policy now requires **five complete paired rounds**, a page error/network and layout parity check, no Guest Mode first-visit double navigation or guest.vary request, balanced TTFB, post-TTFB paint improvement and no meaningful raw LCP/FCP regression. The policy manifest stores the exact `guest_mode` and `epoch`; switching Guest Mode or source versions makes old rules ineligible.
- Local browser worker's low-priority time budget is 450s total, 360s for the benchmark, 1.5 CPUs and 1536MiB maximum, with `flock`. Partial results never certify a route.
- `guest-first-visit-audit.js` is an informational, read-only first-visit diagnostic. A three-round comparison was not run after its invocation was rejected by safety checks; retain the single-run result as preliminary.

The next optimization focus is server-side Elementor/JetEngine/WordPress generation and cache variation, not increasing complexity for a 1.2KB CSS stylesheet that failed measured acceptance.

## LiteSpeed Cache 7.9.2 local-first compatibility contract

- MU336 (`336-mad4b-litespeed-local-provider.php`) is the staging-only **authority coordinator**. Default `mad4b_lsc_css_provider_v1=local_first`; optional explicit `native` mode restores native LiteSpeed CSS behavior and disables the MAD4B overlay. No LiteSpeed plugin source modifications.
- Native LiteSpeed retains ownership of CSS combining/minification, browser cache and Mobile Cache; Nginx FastCGI caches separate keys by device on supported anonymous routes. MU332 safely tunes the same LiteSpeed CSS combiner to exclude per-request Elementor dynamic selector rules; it is not a competing CSS bundler.
- The legacy MU334 CSS priority overlay refuses to activate when local-first is selected. MU335 may defer an asset **only** if it is independently certified by Desktop/Mobile/locale/route, its source hash matches, cache proof and config epoch are valid, and QUIC UCSS/Async CSS/Guest Optimization do not own the request. Otherwise native styles remain intact.
- The local CLI worker also checks this contract before stage/verify. It does not duplicate LiteSpeed background tasks or submit any QUIC.cloud CSS jobs. Feature flags are observed in WordPress, not rewritten in LSCWP source.
- **Not yet replaced**: QUIC.cloud CDN, remote image optimization, and VPI. They retain their configured LiteSpeed passthrough until a functionally equivalent, separately certified local provider exists. Disabling them without a local implementation would be a regression. QUIC settings alone do not prove the remote CDN actually serves this origin.
- The WordPress admin (MAD4B Control Plane > Adaptive Assets) and authorized REST endpoint expose the provider status and these passthrough capabilities.
- Testing includes `MAD4B_MU336_PATH=wp-content/mu-plugins/336-mad4b-litespeed-local-provider.php php ops/qa/css-priority-auto-v1/test-litespeed-local-provider.php` and the per-device browser-policy contract.

Current measured CSS policies on `/` remain HOLD for both Desktop and Mobile; the 20-observation Guest-OFF benchmark did **not** prove a material post-TTFB render benefit. No claim of reduced TTFB is made.
