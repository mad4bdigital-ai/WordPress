# WP Cron long-running callback, 17 November 2026

## Confirmed by actual staging WordPress Cron callback log

Staging `/wp-cron.php` completed the viewpoint scan in 0.5 ms, adaptive
runtime observation in 60 ms, WPML design reconciliation in 362 ms and
media reference audit in 2955 ms before entering the cache-lifecycle
verification hook at 09:31:45 UTC and remaining in it >60 seconds. The
original full native `wp-cron.php` systemd cycle, which covered all due
hooks, had timed out after 125 seconds at 09:23 UTC and was correctly
rolled back. The native timer must remain disabled until its entire
end-to-end cycle and Action Scheduler health are proven.

The Staging **live** cache lifecycle v1.0.4 was already fixed not to repeat
purge calls, but the previously committed Git copy (status v1.0.2) still
had a legacy repeated-purge + cache-delivery GET pathway. The fix starts
from the exact live nonpurging v1.0.4 code and adds a small bounded drain.
No claim that the live copy was purging is correct; preserve this
source/live direction during merge.

## Fix, fail-closed

- Each ordinary five-minute WP Cron cycle verifies at most 3 routes at
  3 seconds HTTP timeout per route, with an additional 12-second soft
  wall-clock deadline. Requests that time out **fail** verification, never
  certify it. Remaining work stays in the original ledger and uses its
  fair selection, CAS acknowledgement, and exponential retry backoff.
- Neither a recurring Cron tick nor an HTTP timeout purges URLs, invalidates
  FastCGI caches, or modifies billing/booking/commerce orders.
- A small private receipt includes verification budgets and actual duration.
- Optional staging-only diagnostic MU plugin logs only scheduled hook name,
  entry point, run ID, start/end timing and process memory, never arguments,
  URLs or user identity. The instrumentation does not run on Wayfatours.
- The protected host-local `wp-cron.php` systemd scheduler remains
  **disabled**. Site visitor cron is left on until truly green continuous
  native scheduler tests; the guarded legacy staging runner is restored.

## Operational evidence

Source-only path: `/wp-content/plugins/mad4b-cache-lifecycle/includes/control.php`.
Staging live path: `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-cache-lifecycle/includes/control.php`.
Original live file before patch must have SHA256
`6da9f6c8127fedcd343c0e1b21d978b4d7c13eb7102a037a73e495f4888deb65`.
Validate through real callback timestamps, not only the systemd service status.

`DISABLE_WP_CRON=false` on Staging and the original Production flags are not
altered by the budget patch. External Hostinger environments have their own
schedulers and require independently authorized per-host deployment.
