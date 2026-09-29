# Research Notes

## Existing strengths inherited from rc.83
- Runtime model discovery and deterministic planning.
- Exact bundle/pipeline/state hash binding.
- Stable operation key for create idempotency.
- Mutation locks with refresh.
- Registry-driven validation/repair/accept pipeline.
- Exact readback.
- Local compensation with CAS-safe drift protection.
- Durable managed-content marker.
- Acceptance-bound status-only publication.
- One-time acceptance receipt.
- Single governed mutation path.
- Transport-scope lifecycle repair without generic write scope.

## Primary residual risks
1. Maintainability concentration in the large adapter.
2. Weak runtime observability across the complete lifecycle.
3. Lack of durable crash checkpoints when requests terminate.
4. Large-site discovery cost.
5. Provider-specific validator and side-effect variability.
6. TTL tuning for receipts and locks.
7. Race conditions that ordinary functional tests may miss.
8. False atomicity claims when third-party providers emit external effects.

## Decision
The next release should be a hardening/operations release, not a wider mutation-surface release. New write abilities are only justified for exact recovery lifecycle operations and must reuse governance, approval, audit, CAS, and reversible evidence.
