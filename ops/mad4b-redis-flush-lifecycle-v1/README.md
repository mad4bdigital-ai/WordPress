# MAD4B Redis FLUSHDB lifecycle and selective reconciliation v1

Company scope: Tours.dev Staging (six WPML languages) and Wayfatours
Production (English + Spanish). This package is source-backed;
runtime enrollment remains per authorized company.

## Confirmed failure mode

WP-CLI eval-file executed wp_cache_flush(); LiteSpeed Object Cache issued
Redis FLUSHDB. Both sites were observed. A property-types schema reconciliation
script contained unconditional flush on its schema phase, including no-op
reruns. The exact prior eval-file script basename was not captured, so its
individual attribution remains probable rather than proven.

## Live protections

1. Three legacy migration scripts skip full Redis flush after no-op tasks.
2. The Property Types script skips Redis flushing on no-op, uses
   clean_term_cache plus icl_cache_clear and flush_runtime for changed terms,
   and preserves full-flush fallback for structural JetEngine changes or
   WPML selective invalidation failure.
3. The Redis warmer now refuses to reuse partial receipts after a Redis
   run_id change or FLUSHDB/FLUSHALL. Existing visitor and CPU admission
   restrictions remain unchanged.
4. Read-only counters and scoped origins are monitored every five minutes.
   The temporary LiteSpeed diagnostic source was restored to its exact
   original SHA-pinned files.
5. Every 30 minutes the release guard verifies host script hashes and can
   reconcile a missing or precisely known original old Property Types script.
   Unknown code changes and concurrent WP-CLI use are rejected.

## Acceptance and open gates

Static Python contract and failure-mode tests are part of this package.
PHP lint passed on both live PHP containers. The latest verified guard
reported PASS 2/2, DRIFT 0. Last observed FLUSHDB counters were 99
for Tours.dev and 46 for Wayfatours, without another increase during
subsequent samples. These are counters, not customer page-speed data.

Tours.dev warming remains under strict CPU/WPCLI admission.
After the Redis epoch changed, its old partial receipts were discarded,
and a new verified partial cycle began. Wayfatours has current
4/4 receipts. Controlled live mutation-based WPML/JetEngine parity
testing has not been completed: do not claim all mutation invalidation
coverage proven.

## Deployment

The no-op patcher defaults to dry run. Its apply mode requires a new
backup folder, matches exact known legacy anchors, and rejects unknown
changes. Company-specific desired-state policy is kept on the VPS;
do not copy tenant policies across businesses.

This package contains no customer records, secret keys, passwords,
price amounts, or marketing attribution data. It never issues
Redis FLUSHDB/FLUSHALL commands. The active QA checkpoint and rollbacks:
 /opt/mad4b-company-provisioning/reports/redis-flush-root-cause-20261108/
