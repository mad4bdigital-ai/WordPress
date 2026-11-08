# MAD4B Redis Flush Provenance v2

Already implemented on Tours.dev Staging and Wayfatours Production. This
source package records what future WordPress Object Cache full flushes
came from, without changing cache contents, the price engine or bookings.

The tiny fail-open hook is inserted after the existing LiteSpeed
`wp_cache_flush()` backend call. The site-specific MU plugin logs only
timestamp, site, success, sanitized PHP source basename, optional
`MAD4B_WORKFLOW_NAME`, and stack function names. It excludes WordPress
request data, customer attributes, Redis keys, CLI arguments and secrets.

The host observer runs every minute. It correlates safe WordPress logs
against Redis INFO FLUSHDB/FLUSHALL counters, with a one-poll grace period
when an event and counter update arrive in different orders. Unexplained
flushes, untagged WP-CLI stdin, malformed audit logs, and observer
failures are flagged. Journal warnings and a site admin notice report
ALERT. Status is visible under Tools -> Redis Flush Provenance.

19 offline Python regression tests and PHP emitter tests pass. Both
site administrations read the host-produced status. The hidden log URL
returns HTTP 403. The sites returned HTTP 200, with no observed new Redis
flush during deployment. No synthetic FLUSHDB was executed.

## Important limitation

The existing Release Guard v1 does NOT yet pin and auto-reapply the
new LiteSpeed function wrapper after a plugin update. An additional
host-side upgrade reinstrumentation operation was rejected by the
execution safety gate; it has not been deployed. In that situation,
counter-only detection continues to work, but the source may become
unattributable until operator review. DO NOT claim automatic repair on
unknown new LiteSpeed versions. No intrusive Redis MONITOR is used.

WP-CLI `eval-file -` cannot disclose an actual script filename from its
CLI arguments. Future MAD4B executor jobs should supply a sanitized
`MAD4B_WORKFLOW_NAME`; without one, such events are flagged.

Live proofs are stored under
`/opt/mad4b-company-provisioning/reports/redis-flush-provenance-v2-20261108/`.
