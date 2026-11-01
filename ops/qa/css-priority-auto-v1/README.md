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
