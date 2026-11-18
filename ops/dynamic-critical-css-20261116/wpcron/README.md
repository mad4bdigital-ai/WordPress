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
- First-fold: source 15-minute, Wayfatours 60-minute maximum re-evaluation,
  plus immediate original WordPress home-dirty event.
- The real anonymous Source Staging CSS SHA and Elementor body graph for all
  six WPML homepage languages are tracked independently of old manifest SHA.
  A cache or plugin regeneration cannot silently preserve a ready verdict
  when the public bundle changed.
- Source home drift evidence can reserve a narrowly constrained one-browser
  recovery window on an eight-vCPU VPS; public route QA, company scope,
  421/422 native policy checks, and certificate publishing are unchanged.
- Under any mismatch of CSS/DOM/market/locale, original full native CSS is
  deliberately retained for visual safety until exact current source QA
  finishes; no cross-locale or expired critical CSS is reused.
- Source-family registry: 30-minute no-browser index refresh.
- Visual and interactivity: daily; integrity: daily; retention: weekly.
- Exact-ticket acknowledgement: newer source edits cannot be consumed.
- Resource pressure: pending tickets persist and retry on the next wake.

Live code snapshot:
/opt/mad4b-company-provisioning/releases/template-aware-css-wpcron-v4-20261118

Inspect:
  python3 /opt/mad4b-company-provisioning/operations/wpcron-css-v1/css-wpcron-worker.py --inspect

Rollback:
  Remove /etc/cron.d/mad4b-css-wpcron.
  Rename MU plugin 429 on each site to a non-.php extension.
  Re-enable the eight timers listed in
  /opt/mad4b-company-provisioning/reports/wpcron-css-v1/migrated-systemd-timer-names.txt
  Retain all previously published CSS certificates and assets.
