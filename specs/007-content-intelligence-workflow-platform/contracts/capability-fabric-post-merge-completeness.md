# Contract — Post-merge Capability Fabric completeness

Contract: `mad4b.capability-fabric-post-merge-completeness.v1`

## Purpose

Turn the residual Capability Fabric review after PR #230 into a machine-checkable, non-authorizing closure program.

## Invariants

1. The closure ledger is descriptive/governance metadata. It never grants OAuth, site, provider, write, Developer, Breakglass, Host or Production authority.
2. Every Phase 37 task belongs to exactly one closure workstream in the machine-readable ledger.
3. Every workstream declares task IDs, existing quality families, dependencies, interim fail-closed/non-authorizing behavior, acceptance evidence strategy and current status.
4. Unknown workstream/task/status/quality identifiers fail Spec Kit validation.
5. OPEN/PARTIAL work may not be represented as complete merely because documentation exists.
6. Runtime/security/live acceptance must be closed by executable or external evidence appropriate to the task.
7. The Critical Kernel terminal gate remains `critical_kernel_vertical_slice_verified`; Phase 37 is a maturity overlay unless an explicit governed change later admits a dependency.
8. Production activation, Breakglass widening, generic shell, arbitrary PHP/`wp eval` and generic raw SQL remain out of scope.
9. Restore/time-travel protection must prevent rollback-prone application state from resurrecting previously consumed/revoked authority artifacts.
10. Mixed-version workers and long-lived processes must not execute with stale authority/config/runtime generations.
11. Provider-side-effect ambiguity always resolves conservatively to reconciliation/unknown until certified postcondition evidence proves otherwise.
12. Capability Fabric completeness requires ownership gates T3768–T3770 plus composed cross-fault gate T3795.

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
- `CAPABILITY_FABRIC_COMPLETENESS`

A PASS claim requires linked evidence; no claim is inferred from task presence alone.
