# ETG Staging: Site-local LiteSpeed JavaScript retention

## Root cause and scope (2026-11-17)

Hostinger `hosting-3` account for Egypt Tour Gates reached its per-account
quota despite free physical disk space. The staging-generated directory
`wp-content/litespeed/js` alone held ~22 GiB, including ~7,051 hashed
`.js` cache artifacts dated before 2026-11-11 (10.29 GiB). `JS Combine`,
`JS Minify`, and inline/external JS Combine were enabled on staging.
This means hash variants accumulated over time, not that source media,
orders or business backups should be deleted.

An explicitly bounded **one-time cleanup** removed only regular, direct,
32-hex-named `.js` files older than 2026-11-11 from ETG Staging's
`wp-content/litespeed/js` directory. The directory reduced from ~22 GiB
to ~11 GiB and the hosting account WordPress tree fell to ~42 GiB.
No uploads, WP database records, Production directory, or private backups
were changed. PHP write operations, previously rejected with disk quota
exceeded, then succeeded.

## Permanent preventive measure

`wp-content/mu-plugins/mad4b-site-local-litespeed-js-retention-v1.php`
is a read-only-to-WordPress-data cache retention worker restricted to:
- `wp_get_environment_type() === 'staging'`;
- exact site host `staging.egypttourgates.com`.

It registers a native **site-local WordPress hourly Cron** callback.
Each tick examines only `WP_CONTENT_DIR/litespeed/js` direct regular files
with a strict 32-hex `.js` basename, no traversal/symlink targets, and
prunes only items at least seven days old. It caps each run at **600 files**
and **9 seconds**, writing a local, non-sensitive result to
`mad4b_site_local_js_retention_last_run`.

The callback does not run on any other company, Production site, or VPS.
It does not disable WP-Cron, create central SSH/systemd schedules, or
change LiteSpeed JS optimization settings. Fresh/current cached assets
remain available, and stale generated cache can be re-created by LiteSpeed.

## Acceptance evidence

The real PHP 8.5 hosting environment passed 9 safety/unit invariants.
The manually invoked local callback reported `COMPLETE` with 6,993 fresh
entries skipped, no extra deletion, `133.4 ms` duration. WordPress's
actual queue recorded `mad4b_site_local_litespeed_js_retention_v1`
as a scheduled event with a next run at 2026-11-17 11:48:54 UTC.

Site Local Cron Runtime v1 was also installed on ETG Staging after quota
relief and confirmed zero Action Scheduler items overdue by more than
five minutes. The host-native hPanel Cron Jobs reconciliation is a
**separate future task**, not authorized or silently implemented here.

Operational rollback: remove only the exact Staging MU plugin file; keep
its benign last-run option as historical evidence. Original WP-Cron and
Action Scheduler are untouched.
