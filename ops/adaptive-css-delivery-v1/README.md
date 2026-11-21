# MAD4B Adaptive CSS Delivery — v1

Source: PR #989; site-owned, company-scoped CSS architecture.

## Deployed slice
- Reuse the site-owned MAD4B_CCSS_WPCron_Dispatch_V1::profile() for enrolled company, host and environment.
- First-party Google web-vitals v6.2.3 IIFE pinned by SHA-256; no remote analytics dependency.
- Only after MAD4BConsent analytics opt-in, GPC/DNT checks, and sampled measurement.
- No IP, user identity, raw URL, query, referrer, cookie, browser fingerprint or raw metric records stored.
- Signed REST POST for one site, route SHA, source epoch and device with 2-hour TTL, same-origin and replay caps.
- Histograms for LCP, INP, CLS, and restoration of the complete CSS.
- No changes to existing 421/422 certificates, HTML, FastCGI or visitor CSS.
- Pure source-aware decision engine: no real unqualified CSS promotion merely from user telemetry.
- Per-company WordPress Cron hourly health rollup with daily-window CSS restoration monitoring and 30-day retention; read-only admin REST status. No additional systemd CSS executor.

## Modes
- off (default): no RUM client, beacon or data collection.
- observe: consenting sampled public sessions only, no CSS changes.
- Progressive public canary: not enabled until signed CSS source, verified cache separation, and stable synthetic QA pass. Staged private candidate is not real visitor canary.

## Privacy and data quality
- Metric units: LCP/INP milliseconds, CLS thousandths. Histograms only, 30-day UTC retention.
- CSS restore state metric records 0 for failure and 1 for successful restore.
- The opt-in gateway's analytics choice is checked again immediately before sending.
- A missing consent gateway, private session, query string, checkout or untrusted site context is excluded.
- Site RUM cannot override the original five-view QA or release admission controls.

## Rollout decisions
- Controlled steps: 0, 1, 5, 25 and 100%.
- Require signed source identity, 5-view browser QA, cache cohort isolation and origin release proof.
- Comparable sample minima for ramps to 5, 25, 100%: 400, 800, 1200 per metric and arm.
- Incomplete INP, CLS or LCP populations cannot automatically promote.
- p75 regressions over baseline+25% can recommend reversal after 50 events. A recommendation does not mutate public CSS.

## Acceptance path
1. Syntax and unit tests in the enrolled source and site profile.
2. Install SQL histogram table explicitly through enrolled WP-CLI; never auto-create on public requests.
3. Enable observe at sample_bps=100 (=1% consenting sessions), first on Tours.dev, then on Wayfatours.
4. Verify REST rejects forged/expired source tokens and never collects raw personal data.
5. Verify FastCGI HTML stays Native or certified under previous authority; no cache purge.
6. Only enable public traffic split after an independent, signed cache-cohort/rollback test succeeds.

## Tests
- python3 tests/test_adaptive_policy.py
- node tests/test_rum_js.js assets/mad4b-css-rum-client.js
- php -l 438-mad4b-adaptive-css-rum-v1.php
- cat tests/php-pre.php 438-mad4b-adaptive-css-rum-v1.php tests/php-post.php tests/php-rest.php | php

Vendor web-vitals IIFE is from Google's Apache-2.0 project, pinned to version 6.2.3 and SHA256 1e5e9b9af6b8d71cfef508e5a869c53b4cf2bbccad8c9b5ac664c34e93f6151a.

## Independent adaptive rollout status
- RUM JS URL versioning uses the first-party file mtime so corrected client code is not confused with a stale browser cache.
- Cohort hash includes the trusted mobile/desktop device, CSS epoch, Elementor/global and JetEngine listing generation, and individual post revision; no cross-language or market aggregation.
- The cache isolation scanner is a mandatory fail-closed read-only preflight. On 21 Nov both source and Wayfa report HOLD_CACHE_ISOLATION: active Nginx has no signed public CSS cohort cache separation. User traffic canary=0%; only existing certified Native/CCSS delivery is unchanged.
- The site's public-css authoritative 421/422 files are never modified by the RUM plugin. RUM never creates or issues certificates.

## Verified deployment checkpoint (2026-11-21)
- Tours.dev Staging: mode observe, 100 basis points, signed REST HTTP202 -> actual MariaDB histogram 4 counters, synthetic fixture deleted in a finally clause; source's 421/422 and FastCGI remain untouched.
- Wayfatours Production: additive plugin and pinned local JS installed, sample mode off until independently admitted source WP-CLI schema installation (CPU/WP-CLI guard and worker lock).
- Public CSS Canary: zero percent; both real Nginx profiles fail cache partition and first-party cohort attestation. No unqualified CSS reaches public visitors.
- The 30-day histograms are observational, not anti-spoofed evidence of a controlled A/B test until the signed serving arm is added at the edge.
- The original worker and the existing 5-viewport synthetic QA continue to hold the source-based certificate authority.

