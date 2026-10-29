# Governed WordPress Staging Cron runner

A company-neutral operator program for selective WP-Cron execution. It does not
replace WP-Cron with wp-cron.php and never executes the full due queue.

The operator is invoked with a per-site root-owned --policy path. The private
policy binds tenant host, container identity, and explicitly reviewed callbacks
by callable, priority, WordPress-relative source file SHA256, empty args and
execution budget. Changed source, callback, tenant or staging environment
cause a fail-closed denial. Root-only locked runs snapshot before and after.

Execution affects only the exact approved due hook using WP-CLI
wp cron event run HOOK --due-now, not a global due-event replay.

Woo Lab Staging currently approves five source-hash-pinned callbacks:
wp_version_check and wp_update_user_counts (WordPress Core metadata),
company_term_media_integrity_audit (diagnostic report only),
wp_privacy_delete_old_export_files (expired privacy exports), and
jetpack_clean_nonces (expired Jetpack nonces). Generic allowed effect classes
are checked in source, and the private tenant policy still requires exact
callback identity/source SHA, empty args and an actually due schedule.

No approval for FluentCRM, Fluent Forms, WooCommerce Action Scheduler,
bookings, backups, image optimization, outgoing communications, external sync,
automatic plugin recovery, or other unreviewed jobs.

The host Cron daemon invokes this script every 15 minutes.
DISABLE_WP_CRON=true is retained so web traffic never replays arbitrary
due actions. Per-run max executions=2, single-run flock, 40-second timeout
for each approved WP-CLI call. The operator emits only summary and hashes,
not credentials, callback outputs or customer data.

To add any high-risk hook, first complete a separate callback-source
and sandbox-effects review; do not enlarge policy merely to clear a dashboard
critical count. Each company has a separate policy and scheduler.

## Outer Cron runtime bound

The host Cron has a 200-second hard timeout. Each of two WordPress state probes
is bounded to 35 seconds and each of up to two individually approved hook
invocations to 40 seconds (150-second inner worst-case total), leaving room
for lock acquisition and audit write. A timed-out hook fails closed and is not
reclassified as completed without a successful WordPress queue readback.

## Woo Lab Staging evidence — 29 October 2026

- Five vetted hooks executed with WordPress WP-CLI, all successful on their due
  instances. Media integrity audit PASS: 501 records, zero quarantine violations
  or unrelated translation-group image reuse. Privacy export and Jetpack nonce
  prechecks found zero expired stored items.
- Two old no-callback schedules were unscheduled after verifying the Staging
  environment, no event arguments and no registered callbacks: a local-only
  inventory lab worker and a TripAdvisor auto-download entry no longer
  implemented in its active plugin. Restore metadata is retained privately.
- Overdue events older than 24 hours reduced from 25 to 18. Total due events
  decreased from 63 to 55. The remaining events include external and business
  tasks and are intentionally excluded from execution pending effects review.
- Negative tests for wrong tenant, wrong callback SHA and unapproved effect
  class all deny execution. Source checks, both real WP-Cron state probes,
  selective-run tests and public Woo Lab health checks passed.
- Content Readiness remains one Critical (real backlog) and 47 High. The
  independent Dynamic Filter SEO Bridge package is still uncertified and in
  the correct Safe Boot hold. Do not suppress the notices or claim a release.
- The private Staging policy is not committed. Source PR #617 is Draft, and
  neither CI success nor a local runtime source pin is a signed owner permit.
