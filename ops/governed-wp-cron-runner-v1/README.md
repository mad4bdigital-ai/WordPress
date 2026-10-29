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

## Outer Cron runtime bound

The host Cron has a 200-second hard timeout. Each of two WordPress state probes
is bounded to 35 seconds and each of up to two individually approved hook
invocations to 40 seconds (150-second inner worst-case total), leaving room
for lock acquisition and audit write. A timed-out hook fails closed and is not
reclassified as completed without a successful WordPress queue readback.
