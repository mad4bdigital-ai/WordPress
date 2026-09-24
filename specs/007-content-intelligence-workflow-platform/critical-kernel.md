# Critical Kernel — Feature 007

## Purpose

This document freezes implementation focus after extensive architecture coverage.

Feature 007 contains many maturity contracts, but implementation priority is intentionally narrower.

## Kernel scope

1. Baseline synchronization with current rc.59 integration line.
2. External release provenance/root trust.
3. Minimal out-of-band Recovery Plane.
4. Multi-Authority live authentication/subject path.
5. Deterministic policy resolution.
6. Execution Commit Guard and approval invalidation.
7. Authoritative aggregate state model.
8. Optimistic revision + idempotency.
9. Lease + fencing token.
10. Inbox/outbox and external execution reconciliation.
11. Exact Bit Flows capability certification and semantic traits.
12. Existing-site bootstrap and content inventory.
13. Intent relationship check.
14. ContentJob + artifacts + Context/Research/Blueprint/Draft/QA.
15. Governed WordPress Draft mutation.
16. Semantic origin/public publication verification.
17. Full evidence/recovery path.

## Explicitly not required before first vertical-slice proof

These remain designed but normally deferred:
- generalized provider resolver beyond real provider #2;
- experimentation platform;
- financial chargeback UI;
- advanced semantic/vector retrieval;
- full portability automation;
- broad multi-site rollout/autopromotion.

## Proof target

One exact ETG Staging path:
current baseline
→ root provenance
→ live authority
→ exact Bit Flows certification
→ site bootstrap
→ one content job
→ context/research
→ blueprint/draft/QA
→ governed WordPress draft
→ origin/public semantic verification
→ recovery/evidence review.

## Freeze rule

No new Critical Kernel abstraction is admitted without satisfying architecture-freeze.md.


## Architecture Freeze

Critical Kernel scope is frozen under `contracts/architecture-freeze.md`.

A new CORE abstraction is not admitted merely because it is useful or elegant. It requires qualifying runtime, security, irreversible-model, second-provider, Production-recovery, or applicable compliance evidence. Otherwise it remains an ADR, backlog item, or optional extension.
