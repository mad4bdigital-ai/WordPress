# MAD4B CSS Discovery Dirty-Race and Deduplicated Job Root Fix — Staging, 2026-11-06

## Deployment boundaries
Live source: `tours.dev.mad4b.com`, WordPress `staging`, PHP container `php-wkgtkzta35wkkgouwt6ruljc`. Authorized operator channel: `registry.infra.mad4b` using key alias `shell_access`. **No Wayfatours Production code/configuration or PR master merge was changed.**

## Root causes reproduced
1. `MAD4B_SCP_Adaptive_Asset_Intelligence::mark_dirty()` previously returned early on a deduplicated pending Job. The new content change was **not** advancing CSS epoch or scheduling Discovery.
2. `MAD4B_SCP_Adaptive_Presentation_Discovery::scan()` previously blindly wrote `DIRTY=0` on completion, which could erase a newer concurrent content-change marker.
3. Consumers treated `DIRTY <= scanned_at` as clean. A scan completed later than a newly queued change could incorrectly show `observed`, **even when the Dirty marker was nonzero and unacknowledged**.
4. Tests had incorrectly assumed registration of the EN mobile CSS variant also cleared independent desktop source-bundle Drift. It does not; a desktop bundle `b70803f4fcdea069c768ca03efc863b6.css` remains outside the route manifest and must continue to be reported.

## Staging live fixes
- `mad4b-adaptive-asset-intelligence`: deduplicated Jobs refresh existing queue rows **and still bump CSS epoch and queue Discovery**. A nonzero Dirty marker is monotonic across same-second events.
- `MAD4B_SCP_Adaptive_Presentation_Discovery::scan()`: snapshot captures starting Dirty; final acknowledgement uses **conditional SQL compare-and-set against the exact original option value**. If another writer changed the marker while the scan ran, it remains pending and a follow-up scan is scheduled. Option-cache invalidation and the existing policy-update hook run only after a successful acknowledgement.
- `Discovery::status()`, `MAD4B_Company_Performance_Intelligence`, `415-mad4b-critical-css-orchestrator.php`, and `mad4b-persistent-site-shell` now interpret **nonzero Dirty as pending**, not a timestamp comparison with `scanned_at`.
- Exact source files and portable plugin bundles are included on Draft PR #763. Temporary `.candidate` JavaScript is excluded.

## Live and isolated QA
- **Isolated no-DB duplicate test PASS**: two `mark_dirty` calls for the same source event yielded one queued Job, `deduplicated=true` on the second call, CSS revisions 6→7, one scheduled scan, and `refresh_queued` even with a snapshot time in the future.
- **Actual Staging WP-CLI concurrency test PASS**: injected a new `queue_refresh()` during Elementor's `_elementor_data` SQL discovery query. The first scan kept Dirty and returned `refresh_queued`; second scan returned `observed` and cleared Dirty. Queue length did not change, public approved variants stayed 0, and public Async remained OFF. No synthetic public approval was created.
- Existing **adaptive policy tests 19/19 PASS**.
- LiteSpeed Drift tests **18/18 PASS** after device-specific correction. An unrelated unrecognized desktop CSS source variant remains visible as `CSS_BUNDLE_NOT_IN_CERTIFIED_MANIFEST`; mobile canary registration must not suppress that warning.
- PHP lint PASS for all five modified runtime files.
- HTTPS homepage health check HTTP 200 for both Tours.dev and Wayfatours.

## Remaining rollout gates
Current public release is **HOLD / native CSS**, `mad4b_perf_predictive_css_v2=off`, `approved_count=0`. Latest readback showed global CSS epoch 27 versus route manifest epoch 26; do not force-equate them or promote a canary based solely on previous English six-pair mobile lab gains. Full multi-language filter/provider and booking interaction coverage, route-specific recertification, and a genuine prior-approved-variant withdrawal through all cache tiers remain pending. Desktop unknown bundle must be reconciled separately.

**Safety:** This change fixes loss of change notifications and false freshness; it does **not** automatically certify CSS, authorize production release, or change marketing pricing/booking data.
