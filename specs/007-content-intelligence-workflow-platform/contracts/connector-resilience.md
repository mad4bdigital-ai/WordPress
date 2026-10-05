# Connector Resilience Contract

Contract: `mad4b.connector-resilience.v1`

## Purpose

All MCP-facing and remote-connector surfaces must fail predictably under transient
transport faults, oversized diagnostics, provider failures, and uncertain
mutation outcomes without weakening authority, exact-build, approval, or
rollback requirements.

This contract is provider-neutral and applies to current and future connectors,
providers, adapters, and remote-operation surfaces.

## Core policy

### Read-only execution

Read-only callbacks MAY be retried automatically only when the shared resilience
classifier marks the failure transient.

The default retry budget is exactly one retry:

- first attempt;
- one retry for a classified transient failure;
- no further automatic attempts.

If both attempts fail, the final envelope must report
`automatic_retry_exhausted=true`, `automatic_retry_allowed=false`, and a
non-immediate recovery action. The condition may remain `retryable=true` for a
later operator/client attempt, but the current request chain must not start a
third automatic attempt.

Authorization, validation, schema, contract, and unknown failures are not
automatically retried. Rate-limit failures remain retryable later but are not
retried immediately; clients must honor bounded backoff and any safe Retry-After
hint exposed by the resilience envelope.

### Mutation and enrollment execution

Mutation and enrollment callbacks MUST execute at most once per dispatcher call.

A timeout, disconnect, 429, 502, 503, 504, session termination, or other
transient-looking exception MUST NOT trigger an automatic mutation retry because
the remote side may already have committed state.

Once a mutation callback has been entered, any escaping exception or returned
`WP_Error` is treated as postcondition-uncertain unless a separate preflight
rejected the operation before callback entry. Raw mutation error messages must
not be returned to the remote caller. The response must report:

- `mutation_state=unknown`;
- `reconciliation_required=true`;
- `blind_retry_allowed=false`;
- `automatic_retry_performed=false`.

The next action is postcondition reconciliation/readback, not replay. This rule
also applies to validation/contract `WP_Error` values returned after callback
entry because the dispatcher cannot prove that no state changed before the
error was produced.

### Partial diagnostics

Composite diagnostics must isolate independent checks. One failing check must not
erase successful sibling evidence.

The preferred connector entry point is `mad4b/connector-preflight`, which:

- uses compact projections;
- executes checks sequentially;
- returns `state=partial` when one or more checks fail;
- identifies failed, retryable, and request-budget-skipped checks;
- never mutates state.

### Request budget

Composite diagnostics have a bounded request budget. Once the budget is
exhausted, no new check is launched. An already-running callback is never
force-aborted by the resilience layer.

Budget exhaustion is a retryable read condition, not evidence that the skipped
check failed semantically. It is never immediately auto-retried. The structured
action is `reduce_scope_then_retry_preflight`: reduce the diagnostic scope and
rerun the compact read-only preflight.

### Result preservation

Successful governed reads must preserve the callback's result type and value,
including arrays, scalars, booleans, and `null`. The resilience envelope must
not replace a valid scalar or `null` result with type metadata or an empty
array.

### Payload discipline

Heavy diagnostics must expose compact modes or bounded projections for routine
connector use. Large raw evidence remains available through explicit deep
diagnostic calls.

Discovery endpoints must have explicit result limits and deterministic bounded
pagination. Optional ability-hint expansion must be opt-in. Multi-token searches
rank results by relevance before pagination and the result limit are applied so
broad early catalog entries cannot hide a more specific operation. Pagination
must expose total-match and next-offset metadata without requiring persistent
server-side cursor state.

### Error taxonomy

The shared classifier uses stable categories:

- `rate_limit`;
- `timeout`;
- `session_terminated`;
- `transport`;
- `upstream_unavailable`;
- `authorization`;
- `contract_or_validation`;
- `request_budget`;
- `internal`;
- `unknown`.

Remote responses expose an error fingerprint and class when available, but never
the raw exception message.

### Transport boundary limitation

The WordPress runtime cannot catch a connection failure that happens before the
request reaches PHP or after the HTTP/MCP response has already left WordPress.
The server-side mitigation is therefore to reduce fan-out and payload size and
to expose deterministic recovery semantics.

After an external session disconnect:

- read-only callers reconnect and begin with `mad4b/connector-preflight`;
- `session_terminated` is classified separately from generic transport failure;
- two session-termination observations exhaust the request-local termination budget;
- once that budget is exhausted, the current composite read stops launching sibling checks and reports `skipped_session_breaker`;
- the circuit breaker is request-local only and is never persisted;
- mutation callers do not replay the mutation blindly;
- mutation callers reconcile postconditions and re-plan if state is uncertain.

