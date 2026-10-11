# MAD4B MCP — Incident isolation and response-contract reliability

Updated 2026-10-11, PR #258. This runbook documents **observed** failures and
source remediations. It does not certify a newer source as installed or promise
that application-side changes can remove latency outside the WordPress process.

## Live evidence: what is and is not known

Multiple read-only calls to All Royal Egypt Staging returned successfully, while
some calls with the same inputs returned an opaque connector error
(`The tool failed internally.`). Measured client-side round-trips for those
read-only calls varied by operation and attempt. One independently inspected,
successful `mad4b/session-safe-diagnostics` response reported:

| Layer | Observation |
| --- | --- |
| Client/wrapper measured round-trip | approx. 22,560 ms |
| PHP request elapsed by server | 3,857 ms |
| WordPress diagnostics work | 57 ms |
| PHP request overhead outside diagnostics | 3,800 ms |
| Number of DB queries in request | 425 |
| PHP included files | 6,223 |
| PHP peak memory | 52,428,800 bytes |
| Remote network calls initiated by diagnostics | 0 |
| Runtime identity | Generation SHA, source and Site Profile are available |

The difference between the client round-trip and PHP request elapsed is not
instrumented by this plugin. It **must not** be attributed categorically to
WordPress, ChatGPT, the MCP transport gateway, networking or a particular
external component without gateway/request tracing. Session-Safe's PHP
diagnostic duration alone is a poor indicator of WordPress bootstrap cost.

## Source-level improvements

1. **Projection status fast path.** `mad4b/chatgpt-tool-projection-status`
   returns the metadata summary without invoking optional MCP Protocol Profile
   diagnostics. Use `include_protocol_profile=true` for explicit deep review.
   The default returns `ready=null,state=deferred`, never a fabricated PASS.
2. **Session-safe fast path.** Use `diagnostic_scope=identity` to read only
   identity/build/connection/reconnect evidence. Full is still the default for
   compatibility. Omitted runtime/authority/certification sections appear
   `deferred_scope`, set partial evidence and cannot certify anything.
3. **Connector preflight fast path.** `mad4b/connector-preflight` accepts the
   same `diagnostic_scope=identity` to avoid current-grant scanning and remote
   peer status where only source identity is needed. The response explicitly
   denies full authority evaluation in that mode.
4. **Error isolation.** Unexpected errors in independent diagnostic bundles
   return typed, partial, read-only results; exceptions and invalid data in
   generation snapshots return a 503-class WP_Error with non-sensitive recovery
   metadata. Failed/missing generation proofs cannot yield valid evidence.
   Only known transient reads may retry; mutations must not retry blindly.
5. **Skills truth separation.** An old `persisted_status` plus a candidate
   package identity proves a historical checkpoint, NOT live filesystem or
   provider certification. The Session-Safe operator report now exposes
   `skills_candidate_checkpoint_ready` separately from
   `skills_runtime_ready=null` when live validation is deferred. The dedicated
   `mad4b/skills-runtime-certification` is the live read-only truth source.
   A blocked current write-grant snapshot remains a blocker.
6. **CI-independent validation.** These acceptance tests can be executed by a
   trusted local PHP runner on the exact HEAD; queued GitHub Actions are not a
   substitute for tests or signed Staging package evidence.

## Practical MCP incident sequence

Use one lightweight read at a time. Do not launch parallel site-info, projection
status, managed Skills, full catalog, and full write-authority reads merely to
verify whether the same connection is alive.

```json
{"diagnostic_scope":"identity","budget_ms":4000,"retry_transient_reads":false}
```

Call `mad4b/session-safe-diagnostics` with that input after the updated source
is installed. The existing Site Profile and exact source identity may be
checked separately if this call is unavailable. If identity is reachable,
request `diagnostic_scope=full` to examine the current write-grant snapshot.
For critical approval operations, discover the exact mount/authority and use
one-time scoped approval; a successful read is not write authority.

For any timeout/opaque connector failure, record:
- UTC timestamp, exact tool name, request count, client round-trip duration,
  WordPress `request_elapsed_ms` and `diagnostic_elapsed_ms` if available;
- deployed source SHA, runtime generation, Site Profile and server origin;
- HTTP/MCP gateway correlation ID and sanitized server log error fingerprint
  **outside** the plugin where available (never secrets or OAuth tokens);
- whether a PHP request actually reached WordPress; no response does **not**
  prove that a mutation did not start.

Compare request overhead, DB queries, PHP included-file count, and memory against
the **previous exact Staging release**, not generic universal thresholds. If PHP
reports minimal elapsed time but the remote wrapper is consistently slow,
investigate the MCP client/gateway/proxy tracing and connection timeout budget.
WordPress source edits cannot directly fix those components.

## Acceptance gates

- Independent PHP 8.3 syntax and runtime fixtures on the immutable exact SHA.
- Test one identity-only request and one full request, then repeat controlled
  sequential requests and record error rate/client-vs-server timing.
- Prove false/stale grants remain BLOCKED; no historical Skills certification is
  promoted to live success; exception text is not exposed.
- Deploy certified exact-HEAD Staging package, verify readback, then exercise
  the new request modes through the **live** MCP endpoint.
- Preserve the independent signed CI-outage Staging update path if the GitHub
  queue is unavailable; no Production/mutation safety bypass.

