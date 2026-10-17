# Feature 007 / PR #258 — cross-cutting MCP & Staging audit

**Date:** 2026-10-11
**Target:** `mad4bdigital-ai/WordPress`, PR #258, `96036b4a351424535d83064d08b9b6acfb5048d5`
**Live observed target:** `https://staging.allroyalegypt.com`, WordPress 7.1.3,
PHP 8.3.35, MariaDB 11.8.9, Control Plane rc.96, installed source
`b53efafc149874455ab713498ba0399a1b43fd2f`.

> This is a scoped high-risk source and live-evidence audit, not a file-by-file
> certification of all 802 changed PR files. It does not certify the current
> HEAD as installed, pass PHP native tests, approve production or bypass any
> active Write Authority. Keep source facts, live facts and inferences separate.

## Summary verdict

- **Operational Staging read health:** a new `session-safe-diagnostics` read
  reported `state=ready`, `HEALTHY`, `partial=false`, no subject blockers,
  current write candidate binding and basic MCP protocol identity ready.
  This result is from the **installed** older source, not the pending PR HEAD.
- **Source implementation:** PHP code fixes for MCP transient read isolation,
  scoped light probes, Skills truth separation, dynamic first-party Admin
  enrollment and CI-independent signed *Staging* evidence were committed.
  A concurrent WP-Cron stale-state and scheduling failure path was corrected
  in source and regression fixtures were authored.
- **Release acceptance:** outstanding. GitHub Actions 61 queued on the observed
  PR HEAD; alternate independently signed native test evidence is supported,
  but neither a signed certified ZIP nor post-install E2E acceptance was
  produced by this audit.

## Evidence inventory

| Subject | Observed measurement | Interpretation |
| --- | --- | --- |
| One MCP round trip | ~22,560 ms | External client-side observation; **not** WordPress runtime duration |
| Same request's PHP elapsed | 3,857 ms | Includes WordPress + other plugin work |
| Same request's diagnostics | 57 ms | Narrow diagnostic callback only |
| Repeat read-only diagnostic | 3,563 ms PHP / 40 ms diagnostic | Not a controlled latency distribution |
| Database | 425 queries / PHP request | Component/SQL attribution still required |
| PHP | 6,223 included files / PHP request, peak 52,428,800 B | Includes WordPress and other plugins |
| Main plugin entrypoint | 377 direct require_once references, 375 unique paths, ~9.1 MB referenced PHP source | Static source bytes, **not** runtime memory |
| Main plugin bootstrap | 115 bootstrap-family call occurrences | Some conditional; **not** 115 executed calls |
| Plugin source candidate | 599 package files, 499 PHP, 12,297,972 bytes / ~11.73 MiB | Exact PR tree inventory excluding tests and generated provenance |
| Native WordPress worker | 4 source files per Cron iteration | At least 150 worker iterations for this source, before runtime delays |
| Native MIME/MCP export | 8,192 decoded bytes per call | At least ~1,502 calls for source-sized payload, likely more after ZIP overhead |
| Query Monitor | 4.0.7, DB attribution/drop-in ready | Query fingerprints, caller costs and correlation not collected yet |
| Indexes | postmeta(post_id,meta_key) and termmeta(term_id,meta_key) present | No basis to add duplicate indexes |
| Frontend | 0 accepted samples at current package identity | **No frontend performance PASS** |
| CI | 61 queued | Not CI PASS; use independent signed native Staging gate |

## Cross-layer findings and controls

