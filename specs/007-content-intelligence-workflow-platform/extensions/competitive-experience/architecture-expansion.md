# CE01 Architecture-Derived Resilience Expansion

Contract: mad4b.adaptive-capability-fabric-v2-resilience-expansion.v1.
Provenance: design derivation from the current CE01 backlog and MAD4B operating
boundaries. It is not competitor evidence, not a user-source quotation, not
runtime measurement and not authority. All six additions remain SPEC_BACKLOG_ONLY.

## ARCH-01 Release rings and fleet promotion

Single-site acceptance is insufficient for long-lived operations across Staging,
Multisite or a managed fleet. Promotion needs exact generation cohorts, site-local
authority, bounded canary rings, partial-failure accounting, fencing and per-site
receipts. Promotion never copies grants or Production authority between sites.

## ARCH-02 Automation SLOs and backpressure

A self-healing loop can become the outage if repair retries flap or evidence goes
stale. Adaptive operations therefore need explicit eligible-workload SLOs, error
budgets, retry budgets, cooldowns, queue isolation, circuit breaking and a kill
switch that halts automation without disabling safe reads or governed manual work.

## ARCH-03 Supply-chain provenance and trust

Runtime discovery proves observed behavior, not package origin. Provider packages,
policy packs and interpreter inputs need source-channel provenance, immutable
hashes, signer/revocation state, dependency inventory and downgrade protection.
A valid signature proves provenance only; it never creates execution authority.

## ARCH-04 Registry and policy schema migration

Long-lived signed registries will outlive individual schema versions and workers.
The system needs versioned migration DAGs, dry-run diffs, mixed-generation
compatibility, reversible snapshots and explicit preservation of unknown fields.
Migration cannot silently lower risk, widen authority or reinterpret old receipts.

## ARCH-05 Adversarial compatibility fuzzing

Structural and happy-path behavioral probes are not enough for unknown future
plugin changes. Disposable fixtures should exercise schema boundaries, malformed
responses, lying read annotations, hidden writes, concurrency, cancellation and
provider faults. Fuzzing is isolated and never targets Production or paid effects.

## ARCH-06 Restore and disaster-recovery convergence

A database/files restore can rewind receipts, registry generations and local state
while external effects remain current. Restore acceptance needs an explicit epoch,
stale-authority invalidation, current artifact/provider rediscovery, candidate and
grant rebinding, external reconciliation and exact readback before writes resume.