## Hourly Core Web Vitals readback
The site-owned hourly REST-health option exposes the conservative p75 histogram upper bounds and sample count for each arm and LCP/INP/CLS; no values are invented for a metric without samples. This shadow report is observational across source routes and cannot promote or revoke CSS certificates. An authenticated manager can inspect /wp-json/mad4b-css/v1/status. The field public_rollout_percent_changed is always false.

## Exactly-once guarded Wayfa schema activation
A root-owned one-time cron entry is installed for the production RUM schema initialization only; it checks the original host CPU budget and acquires the shared WordPress CSS worker lock. It verifies the exact enrolled company, checksums of the PHP/JS source, WP schema readback, and the observe-mode sample basis points. On success it saves a root receipt and disables itself. Any non-resource failure disables future attempts. The configuration expires after 24h. This task **does not** run CSS or Chromium, purge FastCGI, or enable a public canary. It is a schema/observe enrollment and not a proof of CSS source authority.


## Source-fresh telemetry token
The v2 short-lived HMAC proof binds the actual site, route SHA, variant SHA,
device, CSS epoch, JetEngine listing generation, Elementor global generation
and timestamp. Both generations are re-checked on every RUM POST against the
current WordPress source; a stale HTML token fails closed on source changes.
Previously cached v1 tokens are rejected and can simply skip observations.

## Verified production RUM activation and E2E receipt (2026-11-21)
- Wayfatours: exactly-once resource-guarded schema activation succeeded at 12:08:26 UTC. The temporary activation cron self-disabled and retained a root receipt. Observe samples 100bps of eligible consenting visits; live CSS canary remains 0%.
- Wayfatours: signed v2 token POST to the real first-party WordPress REST endpoint returned HTTP202, inserted four histogram counts; synthetic route+variant counters were deleted and verified absent. Test code: tests/e2e-wayfa-rum-v2.sh.
- WordPress runtime files 421/422, v2 certificates, FastCGI cache keys and public CSS remained unchanged by adaptive RUM deployment.
- A few WP-CLI stderr entries about missing FluentCRM/FluentBoards abilities are unrelated to RUM; they do not authorize any change to Fluent components.

## 2026-11-21 CSS native-source pin reconciliation
The original first-party Git blob for wp-content/mu-plugins/422-mad4b-public-critical-css-runtime.php (current PR #989) has SHA-256 13e98efebaef87918d38c1d5be30bd6cf45c9f40c0e3264a31beca79b4663a36, accompanied by V2 consumer 249083d5aa0124ea1280a88f5062945cdff2885eca63b36ba5c66682b0faf3d1 and 421 coverage ae7936c7ad09b059375e1960d4c59144af0b4325933db34bcfa63c26e49233bb. The live host runner was rejecting the first-party Git runtime because its immutable allowlist was behind the approved source. The fix adds the exact Git V2 pair to the original Python runner allowlist, retains legacy approved release pins, and tests refusal of all altered components. Wayfatours preserves local renderer-specific behavior and receives only the surgical patch in patches/wayfa-v2-source-pin.patch. Both site-owned renderer digests and their profiles must be switched atomically behind the existing source/site/shared locks; never replace the live PHP, waive origin QA, or treat observed source bytes as permission.


## CSS actual delivery / source revision audit (2026-11-21)
- Production site-owned CSS driver had rejected first-party PR #989's PHP runtime 13e98... because the immutable original source allowlist still recognized only historic Wayfa 597a...; fixed by exact Git-pinned pair and companion, retaining old approved rollbacks.
- Atomic two-company engine admission on the VPS: source renderer 8f72d6ddb294..., Wayfa renderer 804b3243afa3...; rollback preimages recorded under the root-only code release receipts directory. The release script is ops/adaptive-css-delivery-v1/release-css-source-pair.py and uses expected preflight files, locks and exact digests.
- Wayfa passed the real site-owned health audit with healthy_with_safe_fallback; WordPress Cron subsequently completed families ticket 169 at 12:42Z, verifying the previously blocked source path. No CSS certificates were forged or status-upgraded.
- Existing Wayfa V2 certificate files: 24 certified, 3 reverted, pinned to historic runtime SHA 597a...; new live source runtime is 13e98..., so consumer correctly refuses those certificates on real frontend HTML. The group registry now has 18 groups with zero context-qualified. These are NOT 24 actively delivered public CCSS pages.
- Source listing generation moved from 1436 to 1505 by the 12:42Z refresh. Blindly updating certificate runtime SHA would not reconcile this dependency. Requalification needs fresh exact source + QA or legitimate per-template inheritance; the worker and family scheduler own that workflow.
- Public production canary remains at 0% because Nginx's verified CSS cohort cache split is absent. Existing optimized home CSS is a separate certified runtime.
