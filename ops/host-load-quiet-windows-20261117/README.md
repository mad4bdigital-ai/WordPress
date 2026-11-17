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
