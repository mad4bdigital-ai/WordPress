# PR #403 — MCP runtime failures: two Staging sites

Scope: Egypt Tour Gates and All Royal Egypt (Staging). Read-only analysis and non-authorizing transport guidance.

## Live evidence

- Fourteen parallel MCP reads (seven per Staging site) caused intermittent opaque internal host errors. Failures differed between sites and included ordinary site-info and Site Profile reads.
- Serial retest of ETG site-info and Site Profile returned correctly. Dynamic capability preparation followed by generation/authority-pinned read-execute for staging-write-owner-review also succeeded.
- The same prepared read sequence on All Royal Egypt succeeded with one inner read attempt. This proves those PHP read paths are functional when called sequentially, but does not establish exact causality for the connector's concurrent errors.
- Calling the stable write-info catalog with direct-only staging-write-authority-convergence-apply returns an expected stable-catalog refusal. Discover the appropriate lane before selecting a dispatcher.
- Both sites remain on source commit 3337ab6104a4e23f9182ea23bf31b932edbca914 and have an old candidate binding and expired Owner Intent. No automatic write convergence is authorized.

## Read-side execution workflow

1. Run mad4b/session-safe-diagnostics for a comprehensive snapshot, not parallel individual status calls. Its existing transport policy already implements server-side sequential execution in one MCP request.
2. For an additional read, prepare precisely one Ability with mad4b/chatgpt-tool-projection-discover, then call mad4b/read-execute with the exact signed receipt, expected input schema, lane, classification and authority-scope digests.
3. Allow at most one in-flight MCP tool call per site from the caller. On a shared hosting account, conservative clients should also avoid flooding the host across both sites.
4. If a read transport fails, do not infer a PHP fatal or a write outcome. Inspect runtime generation, refresh the prepared receipt and retry only a verified read-only operation, if appropriate. Never blind-replay writes or uncertain jobs.
5. Direct-only owner Step-up and Developer abilities cannot be treated as stable Write Broker items. Preserve all original OAuth, owner, environment, exact-head and Breakglass restrictions.

## Patch scope and limitations

Session-safe Diagnostics now publishes explicit client-side single-flight advisory metadata and disallows blind mutation retries. The plugin cannot enforce scheduling of independent MCP tool invocations initiated by an external client. This is a non-authorizing interface contract, not evidence that the host-level concurrency issue is repaired.

Expected acceptance: request-per-site serial read probes on both sites after deployment; verify scoped owner receipt and current candidate; separately test bounded concurrency through the host with logs before claiming the transport issue closed.