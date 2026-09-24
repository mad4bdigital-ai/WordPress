# Critical Kernel — Feature 007

## Purpose

This document freezes implementation focus after extensive architecture coverage.

Feature 007 contains many maturity contracts, but implementation priority is intentionally narrower.

## Kernel scope

1. Active external repository governance on `master`; committed policy alone is not sufficient.
2. Exact baseline synchronization and execution-ledger reconciliation.
3. External release provenance/root trust for the current master descendant.
4. Minimal out-of-band Recovery Plane plus protected backup-root readiness.
5. Multi-Authority live authentication/subject path.
6. Deterministic Policy Resolution Engine and truthful operating modes.
7. Gate DAG parsing, liveness, blocker-set truthfulness and BootstrapTransition governance.
8. Execution Commit Guard and approval invalidation.
9. Authoritative aggregate/event/artifact consistency model.
10. Optimistic revision, idempotency, inbox/outbox, lease and fencing failure model.
11. Exact installed Bit Flows 1.29.0 certification, semantic traits and privileged-side-channel decision.
12. Existing-site bootstrap and normalized content/SEO/media/link inventory.
13. Many-to-many Intent Registry and collision/cannibalization decision.
14. ContentJob domain service with immutable Artifact Registry/Store/lineage.
15. Knowledge Dispatcher, bounded ContextPack and immutable WriterProfile version binding.
16. Research provider platform and competitive-intelligence evidence artifacts.
17. Blueprint, ArticleDraft, FactLedger and Editorial/SEO/Final QA.
18. Governed WordPress Draft mutation with exact PublishManifest/readback/rollback.
19. Semantic origin/public publication verification.
20. Operator/Doctor/reconciliation + live recovery review + formal critical-state proof.

## Explicitly not required before first vertical-slice proof

These remain designed but normally deferred:
- generalized provider resolver beyond real provider #2;
- experimentation platform;
- financial chargeback UI;
- advanced semantic/vector retrieval;
- full portability automation;
- broad multi-site rollout/autopromotion.

## Proof target

One exact ETG Staging evidence chain:

repository governance
→ current baseline + reconciled execution ledger
→ latest-master root provenance
→ protected backup/recovery readiness
→ live authority + policy/liveness
→ exact Bit Flows 1.29.0 certification + side-channel proof
→ site bootstrap
→ intent ownership
→ one durable ContentJob
→ immutable artifacts/store/lineage
→ bounded context + exact writer profile
→ normalized research + competitive evidence
→ blueprint/draft/FactLedger/QA
→ governed WordPress draft
→ origin/public semantic verification
→ Operator/Doctor/recovery review
→ formal critical-state proof
→ `CRITICAL_KERNEL_VERTICAL_SLICE_VERIFIED`.

Disposable CI, a previous master SHA, a different provider package or a previous ETG candidate cannot satisfy a live dependency by inference.

## Freeze rule

No new Critical Kernel abstraction is admitted without satisfying architecture-freeze.md.


## Architecture Freeze

Critical Kernel scope is frozen under `contracts/architecture-freeze.md`.

A new CORE abstraction is not admitted merely because it is useful or elegant. It requires qualifying runtime, security, irreversible-model, second-provider, Production-recovery, or applicable compliance evidence. Otherwise it remains an ADR, backlog item, or optional extension.

## Minimal governed recovery tooling admission

The existing minimal out-of-band Recovery Plane now explicitly depends on a narrowly admitted Tool Execution subset:

- semantic recovery/diagnostic operation registry;
- canonical read-only CLI diagnostics;
- certified structured executor mapping;
- minimal Recovery Runner that does not require healthy WordPress/plugin boot;
- package/runtime health read;
- attested known-good package restore;
- Host/WordPress/Production authority separation.

This does **not** admit the full Host Connector or broad host automation into the Critical Kernel. General host writes, provider CLI automation and cross-provider executor expansion remain Phase 11 maturity work.

Critical proof:
```text
release provenance root
→ recovery tooling available
→ recovery plane available
→ deliberate WordPress/plugin failure
→ read package/runtime health
→ restore attested known-good package
→ verify control-plane recovery
→ prove no content publication / generic shell authority
```


## Unified closure authority

`implementation-closure.md` and `implementation-closure.json` are the normative remaining-work index. They may classify maturity work as non-blocking, but they may not remove any dependency from `gate-graph.json` without an ArchitectureAdmissionDecision and updated evidence mapping.

The repository-governance bootstrap used to land the policy is not the terminal governance proof. The terminal condition is independently read back active ruleset enforcement on `refs/heads/master`.

Protected backup preparation is a live precondition, not implicit deployment authority. It must be verified before the exact trusted candidate is installed on ETG Staging.
