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

The initial Woo Lab Staging policy approves only two vetted WordPress Core
metadata-maintenance callbacks: wp_version_check and wp_update_user_counts.
No approval for FluentCRM, Fluent Forms, WooCommerce Action Scheduler, vendor
API, bookings, backups, image optimization, email, external sync, garbage
collection, or other plugin tasks.

The host Cron daemon invokes this script every 15 minutes.
DISABLE_WP_CRON=true is retained so web traffic never replays arbitrary
due actions. Per-run max executions=2, single-run flock, 80-second timeout
for each approved WP-CLI call. The operator emits only summary and hashes,
not credentials, callback outputs or customer data.

To add any high-risk hook, first complete a separate callback-source
and sandbox-effects review; do not enlarge policy merely to clear a dashboard
critical count. Each company has a separate policy and scheduler.


## Staging remediation extension — 29 Oct 2026

The operator's generic effect classes are explicitly constrained to `wordpress_core_metadata_maintenance`, `diagnostic_report_only`, `expired_nonce_cleanup` and `expired_privacy_export_cleanup`. Root-owned tenant policy pins the exact source and callable; a category label alone is never sufficient for execution.

In addition to the two core events, Woo Lab Staging approved and executed three reviewed and bounded callbacks:

- `company_term_media_integrity_audit`: computes a diagnostic report (501 destination image metadata records, PASS, zero quarantine or unrelated-group reuse violations); does not modify destination image metadata
- `wp_privacy_delete_old_export_files`: standard WordPress expired export cleanup; the precheck found zero export files
- `jetpack_clean_nonces`: expired Jetpack nonce cleanup; the precheck found zero stored Jetpack nonce options

All five verified hooks are included in the root-only Woo Lab policy; the generic source contains no tenant hostname or container name. The approved list maximum was raised from four to six, *not* the per-cycle execution budget, which remains two.

Runtime overdue events older than 24h fell from **25 to 18**: five individually reviewed hooks were executed, and two stale/orphaned schedules (an explicitly `local`-only lab inventory hook and an absent TripAdvisor callback) were removed after exact environment/no-args/no-callback checks and recording rollback timestamps. A separate LiteSpeed hook currently lacking a callback was retained because its provider can conditionally register it in a later request. There is **no general Cron replay**. Other queued hooks (including FluentCRM, WooCommerce Action Scheduler, Duplicator, LiteSpeed remote tasks, external API polling and source updater) remain denied.

The exact WordPress tenant and callback source drift negative tests remain fail-closed. An additional negative test confirmed that `approved_effect_class=full_queue_replay` exits without executing Cron. The stored WordPress content-readiness Critical remains open until the other overdue jobs are resolved or independently classified; do not hide or suppress it to make the UI green. The Dynamic Filter SEO Bridge Full Boot remains separately BLOCKED by independent package provenance/certification, not by this Cron runner.

The site-specific policy, execution results and rollback evidence remain private on VPS under `qa/governed-cron-runner-20261029/` and `runtime/cron-policy/`. Source PR changes are not a real independent operational certificate, Production permission or release authorization.
