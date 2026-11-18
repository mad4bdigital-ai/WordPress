# MAD4B WP-Cron-owned Critical CSS scheduler — 2026-11-18

WordPress is the source of which CSS jobs are due. First-party plugin 421
stores original source invalidations. Plugin 429 registers native WP-Cron
callbacks and per-company numbered tickets in mad4b_css_wpcron_dispatch_v1.

Host Cron simply wakes the WordPress queue every five minutes with one fixed
entry in /etc/cron.d/mad4b-css-wpcron. The WP-CLI-only pump runs only due
CSS events and reads tickets in the same WP bootstrap. Frontend PHP never
runs shell commands, Chromium, or certificate publication.

Worker executes fixed first-party CSS scripts, preserving CPU admission,
browser locks, company identity, WPML/WCML partitions, CSS hashes, native
fallback, and certificate verification. Failed visual or interaction checks
are post-policy observations, not a policy activation condition.

Cadence:
- Public route: 30-minute maximum polling plus debounced original dirty hook.
- First-fold: six-hour fallback, immediate home-dirty enqueue.
- Source-family registry: 30-minute no-browser index refresh.
- Visual and interactivity: daily; integrity: daily; retention: weekly.
- Exact-ticket acknowledgement: newer source edits cannot be consumed.
- Resource pressure: pending tickets persist and retry on the next wake.

Live code snapshot:
/opt/mad4b-company-provisioning/releases/template-aware-css-wpcron-v1-20261118

Inspect:
  python3 /opt/mad4b-company-provisioning/operations/wpcron-css-v1/css-wpcron-worker.py --inspect

Rollback:
  Remove /etc/cron.d/mad4b-css-wpcron.
  Rename MU plugin 429 on each site to a non-.php extension.
  Re-enable the eight timers listed in
  /opt/mad4b-company-provisioning/reports/wpcron-css-v1/migrated-systemd-timer-names.txt
  Retain all previously published CSS certificates and assets.