### Snapshot-aware reconnect and resume

The companion read-consistency contract is `mad4b.read-consistency.v1`.

Clients start a diagnostic transaction with `mad4b/read-snapshot-header`. The
header binds the transaction to a `runtime_generation` derived from exact build
identity, candidate binding, provider/tool inventory, active-plugin generation,
and Site Profile identity. Session identity is kept separate from runtime
generation so a reconnect can resume on the same runtime.

Routine diagnostics use only fixed bundles exposed by
`mad4b/read-diagnostic-bundle`:

- `identity`;
- `runtime`;
- `certification`;
- `providers`.

Execution preparation that needs exact Ability or governed-operation metadata
uses the compact `mad4b/read-metadata-envelope`. The Ability is mounted on
`mad4b-read` and remains hidden from the direct `mad4b-chatgpt` tool list;
ChatGPT invokes it through the existing `mad4b/read-execute` dispatcher. One
generation-bound response contains only the metadata needed for safe planning:
registration identity, canonical enrollment `dispatch_policy_digest`,
input/output schema digests, metadata digest, authority surface, and an
execution-binding digest. It does not return a full catalog.

For governed enrollment operations the envelope MUST obtain registration,
dispatch-policy, and input-schema identity from
`MAD4B_SCP_Enrollment_Dispatch::info()`, and MUST fail closed if the live
Ability schema digest differs from that canonical dispatch identity. Ability
metadata lookup MUST be limited to the governed ChatGPT capability universe;
raw-SQL/breakglass or unrelated registered Abilities may not be probed through
this path.

The bundle API never accepts caller-selected tool names. Every bundle runs
sequentially through `MAD4B_SCP_Connector_Resilience::run_checks()`.

Before and after each bundle the runtime generation is re-read. A mismatch makes
all evidence from that bundle invalid for merging and returns
`client_action=restart_read_transaction`,
`valid_for_merge=false`, and `discard_partial=true`.

After an external MCP session termination the client policy is:

1. discard the dead external session identifier;
2. reconnect at most once for the current recovery attempt;
3. fetch a new snapshot header;
4. if runtime generation matches, retain completed bundles and resume only the
   missing read-only bundles;
5. if runtime generation changed, discard partial evidence and restart the
   diagnostic transaction;
6. never replay a mutation or enrollment automatically.
7. when an `approval-plan` response is uncertain, call `mad4b/approval-plan-reconcile` with the exact original payload before creating another approval plan; an existing pending/approved/executing/used/failed exact payload blocks blind replay.

The preferred read parallelism is one. The hard client ceiling is two independent
lightweight reads. Large `Promise.all`-style diagnostic fan-out is outside the
contract.

Evidence returned by read-consistency bundles records a live projection
freshness and source generation. Nested external attestations keep their own
freshness semantics; a fresh local projection does not convert stale external
evidence into live evidence.

No persistent read transaction, session circuit breaker, authority cache, or
resume cursor is stored in WordPress.


## Session-safe composite diagnostics

Composite connector health inspection MUST prefer
`mad4b/session-safe-diagnostics` over issuing multiple status tools in parallel.

The session-safe diagnostic ability:

- executes the fixed `identity`, `runtime`, `certification`, and `providers`
  bundle order sequentially inside one WordPress request;
- binds the complete report to one runtime generation and discards the report
  if that generation changes before completion;
- uses the shared request budget and transient read classifier;
- returns compact allowlisted projections rather than raw provider/catalog
  payloads;
- caps scalar blocker lists and other repeated status vectors;
- enforces a hard 16 KiB response budget and automatically reduces oversized
  results first to section summaries and then to section digests;
- requires exactly one external MCP call for the composite report and explicitly
  declares direct composite fan-out unsupported;
- remains read-only, non-authorizing, and incapable of Production mutation.

Broad status abilities remain available on the governed logical/read surface for
deliberate single-scope inspection, but they MUST NOT be projected as direct
`mad4b-chatgpt` tools. The ChatGPT direct catalog uses an explicit reviewed
read allowlist centered on `mad4b/session-safe-diagnostics`, discovery/info and
single-target dispatch. Deep follow-up uses `mad4b-read` or exactly one
generation-bound `read-execute` target at a time.

### Deterministic recovery routing

A session-safe report that is coherent but not operationally ready MUST expose
exactly one recommended next step for the highest-priority current blocker.
This recommendation is orchestration guidance only and never grants authority.

