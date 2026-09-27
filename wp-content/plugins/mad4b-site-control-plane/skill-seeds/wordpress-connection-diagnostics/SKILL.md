---
name: wordpress-connection-diagnostics
description: Verify and diagnose the MAD4B WordPress connection when the user asks about MCP reachability, OAuth, ChatGPT connection certification, transport binding, external handshake, read-gateway readiness, connector timeouts, session termination, or retry behavior.
---

Use this skill only for the connection and connector-resilience layer.

1. Start routine diagnostics with `mad4b/connector-preflight`. Keep `include_staging_certification=false` and `include_operation_discovery=false` unless the task specifically requires them.
2. Treat `mad4b/connector-preflight` as a compact first-pass view, not a replacement for exact deep diagnostics.
3. Prefer sequential calls. Do not fan out many expensive read abilities in parallel. Keep ordinary connector diagnosis to at most two concurrent read calls.
4. For a read-only failure, inspect its structured category and action:
   - `timeout`, `transport`, or `upstream_unavailable`: reconnect if needed and retry once only when `automatic_retry_allowed=true`.
   - `rate_limit`: respect `retry_after_seconds` when present; never immediately replay.
   - `authorization`: repair scope, subject, grant, approval, or authority. Do not retry unchanged.
   - `contract_or_validation`: repair the request/schema/ability contract. Do not retry unchanged.
   - `request_budget`: reduce diagnostic scope and rerun the compact preflight. Do not auto-retry the same broad request.
   - `internal`: inspect or escalate the internal fault. Do not retry unchanged.
   - `unknown`: inspect before retrying.
5. Never automatically replay a write or enrollment operation. If the result reports `mutation_state=unknown`, `reconciliation_required=true`, or a transient remote error after execution may have started, read the target postconditions first, determine whether the mutation committed, then re-plan from current truth.
6. Never treat `retryable=true` as equivalent to immediate automatic retry. `automatic_retry_allowed` and `client_action` control the next step. For budget exhaustion, follow `reduce_scope_then_retry_preflight`.
7. Read `mad4b/connection-status` when full endpoint, transport, subject-bridge, OAuth/preflight, or peer-certification details are required.
8. Read `mad4b/runtime-authority-status` to confirm effective authority without treating read connectivity as write authorization.
9. Use `mad4b/diagnostics-health` only when distinguishing connection failures from provider/runtime degradation.
10. Use `mad4b/staging-certification-status` only for explicit deep certification. Prefer its compact projection for connector-facing summaries; do not request deep evidence casually.
11. For operation discovery, use a narrow query and bounded `limit`. Keep ability-hint expansion off unless discovery actually requires it.
12. If external handshake is already verified and certification blockers are empty, do not reopen OAuth, PKCE, token, or MCP endpoint work merely because a provider adapter is degraded.
13. Never expose access tokens, refresh tokens, authorization headers, private keys, raw session identifiers, or raw exception messages.
14. Return a gate-by-gate result: environment, build/provenance, transport, OAuth/preflight, external certification, authority, failed/retryable checks, blockers, and the next layer that actually needs work.
15. If a connector call itself terminates before a structured server response is available, re-establish the connector session and resume from the compact preflight. Do not assume the failed call mutated nothing unless it was a proven read-only operation.
