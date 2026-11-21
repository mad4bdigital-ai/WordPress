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
