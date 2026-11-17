# Registered Hostinger SSH WordPress-Cron — reversible fallback

Five remote sites are matched to a current company/site identity and an
existing pinned SSH target before every scheduled execution. The central
VPS only opens a bounded SSH session; WordPress's original `wp-cron.php`
executes in the site's own Hostinger PHP CLI process. No WordPress plugin,
WooCommerce, billing, account, option, database, or hPanel setting changes.

Hostinger accounts' `crontab` command is unavailable (127); therefore this
is **not a native Hostinger hPanel Cron Job**. It is a registered VPS
systemd fallback, relying on the VPS and enrolled SSH channel. The
site-native request-driven WP Cron stays enabled independently so an
SSH outage cannot permanently stop business/transactional events.

Each company has an independent 5-minute timer, staggered by minute so two
sites on the same shared account never start together. A central host-group
flock and the remote `flock -E 73 -n` serialize shared-host PHP workload.
Each native WP Cron process is bounded to 65 seconds, nice 15, and has
per-company structured receipts (state, elapsed, exit and exact host).
The WordPress own `doing_cron` lock governs visitor/API versus SSH
invocations. A 0 exit means the *native process exited zero*, not that
Action Scheduler's entire queue drained or that every cron callback ran.

Runbook on incident: inspect `journalctl -u mad4b-hostinger-ssh-cron@<site>.service`
then compare the root-private receipt in
`/opt/mad4b-company-provisioning/reports/host-aware-remote-wp-cron-v1/`
with site-local Action Scheduler pending and overdue counts via the pinned
read-only SSH WPCLI route. If a remote host changes identity or has a
non-zero native Cron exit, the dispatcher fails closed; WP visitor cron
remains available. Do not delete SEO/RM queue entries or retry payments
manually. To reverse the central fallback, `systemctl disable --now` only
its five `mad4b-hostinger-ssh-cron@*.timer` instance units. No site file
or setting reversion is needed.

Known backlog discovered 2026-11-17: All Royal Egypt Production had ~1884
Action Scheduler pending, ~725 overdue at an earlier check, mostly
`rank_math/analytics/get_inspections_data`; all inspected per-action
argument hashes were unique, so deduplication/deletion is unsafe.
Treat provider API quota/batch exhaustion as separate from Cron transport.
Do not certify all actions delivered merely because WP Cron exited 0.