| Layer | Risk / finding | Severity | Required work / evidence |
| --- | --- | --- | --- |
| MCP transport & client | Large client-to-PHP time delta, occasional opaque tool errors | P0 | Match request/correlation IDs across ChatGPT connector, remote gateway, webserver, PHP-FPM; determine timeout or queue owner |
| WordPress bootstrap | High eager import count and pre-callback request cost | P1 | Deployed phase markers `BOOT_*_AT`, OPcache/cache hit statistics, profile request classes before route-specific lazy loading |
| PHP-FPM / workers | Worker saturation/queue cannot yet be attributed | P1 | Read-only server worker/process/queue/slowlog sampling, no arbitrary limits increases |
| DB / options | High SQL count in one sample; Query Monitor attribution available | P1 | SQL fingerprint/caller total time, N+1, autoload option size, object-cache hit rate; verify index query plans |
| Catalog / Abilities | Large dynamic provider/ability surfaces | P1 | Time server registration, tools/list serialization, schema projection and generation invalidation separately |
| WP-Cron build | 599 files × batch 4; stale-state race was present | P0 fixed **in source**, live unverified | Worker reads current job under lock, catches exceptions, handles schedule failure, tests before Staging rollout |
| Artifact export | ~1,500–2,048 MCP calls per large ZIP at 8 KiB | P1 architectural | Avoid MCP chunk relay as default bulk data plane; use independently authenticated short-lived artifact transport with checksum, permission and no public filesystem exposure |
| Temporary artifacts | Immutable job workspace reused per source, no proven retention/garbage collection | P1 | Restrict total storage, safe verified cleanup and TTL, preserve current receipt, no arbitrary deletion |
| Third-party integration | WPML/JetEngine/WooCommerce/SEO hook and query costs unknown | P1 | Comparative canaries and per-component Query Monitor evidence |
| OAuth / RBAC | High-privilege mutations require owner, Step-Up, site/grant binding | P0 preserve | Negative tests for wrong site, missing scope, old approval, denied grant, production |
| Dynamic adapter mounts | Registry-based Admin mounting works on installed Staging | P1 | Exact-new-HEAD mount/denial tests after install; avoid fixed Server lists |
| Skills / context | Historical candidate checkpoint was confused with live certification in old aggregated report | P1 fixed **in source**, live unverified | Compare direct live Skills certification and snapshot tri-state |
| Retry / idempotency | Blind retry after uncertain mutation outcome may duplicate effects | P0 policy | Never retry writes without reconciliation or durable operation identity |
| Reliability / timeouts | Failing read bundle may take down composite MCP endpoint | P1 fixed **in source**, live unverified | Structured 503/partial results, no raw exception disclosure, representative repeated read probes |
| G9 provenance | Fingerprint drift caused multiple exact-source build failures | P0 controlled | Keep reviewed file/digest paired, validate all 22 G9 records, never silently update evidence |
| Release / supply chain | GitHub runners queued, ZIP/parity not certified | P0 | Independent immutable exact-SHA native tests, trusted Ed25519 Staging signer, receipt readback; Production out of scope |
| Compatibility | WP 7.1.3/PHP 8.3 site; PHP 7.4 lower bound in plugin | P1 | PHP 7.4+8.3 parse/lint and mocked WP/Cron/Ability versions; no unsupported syntax |
| Frontend / AJAX / REST | Only MCP canary evidence; full acceptance surface incomplete | P1 | At least 3 controlled frontend samples and REST/admin/cron+WPML parity |
| Observability / privacy | No gateway correlation evidence; raw user/secret logging must remain off | P1 | Structured, bounded redacted IDs/timings; retention and access limits |
| Tenant/site/environment isolation | Site UUID, profile, origin and Staging gates critical | P0 preserve | Exact identity drift and replay refusal; no implicit Production mutation |
| Rollback / database recovery | New worker behavior not yet upgraded live | P0 | Certified backup/current receipt, safe rollback/readback, no schema DDL in read-only path |

## Source corrections in this review

- `class-mad4b-scp-wp-native-build.php`: after-lock job reload to prevent
  stale concurrent worker cursor resets; typed blocked state on unexpected
  worker exception; check schedule/persistence rather than reporting a
  successful start without a queued event.
- `tests/wp-native-build-runtime.php`: test failed Cron, completed job state
  arriving after lock, and fail-closed network exception.
