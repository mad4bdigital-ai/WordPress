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

Authorization, validation, schema, contract, and unknown failures are not
automatically retried. Rate-limit failures remain retryable later but are not
retried immediately; clients must honor bounded backoff and any safe Retry-After
hint exposed by the resilience envelope.

### Mutation and enrollment execution

Mutation and enrollment callbacks MUST execute at most once per dispatcher call.

A timeout, disconnect, 429, 502, 503, 504, session termination, or other
transient-looking exception MUST NOT trigger an automatic mutation retry because
the remote side may already have committed state.

When an exception escapes a mutation callback, the response must report:

- `mutation_state=unknown`;
- `reconciliation_required=true`;
- `blind_retry_allowed=false`;
- `automatic_retry_performed=false`.

The next action is postcondition reconciliation/readback, not replay.

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
check failed semantically.

### Payload discipline

Heavy diagnostics must expose compact modes or bounded projections for routine
connector use. Large raw evidence remains available through explicit deep
diagnostic calls.

Discovery endpoints must have explicit result limits. Optional ability-hint
expansion must be opt-in.

### Error taxonomy

The shared classifier uses stable categories:

- `rate_limit`;
- `timeout`;
- `transport`;
- `upstream_unavailable`;
- `authorization`;
- `contract_or_validation`;
- `unknown`.

Remote responses expose an error fingerprint and class when available, but never
the raw exception message.

### Cache and circuit-breaker policy

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
