# Safe Public HTML Cache Purge v1.0.1 — 8 November 2026

## Why this exists
LiteSpeed Cache's native **Purge All** on these single-site WordPress installations invokes the vendor's Object Cache flush; Redis-enabled LiteSpeed's `Object_Cache::flush()` calls `Redis::flushDb()`. The native action is distinct from Nginx FastCGI cache and cannot guarantee FastCGI invalidation by itself. The site-local Nginx FastCGI layer keeps 30-minute public HTML routes, separate from the Redis object cache.

## Fixed design
- `MAD4B_Safe_Public_HTML_Purge_V1::purge_public_html()` uses **only** LiteSpeed's `Purge::purge_all_lscache()`, never its general `Purge::purge_all()` and never `wp_cache_flush()`.
- Native `litespeed_purged_all_lscache` event emits a fixed, site-scoped, owner-validated file signal; a separate systemd host worker validates the trigger and changes **only** the site's global FastCGI key epoch in its bind-mounted Nginx configuration, checks `nginx -t`, reloads and records a readback.
- Existing FastCGI per-path `auth_request` .generation logic and scoped LiteSpeed URL/Post bridge remain unchanged. The global epoch is appended to existing FastCGI keys with an initially empty value (no key churn on installation). Its update changes the key space without deleting stored files or flushing Redis.
- The server-side worker retains the original config-file inode (Docker bind-mounted file), preserves the `map_hash_bucket_size` ordering on Wayfatours, and rolls back on reload failure.
- Tools → **Safe Public HTML Purge** uses Administrator capability and nonce and displays the verified host receipt. LiteSpeed admin screens warn that their native **Purge All** still includes Object Cache.
- Internal MAD4B-owned full purge callsites are replaced with `do_action('mad4b/public_html/safe_purge_all', '<scope>')`. The Site Control Plane LiteSpeed adapter's `purge_all()` now returns `scope=public_html_only` and **queued**, rather than claiming a global purge. The taxonomy-count repair path performs its native selective term/taxonomy cache cleaning, then requests public HTML only, without calling `wp_cache_flush()`.
- Passive callsite guard scans MAD4B MU/plugin module and adapter sources every 30 minutes for native full/object purge callsites, and alerts on any newly introduced invocation. This does NOT scan or intercept vendor-owned calls.
- Source's Redis warm timer retries opportunistically with both WPCLI and 313/CPU-budgets still enforced; admission denials remain non-failing deferred cycles.

## Proved in live environments
- **Tours.dev Staging:** Native LiteSpeed page-cache-only purge requested from actual WordPress; host epoch worker successfully rotated Nginx global key and verified `nginx -t`, reload, persistent receipt and WordPress readback `status=ok`; homepage 200. Source Global Epoch changed once as part of explicit staging QA.
- **Wayfatours Production:** identical safe plugin v1.0.1 installed and activated; Nginx global map configured and validated with **empty epoch**, HTTP 200. The real broad Production epoch rotation was intentionally NOT performed. Separate **per-URL** LiteSpeed→FastCGI bridge had already passed live QA on both sites.
- Full set of **16 source-backed MAD4B callsite edits** deployed (7 source modules + 6 Wayfa modules + 2 company control adapters + 1 taxonomy service fix). Patches and backups are preserved.
- PHP mock vendor contract passed in both containers: exactly page-cache-only path called, native full vendor purge call count zero, symlink trigger rejected.
- Cache Control readiness 14/14 tests PASS, live PASS 2/2 with Nginx epoch and Safe HTML module hash integrity PASS. Scope guard selftest PASS and current scan 0 direct dangerous callsites.
- Pricing Cache observer continues to pass, checkout private/no-store, Nginx validates both.
- **Do not claim FLUSHDB is fixed for all system callers:** Redis provenance previously counted 112 source and 56 Wayfa and recorded separate unattributed historical activity. Native LiteSpeed full vendor button, other plugins, and manual WP-CLI can still flush Redis. The new safe path does not issue Redis commands; preserving Redis under all external callers needs separate provenance evidence and controls.
- Source Redis warm 2/12 was previously deferred by WPCLI and 313/CPU admission. Increasing retry frequency is NOT a 12/12 completion claim. 
- Existing Page Dependency graph unknown-coverage nodes are not auto-invalidated; safe sitewide public HTML purge is explicitly different from normal one-tour scoped invalidation.

## Admin URLs
- Tours.dev: `https://tours.dev.mad4b.com/wp-admin/tools.php?page=mad4b-safe-public-html`
- Wayfatours: `https://wayfatours.com/wp-admin/tools.php?page=mad4b-safe-public-html`

## Rollback
Require authorized deployment review first. Stop `mad4b-public-html-epoch@{tours-dev,wayfatours}.timer`; remove the new Safe HTML MU plugin and undo the 16 owned callsite changes from the captured exact backups after SHA comparison. Restore each Nginx file **in place** from host-specific pre-epoch backup, preserving its bind-mounted inode; verify `nginx -t` and reload. Restore monitor pins as appropriate. **Never** edit vendor LiteSpeed Cache code, erase Redis, or delete the Nginx HTML cache to roll back this module.

## Local VPS evidence
`/opt/mad4b-company-provisioning/reports/litespeed-fastcgi-coherence-20261108/global-epoch/`

The pull request remains Draft until CI confirms and explicit review is complete; do not conflate draft PR status with completed live deployment.
