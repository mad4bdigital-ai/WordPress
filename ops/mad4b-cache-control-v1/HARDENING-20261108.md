# Cache coherence hardening — 8 November 2026

## Live changes verified on Tours.dev and Wayfatours
- LiteSpeed → FastCGI selective bridge v1.1.0: up to 300 scoped paths per request in batches of 60. A failed batch creates a persistent, administrator-visible invalidation-gap ticket; >300 paths fail closed. It never falls back to a global purge or flushes Redis.
- Safe Public HTML Purge v1.1.0: a 24-hex request ID is attached to every new site-local V2 signal. The host worker only reports the **matching request_id** with its Nginx epoch receipt, preventing old success being mistaken for new completion. Legacy V1 host signals remain readable until migrated.
- Added 5 previously missed MAD4B full-purge callsites per site in Commerce/Experience to the page-only safe action; preserved their existing saved-state validation, Elementor CSS purge, and pricing behavior.
- Passive 30-minute guard now scans all installed `mad4b-*` plugin trees plus MU plugins, rather than only the Platform and Site Control Plane subsets. It detects owned direct `wp_cache_flush`, Redis flush and native LiteSpeed blanket purge.
- Added read-only candidate inventory across WordPress plugins and host company scripts. An inventory hit does not prove that callback executed or that Redis was flushed.

## Tests and verification
- Both PHP containers: bridge basic, batch 62→60+2, second-batch failure→manual gap, >300 overflow→no broad fallback, safe HTML request-ID and symlink protection; PHP syntax checks for all changed plugins.
- Host epoch Python selftest and an isolated temporary-file request-ID receipt fixture: successful. The fixture mocks Nginx reload and does not purport to be production-wide acceptance.
- Both public homepages HTTP 200; Cache Control readiness PASS 2/2, drift 0 after policy hash synchronization; safe plugins share the same hash.
- Redis warm receipts observed as 12/12 Tours.dev and 4/4 Wayfatours. Existing priority and admission guards were not bypassed.
- Historical Redis FLUSHDB attribution is still **OPEN**. The expanded scan produced 71 possible external/vendor code candidates on Tours.dev and 48 on Wayfatours, not 119 confirmed cache-flush events.
- This deployment **did not** rotate Wayfatours Production-wide Global Epoch. Production-wide cold-cache reset has real operational impact and requires its own controlled acceptance.

## Operational limitations
A >300-route scoped event still leaves a visible invalidation gap rather than guaranteeing all affected pages were invalidated. The retained gap must be reviewed, and any targeted repair uses the existing authenticated Cache Control flow. Do not interpret this as complete Dependency Graph coverage.

The Redis provenance v2 runtime counter remains the authority for actual FLUSHDB/FLUSHALL activity. Static callsite inventory is supplemental and cannot retroactively attribute old counter increases.

## Rollback
Exact predeployment originals and SHA manifest live under `/opt/mad4b-company-provisioning/reports/cache-pr781-hardening-20261108/backups/`. Restore the affected MU plugins, worker, five Commerce/Experience files per site, and old readiness pin atomically, then run PHP lint/readiness checks. Restore guard and drop-in separately if needed. Do not clear Redis, edit LiteSpeed vendor code, or reset cache financial data.

