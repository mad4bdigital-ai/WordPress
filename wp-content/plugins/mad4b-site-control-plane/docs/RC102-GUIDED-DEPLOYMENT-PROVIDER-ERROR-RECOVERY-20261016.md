# MAD4B rc.102 — Guided onboarding, provider fleet and diagnostic recovery

Source: PR #395 follow-up to `3f26b8480a569229489534ed189a00027a1684ec`
Scope: ETG and All Royal Egypt, **Staging-only**. This document does not certify deployment, grant additional authority, or authorize Production.

## User workflow after install

1. **Deployment Binding** appears immediately after site identity in Action Center. Independent host binding remains an external/site-owner gate.
2. **Connection, schema, Managed Skills and exact Write binding** continue using their existing authoritative receipts. Readiness is never guessed from plugin ZIP upload alone.
3. **Provider fleet** uses `mad4b/provider-closure-matrix` with `include_plugin_fleet=true`; additional `plugin_fleet_offset` and `plugin_fleet_limit` paginate across every discovered plugin, keeping the read response bounded (default 32, maximum 50 per page). Each record states the level and its next evidence gate, never grants or promotion.
4. **Owner approvals** remain exact ticket-by-ticket. When a ticket is `wp_admin_human_only`, no AI or catch-all authorization is substituted. Unknown/high-risk plugin writes remain denied.
5. **Operator UI** presents the next relevant safe step prominently; less urgent tasks are collapsed. It explains how to diagnose MCP errors without blind write retries.

## Runtime diagnostics and error isolation

- `mad4b/runtime-convergence-status` now returns `checkpoint_operator_state`. An old `pending_manual_resume` tied to a previous source SHA is visibly `superseded_audit_only` **only when the current required phases have no blockers**. The checkpoint is never deleted, replayed or used as authorization.
- `guided_next_actions` distinguishes required, optional, auto-safe, owner-gated, and independent evidence phases. Core readiness does not mean optional plugin certification or browser/release acceptance.
- `mad4b/session-safe-diagnostics` accepts `diagnostic_scope=triage` (identity and runtime bundles, defers others). The `full` default and existing `identity` scope remain supported.
- `diagnostic_retry_policy` recommends one triage request, then `mad4b/read-snapshot-header` followed by **one generation-bound** `mad4b/read-diagnostic-bundle` as necessary. No parallel deep fanout and no replay of uncertain mutations.
- Optional runtime observations (REST compatibility, Provider Closure Matrix and query performance) catch internal exceptions, report bounded typed incidents and fail closed instead of crashing the entire Runtime Convergence response. Provider state becomes `unverified` if its observation is absent; it must never show `ready` because a backend call failed.

## Defects evidenced on ETG and ARE — not falsely closed

| Evidence | ETG | All Royal Egypt | Outcome / required verification |
|---|---|---|---|
| Source plugin | rc.102 `f6080e58...` | Same | New guided patch requires separate Staging install and identity reconciliation |
| Write Binding | 102/102 ready at last independent read | 102/102 ready | New package install will require fresh reconciliation if source SHA changes |
| Managed Skills | Current earlier build certified | Current earlier build certified | Revalidate for new build |
| Provider Closure | 28 governed-gated abilities | 28 | Each requires its exact reversible, source, transport, canary, approval and/or rollback evidence |
| Frontend host loopback homepage | HTTP 500 | HTTP 500 | WordPress-host vs public-route discrepancy unresolved; do not treat public 200 as origin acceptance |
| WordPress-host REST root | HTTP 200 | HTTP 200 | Supports a narrower network route only |
| Automated Chrome browser | HTTP 403 challenge | HTTP 403 challenge | Bot protection challenge; cannot certify JS/AJAX/UX via automated browser |
| Performance samples | 10.12s / 1,890 DB queries / about 211MiB peak memory, one sample | 2.70s / 513 DB queries / about 86.7MiB, two samples | Over target on time and queries; insufficient sampling for formal score |
| Protected backup root | ready | missing | Prepare by governed site/host-owned process and independently verify |
| ETG JetSmartFilters filter 16032 | `_query_var=tour_cities` preserved | not applicable to ETG target | Reversible recertification ticket `fab2e2ce-...` was created; requires separate human admin decision |
| ETG Elementor template 23210 | structural validation previously passed | not tested | Live Heading behavioral recertification and external acceptance outstanding |

## Test and acceptance policy

The changes cover a safer and clearer *operating experience*, not general automatic approval or automatic plugin mutation. No auto-generating executable adapters; no arbitrary plugin code execution; no Developer / Breakglass / Production. Before site certification: complete CI, build/verify a new exact-head Staging ZIP, install only with approved change path, verify provenance, reconverge Skills and Write, and obtain independent tests for origin HTTP, browser render/filter parity, provider capabilities and backup/recovery.