- Earlier pending source changes: request-local bootstrap phase timestamps,
  scoped Session-Safe diagnostics, candidate-vs-live Skills separation,
  G9 manifest repin and exact-source static inventory.

## Residual design objections that are NOT yet closed

- **Concurrent admission of two new builds:** the fixed WP-Cron worker now
  re-reads persisted current state after taking the per-source file lock.
  However `start()` is not yet protected by a *site-global atomic admission*
  lock. Two authorized requests could both observe an idle option and submit
  conflicting jobs (especially for different source heads). Requires an atomic,
  TTL/reconciliation-aware owner admission lease and receipt-bound idempotency.
- **WordPress object cache vs persistent DB:** the after-lock worker read now
  bypasses request-local `get_option()` caching and queries the prepared
  `$wpdb->options` row. The current write still uses `update_option()`,
  so a formal compare-and-swap across concurrent admission and workers has
  **not** been proved. Do not claim global exactly-once execution.
- **Cron transport reliability:** 599 files at 4 per tick implies at least
  150 iterations; WP-Cron is traffic-driven and a bounded timeout on every
  blob does not bound aggregate completion. Add measurable retry/backoff
  policy, trusted external worker fallback and storage quota before considering
  this the default build transport.
- **Source host portability:** `WP_Native_Build::REPO` and `PLUGIN`
  remain fixed source family constants even though Ability mounting uses the
  dynamic Adapter Registry. Supporting arbitrary sites or repositories still
  needs a reviewed, site-bound source-provider policy with exact trust roots,
  never unaudited user-provided repository URLs.
- **Large artifact export:** 8KiB chunks over MCP create an excessive
  serial round-trip budget; a secure artifact download/data plane is
  **not implemented**. No arbitrary public download URL should replace it.
- **Temporary source retention:** no proven bounded garbage collector or
  per-site disk-budget policy for finished/blocked private workspaces.
  Over time jobs could accumulate disk use. Needs explicit retention,
  ownership/identity proof, cleanup receipts and safe rollback.
- **Upgrade identity:** the plugin header still says `0.4.0-rc.96` across
  different source SHAs. Operators must use exact commit, package digest and
  artifact identity rather than the version string alone for freshness.

## Recommended architecture — dynamic, no static Ability hardcodes

Preserve the trusted Adapter Registry dynamic declarations and original WP
permission callbacks. Add an independent, bounded **artifact transfer plane**:
a short-lived one-time Staging-only download reference tied to site UUID,
exact commit, package digest, enrolled owner, Step-Up, approved operation and
range budget, with no publicly guessable path and a final full SHA-256 check.
Bulk artifact transfer should never transit thousands of serial MCP messages.
This is a **design requirement, not implemented or approved** in this audit.

Gradually move modules from unconditional PHP loading to declarative
request-scope/classmap loading, but only after class/registration dependency
mapping and shadow boot parity on native MCP, WordPress Admin, AJAX, Cron,
WPML/third-party and restricted-site negative cases. Do not disable
require_once or alter authorization to chase a static line-count target.

## Acceptance protocol before closure

1. Pin an immutable SHA and independently validate G9 evidence, package manifest,
   PHP syntax, runtime fixtures, canonical ZIP and dependency inventory.
2. Verify negative authorization, missing OAuth Step-Up, production refusal,
   restore/rollback, concurrent Cron jobs and retriable read vs nonretriable write.
3. Independently sign a Staging-only native report when Actions are unavailable.
4. Install the exact certified Staging package, read back runtime and source hash;
   reconcile current grants **to that installed package**, not to earlier refs.
5. Repeat sequential identity/full/projection/write-readiness and archive canaries,
   log client/gateway/PHP timers with matching correlation IDs, report p50/p95
   and failure rates, query fingerprints and FPM queue pressure.
6. Collect controlled frontend/admin/REST/Cron + third-party parity data.
7. Mark P0/P1 closed only on passing tests and live readback; do not treat
   old Staging source status as certification of the new PR HEAD.

**No Production modification or CI/approval bypass authorized here.**
