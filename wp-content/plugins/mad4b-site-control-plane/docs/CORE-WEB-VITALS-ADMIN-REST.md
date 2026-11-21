# MAD4B Core Web Vitals — Admin REST and lifecycle

Live Staging authority: tours.dev.mad4b.com. Implementation: includes/class-mad4b-scp-core-web-vitals.php.

## Administrative REST

All routes are under /wp-json/mad4b/v1/admin/core-web-vitals. Authentication follows WordPress REST; requests made with admin cookies require X-WP-Nonce. All routes require manage_options, return private no-store responses, and never return API key material.

- GET / — read current metrics, provenance, history and lifecycle.
- GET /lifecycle — read job state, timestamps, lock, cooldown, daily/once schedules.
- POST /settings — JSON page_url (HTTPS page on same site), optional api_key, optional clear_api_key. Blank api_key retains encrypted key. Save verifies readback.
- POST /refresh — JSON {} queues a one-time event and returns 202. Existing queued/running work returns 200, rate-limit cooldown returns 429.

## Lifecycle

idle -> queued -> running -> completed / partial / failed. Readback additionally detects stale_running and orphaned_queue. Each job stores job_id, entry_point, timestamps, results and safe error codes. REST and admin-post paths share save_settings() and queue_refresh(). Neither endpoint runs a Google network request inline.

Existing scheduled daily refresh remains intact. Provider errors receive bounded cooldowns. A page URL change clears stale evidence from the previously monitored page, but keeps API credentials. Missing CrUX data is insufficient_data, never Failed. Lighthouse performance score is never used to determine Core Web Vitals Passed.

PageSpeed Insights external requests have a maximum 35-second timeout per device, keeping two sequential runs below the current staging PHP-FPM 100s request timeout in ordinary operation. The status shows transport error codes and durations, never raw provider error messages or credentials.

Google's Chrome UX Report API is separately enabled in Google Cloud; the configured key returned permission_denied for its direct queryRecord endpoint on 2026-11-06. This does not invalidate the existing PageSpeed Insights configuration.

## Verification

Staging-only isolated regression test:

wp eval-file wp-content/plugins/mad4b-site-control-plane/tests/core-web-vitals-rest-lifecycle-runtime.php --allow-root

The test uses isolated options, Cron hooks, REST namespace and mock Google responses. It tests guest permission denial, administrator REST reads and writes, encrypted settings, partial success, cooldown, page-switch invalidation, retry and key clearing.

Pre-change copies of the PHP implementation are saved under:
/opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/cwv-backups/

Reconcile against GitHub only after evaluating live-source drift; do not overwrite newer Tours.dev code with older GitHub files.

## 2026-11-06: Slow-provider resilience — independent device workers

The PageSpeed Insights v5 key configured in MAD4B is a supported Google credential, passed through the \`key\` query parameter. Live diagnostics confirmed successful Google TLS/DNS, a real HTTP 200 mobile response (~19.2s), and two 35s HTTP client timeouts for separate requests. Google Lighthouse scan duration is variable.

The daily and REST/admin refresh entry points now dispatch two **chained** WP-Cron device jobs under the \`mad4b_core_web_vitals_device_worker\` hook. Each run has a 70s maximum provider timeout and its own execution request; no visitor, REST, or admin request waits for Lighthouse. After Mobile finishes, Desktop is scheduled. A device with a transient transport failure is appended for one automatic retry after a 180s delay, with no unbounded retry loop. Rate-limited (429) and denied (403) requests are not retried immediately. A configured Performance Release receipt continues to use \`queue_refresh('performance_release')\` and the existing cooldown policy.

Snapshot persistence is **per device** and failure does not delete any previous valid origin/page CrUX or Lighthouse results. The status reports the request duration, sanitized error category (timeout/TLS/DNS/other) and per-device attempt count. \`insufficient_data\` is the correct CrUX verdict where Google has no eligible field-data samples for a Staging domain. The separate CrUX API still requires enablement in Google Cloud; a PageSpeed API key is not proof that the CrUX API is enabled.

Regression:
\`wp eval-file wp-content/plugins/mad4b-site-control-plane/tests/core-web-vitals-rest-lifecycle-runtime.php --allow-root\`
\`wp eval-file wp-content/plugins/mad4b-site-control-plane/tests/core-web-vitals-async-worker-runtime.php --allow-root\`

Both tests use isolated settings/hooks, mocked provider responses, and do not replace the user's configured Google API key.
