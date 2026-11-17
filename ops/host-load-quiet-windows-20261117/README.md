# Bounded Wayfatours optional cache work (17 Nov 2026)

## Measured cause

On the eight-vCPU VPS the sampled 1m load fluctuated around 5-8 (brief
higher spikes), with most cores often idle and no meaningful steal or
memory pressure. A separate enrolled 1.3-vCPU Playwright browser and
expensive anonymous PHP-FPM cache misses competed with native site workers.
A Wayfa mobile prime timer repeated strict CSS precondition failures every
30 seconds; the HTML warmer polled every 60 seconds although its company
policy refresh threshold is 780 seconds and a full generation warm includes
14 anonymous/WPML/cookie variants and may consume ~30 seconds.

## Change

Only two **optional public cache** timers and services receive drop-ins.
The original source and production PHP, cache key/TTL/generation, CSS proofs,
booking, payment, WooCommerce, 305/313 workers and authoritative browser
qualification services are unchanged.

- HTML warmer: 5-minute scheduling opportunity, randomized up to 90 sec.
- CSS-sensitive mobile primer: 15-minute opportunity, randomized up to 180 sec,
  preserves original failed CSS checks; it is not declared ready on error.
- Both defer via existing read-only visitor-load admission when CPU load is
  high or WordPress CLI slots are busy. Both receive Nice=15, CPUWeight=10,
  IOWeight=10, idle I/O class, CPUQuota=60% for their own process tree.
- Exact rollback: remove only the four
  `/etc/systemd/system/<unit>.d/90-mad4b-quiet-window.conf` files,
  then `systemctl daemon-reload` and restart the two timers. Keep original
  cache generation/ledger files intact.

The host-level CPU admission guard remains in charge of other elective work;
we do not kill long-running QA jobs or throttle PHP-FPM checkout traffic.

## Persistent host + real visitor stability SLO

An independent `mad4b-host-visitor-stability.timer` executes every 30 minutes,
reading the existing 5-minute `mad4b-visitor-tail-observer` history. It makes
**no additional WordPress/HTTP requests**, so detection itself does not create
CPU bursts. It persists a root-private `reports/host-visitor-stability-v1/latest.json`
with the 24-hour baseline before the first Wayfa quiet-window deployment
(2026-11-17 08:47:18 UTC), post-change observed samples, 1/4-hour recent
percentiles and the exact number of reliable source-home observations.

A rolling 24-hour stability statement requires at least 220 real observer
samples and at least 80% valid CPU telemetry. Earlier output is
`under_observation` rather than a fabricated success. CPU pressure is a
separate diagnostic; it never automatically pages anyone without visitor
impact. Multiple independent AJAX p95 windows >=3000ms with >=25 real
requests, or repeated confirmed HTTP errors, raise an actionable incident
through the existing `mad4b-alert-handler@%n.service`, debounced for 4h.
A bounded curl timeout (HTTP status 0) is *inconclusive*, not proof of an HTTP
5xx. No observer data for >30 minutes reports `observer_stale`; neither
case can be called long-run stable. Root systemd service has read-only
filesystem permissions except the private report directory, CPUQuota=20%,
MemoryMax=128M, and no capabilities.

Rollback: disable the single new timer and remove
`/etc/systemd/system/mad4b-host-visitor-stability.{service,timer}`. Retain
original 5-minute observation data; do not stop visitor monitoring or
critical services.
