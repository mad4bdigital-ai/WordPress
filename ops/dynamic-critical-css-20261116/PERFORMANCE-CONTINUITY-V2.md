# Performance Continuity v2 — Wayfatours first-paint recovery

## Incident evidence, 2026-11-17

- Wayfatours EN/ES public mobile first-party response carried
  `X-MAD4B-Mobile-CSS-Ready: 0` and
  `X-MAD4B-Mobile-CSS-Native-Complete: 1`. The synchronous LiteSpeed
  combined CSS was ~331 KiB transferred. This is a *safe fallback*, not a
  healthy mobile CSS certificate.
- Both active homepage source CSS SHA256 values were absent from the
  previous Critical CSS manifest. Prior certificates must never be reused
  for unrelated CSS bytes.
- The content-addressed `event-gate.py` treated the *resource-deferral*
  `pending` state as a one-hour QA failure, causing
  `unchanged_failed_generation_cooldown` even after `shared browser busy`.
- Home and ordinary public-route certification workers competed for the
  global Chromium lock. Homepage LCP recovery had no resource priority.

## P0 remediation

1. Preserve all original source/SHA/Elementor/market QA validation.
   Distinguish `pending` resource deferral from actual `hold` failure.
   An unchanged pending generation retries after 120 seconds; a truly
   failed browser/source verification still waits 3600 seconds. New
   source identity triggers immediately regardless of status.
2. A cheap 5-minute Wayfa timer checks the event/source gate. Actual
   Chromium only starts on a changed generation, expired weekly integrity
   check, or a pending retry **when the global lock and CPU guard admit it**.
3. A separate read-only home watchdog makes tiny cookie-free first-party
   EN/ES requests every 10 minutes and records exact mobile CSS readiness,
   active fallbacks, and a sustained degradation age. After 15 minutes it
   emits `PERFORMANCE_CONTINUITY_WAYFA_ALERT` with a private JSON receipt.
   The alert never falsely claims real-user CrUX readiness.
4. The ordinary Wayfa public-route Chromium worker checks this locally
   attested report before taking the global lock. While home has unready
   CSS, it defers the browser slot for home recovery. After two hours,
   reserve one in four 7-minute windows to other pages so they cannot
   starve indefinitely; unknown/stale monitor data never blocks them.
5. Publishing still requires the original paired Chromium FCP/LCP/CLS
   proof and same public source SHA; the worker only marks `ready` after
   the original strict final-delivery readback. A successful publish
   without independent delivery stays `pending`, **never** certified.

## Non-regression and rollback

- No `wp-config.php`, LiteSpeed Async CSS/UCSS, Elementor template, WPML
  strings, WooCommerce prices, payments, or remote-host Cron jobs changed.
- Old CSS proof remains in the private manifest, but is never applied to
  changed CSS. Safe full native CSS remains until a real verified new
  certificate is published.
- Private watchdog ledger:
  `/opt/mad4b-company-provisioning/reports/performance-continuity-v2/wayfa-home.json`.
- Source-owned units:
  `mad4b-performance-continuity-wayfa.service/.timer` and the revised
  existing `mad4b-critical-css-wayfa.timer`. The public source canary
  handler uses a local read-only priority policy.
- Rollback: stop/disable the watchdog timer, restore the prior 15-minute
  Wayfa timer from its source-hashed backup, and revert source-only
  event gate, runner and public priority changes. This will restore the
  previous latency behavior but preserves native safe CSS.
- A monitor result or successful runner exit does NOT itself certify
  live performance improvement; keep the public `CSS-Ready` header and
  paired browser QA receipts as separate acceptance evidence.

## Adaptive source-change detection

The old read-only Wayfa EN/ES stylesheet SHA observer had a fixed 3600s
cooldown even when the mobile CSS consumer was visibly in native full-CSS
fallback. The new exact-host, root-private healthy/degraded report adjusts
ONLY that cheap source observation to 300s while a fresh, authenticated
`CSS-Ready:0` proof exists. Unknown, stale or tampered reports keep the
original hourly interval. The new pure policy module is part of the
Wayfa-specific QA-source fingerprint; source Staging receives no new
invalidations. Certificate signing, CSS content addressing, paired browser
QA and cache release are unchanged.