## Full bottleneck inventory — static evidence and priority

The exact PR source (before future optimization) contains **377 literal direct
`require_once` statements** in the main plugin, **375 unique imported
paths**, about **9.1 MB total referenced PHP source bytes** (not resident RAM),
and **115 appearances of bootstrap-family method calls**. Some imports and
bootstrap actions run conditionally; size/count are *potential parsing and
autoload pressure*, **not measurements of actual time spent**. WordPress Core,
Query Monitor, WPML, JetEngine, other plugins, and the gateway add their own
costs. Do not assume the 6,223 total PHP files are all MAD4B.

The three largest directly imported sources in that inventory were:

| Candidate | Static source bytes |
| --- | ---: |
| `includes/class-mad4b-scp-google-drive-context.php` | 247,775 |
| `includes/class-mad4b-scp-context-authority.php` | 214,651 |
| `includes/class-mad4b-scp-context-admin-ui.php` | 209,524 |

These are candidates for measured **lazy-loading and route-specific isolation**;
they must **not** be omitted from boot blindly because their classes/hooks may
be required for the exact MCP Ability and current security policy. There are
also two duplicate path references (Site Profile and MCP Request Scope);
`require_once` prevents duplicate execution, so do not assign them double
parse cost.

The new `MAD4B_SCP_BOOT_START_AT`, `BOOT_MODULES_READY_AT`, and
`BOOT_HOOKS_READY_AT` constants create three read-only timing boundaries in
the plugin entrypoint. `session-safe-diagnostics` now reports
`plugin_bootstrap_phases` with module loading, hook registration, total
plugin bootstrap, and pre-plugin request time. Absence/invalid chronology is
`valid=false`, not a manufactured zero-latency PASS. **These times will only
appear after the new package is installed.**

The reusable, source-driven inventory can run without GitHub Actions,
network, a working WordPress DB, PHP or any signing authority:

```bash
python tools/mad4b_mcp_bottleneck_inventory.py --repository-root . --output mcp-inventory.json
```

To compare another exact-source release use `--baseline` pointing to an
independently saved prior inventory. This script is observational and creates
no authorization, deployment or release verdict.

### Prioritized root-cause matrix

| Bottleneck candidate | Observed support | Action / acceptance |
| --- | --- | --- |
| MCP client → gateway/proxy/connector | ~18.7s discrepancy in one sample, origin unknown | Capture matched trace IDs and timestamp stages before assigning blame |
| Pre-callback PHP WordPress bootstrap | ~3.8s observed before/around 57ms diagnostics | Measure per-plugin phases, PHP-FPM queue time, loaded plugin hooks |
| Large eager MAD4B plugin source and boot | 377 direct imports, ~9.1MB static PHP source, 115 bootstrap call appearances | Route-scoped classmap/autoload pilot **only after profiling and regression tests** |
| Database / options / meta / object cache | 425 requests in one PHP sample; Query Monitor DB collector active | Identify top SQL fingerprints, count and aggregate time; inspect persistent object cache and autoload options |
| Postmeta and termmeta lookups | Explicit two relevant composite indexes reported ready on Staging | **Do not re-create indexes.** Query-profile first, compare prior exact build |
| WPML, JetEngine, SEO, WooCommerce, third-party hooks | Many plugins included in WordPress request but no caller-level attribution yet | QM tagged caller/component comparison, controlled route and plugin-level timing |
| Schema/catalog creation and WordPress Abilities registration | Many dynamically mountable abilities and adapter providers | Inspect tools/list serialization, schema and registry generation frequency, request-local caches |
| Managed Skills / current write-grant checks | Full diagnostics can run live grant scans and historical checkpoints | Default light mode for availability probe; deep only when requested; never cache auth truth across requests |
| Query Monitor / telemetry / shutdown | QM active with DB drop-in; MCP surface intentionally avoids full QM collection | Verify one-marker-only MCP capture; opt in to bounded profiling when evidence is needed |
| WP-Cron / scheduled workers / queue | Update/build/maintenance workers exist, queue depth and PHP worker saturation unmeasured | Correlate Cron and PHP-FPM occupancy/locks, avoid synchronous work in MCP reads |
| OPcache/preload, PHP-FPM and object cache | No live authoritative settings or hit-rate evidence here | Collect config/hit rates before tuning limits, do not assume caches enabled |
| Frontend performance | Fresh build shows **zero** eligible frontend acceptance samples | Run required controlled canaries; no frontend pass claim |
| GitHub Actions queue | 61 queued runs at observation | CI-independent native acceptance path; not a site request latency explanation |

Staging's read-only `mad4b/admin-query-performance-status` reported both
`wp_postmeta(post_id,meta_key)` and `wp_termmeta(term_id,meta_key)`
equivalent indexes present and `ready=true`. Query Monitor 4.0.7 attribution
was also active, but only one MCP coverage marker and **no frontend samples**
were present. Avoid unnecessary DDL and unsupported frontend claims.

Performance acceptance requires repeating enough representative read-only
requests to report medians/p95 and failure rates, with source/generation pinned,
plus a separately sampled frontend baseline and caller-grouped DB time.
One successful request and nine source-level invariants cannot establish
tail latency or outage elimination.