Current routing rules are:

- partial report -> inspect the bounded partial report and retry only the missing
  scope; do not invent a mutation target;
- ineffective current Write Authority -> request the read-only
  `mad4b/full-staging-authority-handshake` as the next single call;
- ineffective managed Skills runtime -> direct the operator to
  `mad4b/reconcile-managed-skills`, but mark explicit authority required and
  `automatic_apply_allowed=false`;
- ready subject -> continue with one target operation.

The recommendation must survive payload reduction in compact form. It never
authorizes blind retries, parallel diagnostic fan-out, implicit Write/Developer
enablement, or Production mutation.

### Session-safe comparative performance observation

Routine session-safe diagnostics expose a comparative performance observation
without inventing one universal DB-query, include-count, or memory threshold.

The observation is bound to the same runtime generation and records:

- diagnostic elapsed milliseconds and diagnostic-budget headroom;
- total request elapsed milliseconds as a separate comparative signal;
- database query count;
- included-file count;
- current and peak memory usage.

These values are operational signals, not authorization gates. Performance
acceptance compares them with the **previous exact Staging release** for the same
site/workload profile. Material release-to-release regression requires review;
one large absolute number alone does not prove a defect unless it violates an
existing explicit diagnostic/payload/time budget. The diagnostic budget MUST
be compared with diagnostic elapsed time, not total request latency.

The observation must therefore declare:

- `comparison_required=true`;
- `comparison_baseline_scope=previous_exact_staging_release`;
- no fixed universal DB-query/include-count/memory threshold;
- a client action to compare the exact release baseline before performance
  acceptance;
- `authorizing=false`, `read_only=true`, and `mutation_performed=false`.

Payload reduction must preserve a compact form of this observation so recovery
clients do not lose performance context merely because diagnostic sections were
compressed.

## Full Staging Authority session-safe handshake

ChatGPT MUST NOT prepare Full Staging Authority by issuing the deep
`full-staging-authority-status` and `full-staging-authority-plan` reads as a
direct multi-call sequence.

The direct ChatGPT projection uses exactly one compact read-only ability:
`mad4b/full-staging-authority-handshake`.

The handshake:

- evaluates the exact internal full authority plan once;
- captures a runtime-generation snapshot before and after planning;
- fails closed if the generation changes during preparation;
- returns only the bounded blockers/readiness summary and the exact fields
  required by `full-staging-authority-apply`;
- exposes the candidate-binding current/stored source SHA pair without returning
  the full write/developer plan trees;
- has an 8 KiB response budget;
- performs no mutation and grants no authority.

The deep status and plan abilities remain available through governed read
dispatch for deliberate diagnosis, but they are not direct ChatGPT read tools.

After an external session termination, the client reconnects and requests a new
handshake. A mutation is never replayed merely because the preceding transport
session ended.

## Cache and circuit-breaker policy

The resilience layer must not add persistent authority/catalog caches or a
persistent circuit breaker. Runtime authority and provider eligibility must be
re-evaluated on the next request.

Request-local memoization elsewhere remains permitted when already governed by
the catalog contracts.

## Future connector onboarding

New connector/provider integrations must use the shared
`MAD4B_SCP_Connector_Resilience` service rather than adding custom retry
heuristics.

New composite diagnostics should:

1. expose a compact projection;
2. call `run_checks()`;
3. keep checks independent;
4. avoid parallel fan-out by default;
5. cap list/discovery outputs;
6. preserve exact error categories and fingerprints;
7. route mutations through `execute_mutation()`.

## Verification requirements

CI must include fault-injection tests and prove:

- transient reads retry no more than once;
- one recovered session termination does not poison sibling reads;
- repeated session termination opens only the request-local breaker and stops additional fan-out;
- reconnect/resume requires an exact runtime-generation match;
- the compact metadata envelope stays hidden from the direct ChatGPT tool list and remains reachable through governed read dispatch;
- enrollment metadata digests exactly match the canonical enrollment execution contract;
- metadata lookup outside the governed ChatGPT catalog fails closed;
- transient WordPress `WP_Error` reads follow the same bounded retry policy;
- rate-limit reads do not retry immediately and preserve safe backoff hints;
- permanent read failures do not retry;
- mutation callbacks execute exactly once under injected timeout;
- uncertain mutation exceptions require reconciliation;
- raw exception messages are not returned;
- operation discovery remains bounded;
- compact Staging certification exists;
- the connector preflight is mounted on governed read and ChatGPT surfaces;
- no generic raw shell/SQL capability is introduced;
- Production mutation remains unauthorized by this contract.
