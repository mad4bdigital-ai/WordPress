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
