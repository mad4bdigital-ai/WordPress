# MAD4B Site-Local Cron Runtime Contract

**Architecture:** every enrolled company site owns WordPress cron events in
its *own* PHP/container/shared-host runtime. MAD4B Central inventories,
compares and reports site-local policy but never replays WordPress cron
remotely or dispatches per-site `wp-cron.php` over cross-host SSH.

## Support matrix

| Hosting environment | Site-local owner | Current safe operating mode | Host-native reconciliation |
| --- | --- | --- | --- |
| VPS with WordPress PHP container | Local WordPress PHP-FPM | WordPress Core WP-Cron fallback | Optional local systemd with complete live callback proof |
| Hostinger Cloud Startup / shared PHP | PHP running under the **site's Hostinger account** | WordPress Core WP-Cron fallback | Add per-site hPanel Cron Job when bound + proven |
| Third-party managed WordPress | Provider's WordPress PHP runtime | WordPress Core WP-Cron fallback | Provider scheduler capability evidence |
| Dedicated server / Kubernetes | Site's own worker pod/node | WordPress Core WP-Cron fallback | Local pod CronJob / systemd worker after proof |

Both `wp-cron.php` and Action Scheduler already exist in WordPress. The
read-only `mad4b-site-local-cron-runtime-v1.php` MU plugin adds an identical
`wp mad4b cron-runtime status` command on any PHP-hosted WordPress site.
It reports the *actual* WordPress constant/queue state and never claims a
host-native worker is present without independently verified evidence.
There is no automatic `DISABLE_WP_CRON` mutation, Cron job creation,
forced Action Scheduler replay or background site-specific policy change.

**Important:** `DISABLE_WP_CRON=true` disables visitor-driven triggering
only, not WordPress's native event queue. A native host-local scheduler
must exist and be proven before toggling it. Until then, leave request-
driven WordPress Cron enabled, particularly for booking, WooCommerce,
Fluent Forms and notification callbacks. Do not use a central-VPS SSH Cron
as a long-term worker for remote sites.

## Reconciliation to future Hostinger native jobs

Discovery should capture per-site canonical origin, host account, WordPress
root, environment, PHP version, WP-Cron status, installed scheduler source,
scheduled command cadence and **real event/Action Scheduler outcomes**.
Only after a site-local hPanel scheduler demonstrates a valid full cycle
and no sustained backlog can the site's `DISABLE_WP_CRON` be changed to
`true`. The evidence must be recorded separately for each company site
and each host, without sharing CPU budgets between VPS and Hostinger.
This reconciliation is deliberately **not performed here**: no hPanel Cron
job access/authorization has been established, and there is an outstanding
Rank Math Search Console indexing backlog on All Royal Egypt Production.

## Retired cross-host job

The five old `mad4b-hostinger-ssh-cron@*.timer` VPS units and the
cross-host executor have been disabled/removed; source removes them as well.
The old executor remains in Git *history only*. Site-local WP-Cron remains
in place on all five external WordPress sites. The VPS's own legacy local
native timers are still disabled pending full-run admission validation.
