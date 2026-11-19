# Host-aware native WordPress Cron fleet v1

Local deployment only: Tours.dev (staging) and Wayfatours (production)
run on the enrolled VPS. Remote Hostinger hosted companies retain
site-local WP-Cron until their own host cron + commerce queue coverage
can be independently verified. We do **not** SSH-write an assumed
Hostinger account or inherit VPS capacity/admission as a remote host policy.

For local companies, register two staggered 5-minute systemd timers.
Every tick serializes via one host-wide flock, attests the enrolled Docker
container host+environment and WordPress Action Scheduler hook, and runs
WordPress Core's original `wp-cron.php` through PHP CLI as `www-data`.
The native `doing_cron` transient and WordPress callbacks remain authoritative.
Limits: 125s native PHP timeout, 143s parent, 170s systemd; in-container
`nice 15`, idle I/O, no extra web requests, no credentials, no global remote
scheduled event replay. Systemd `OnFailure` invokes existing alert dispatch.

Migrate only after both independent scheduler preflights prove the site
identity, DISABLE_WP_CRON=true and `action_scheduler_run_queue` callback.
The previously staging-only governed `/etc/cron.d/mad4b-governed-wordpress-staging`
runner has an incompatible fail-closed `DISABLE_WP_CRON=true` requirement and
is superseded only **after** local WordPress cron replacement runs; it does
not provide complete event coverage and must not be the only executor.

Rollback: stop the two timers first, restore original wp-config files,
then restore legacy cron.d configuration. Never disable Cron without an
active site-local WordPress Core runner. No user-facing HTTP endpoint is
used to trigger WordPress business events.
