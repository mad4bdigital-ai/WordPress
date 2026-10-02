# Contract — Post-merge Capability Fabric completeness

Contract: `mad4b.capability-fabric-post-merge-completeness.v1`

## Purpose

Turn the residual Capability Fabric review after PR #230 into a machine-checkable, non-authorizing closure program.

## Invariants

1. The closure ledger is descriptive/governance metadata. It never grants OAuth, site, provider, write, Developer, Breakglass, Host or Production authority.
2. Every Phase 37 task belongs to exactly one closure workstream in the machine-readable ledger.
3. Every workstream declares task IDs, existing quality families, dependencies, interim fail-closed/non-authorizing behavior, acceptance evidence strategy and current status.
4. Unknown workstream/task/status/quality identifiers fail Spec Kit validation; quality-family vocabulary is fixed by the closure ledger and validator, not trusted from individual rows.
5. OPEN/PARTIAL work may not be represented as complete merely because documentation exists.
6. Runtime/security/live acceptance must be closed by executable or external evidence appropriate to the task.
7. The Critical Kernel terminal gate remains `critical_kernel_vertical_slice_verified`; Phase 37 is a maturity overlay unless an explicit governed change later admits a dependency.
8. Production activation, Breakglass widening, generic shell, arbitrary PHP/`wp eval` and generic raw SQL remain out of scope.
9. Restore/time-travel protection must prevent rollback-prone application state from resurrecting previously consumed/revoked authority artifacts.
10. Mixed-version workers and long-lived processes must not execute with stale authority/config/runtime generations.
11. Provider-side-effect ambiguity always resolves conservatively to reconciliation/unknown until certified postcondition evidence proves otherwise.
12. Capability Fabric completeness requires ownership gates T3768–T3770, composed cross-fault gate T3795 and canonical/DB storage gate T3799.
12a. The workstream dependency graph must be acyclic, every dependency must resolve to a known workstream, every Phase 37 task is owned exactly once, and DONE/PARTIAL workstreams require evidence references.
13. Security-sensitive hashes have one canonical encoding only; alternate serialization cannot create a second valid identity.
14. Governed transaction assumptions require certified transactional storage, identity-safe collation semantics and explicit transaction ownership/nesting behavior.

## Status vocabulary

- OPEN
- PARTIAL
- DONE
- DEFERRED

## Closure claims

The ledger may emit only these high-level claims:

- `CAPABILITY_FABRIC_DIMENSION_OWNERSHIP`
- `CAPABILITY_FABRIC_NO_UNTRIAGED_P0_P1`
- `CAPABILITY_FABRIC_NO_AUTHORITY_WIDENING`
- `CAPABILITY_FABRIC_CROSS_FAULT_CLOSURE`
- `CAPABILITY_FABRIC_CANONICAL_DB_STORAGE`
- `CAPABILITY_FABRIC_COMPLETENESS`

A PASS claim requires linked evidence; no claim is inferred from task presence alone.


## RequestScopeContract

The runtime contract is `mad4b.request-scope-generation.v1`.

- A logical request binds one canonical context fingerprint covering blog/site identity, current user/capabilities, environment, Site Profile option identity, ChatGPT projection identity and loaded runtime package identity.
- Context drift inside the same logical request is denied with `mad4b_request_scope_context_drift`; a mutation cannot continue under a changed user/profile/projection generation.
- A new logical request may reset only declared request-local cache owners and only after quiescence proves no active transaction, scoped approval/subject override, execution callback or write-authority reconciliation.
- Site/environment/runtime-package identity changes are worker-lifetime boundaries and require recycle; they are never silently rebound in a stale process.
- MCP/REST hook isolation is resettable only before request-local callbacks have been removed. Once hook topology was changed, cross-request reuse requires worker recycle rather than reconstructing unknown third-party hook state.
- RequestScope receipts are evidence-only and non-authorizing. Cache reset can remove stale positive state; it cannot create grants, approvals, eligibility or Production/Breakglass authority.
