# Storage & Retention

Use versioned dedicated tables for operation events, operation heads, recovery cases and metric buckets. Event rows are append-only with unique (operation_id, sequence), previous_event_sha256 and event_sha256. Heads are projections, not evidence authority.

Defaults: verbose trace 30 days, recovery/journal evidence 180 days, metric buckets 90 days. Cleanup uses bounded batches, never deletes active/recovery_required operations, and emits audit evidence. Uninstall never destructively purges automatically; explicit owner-governed purge is required.
