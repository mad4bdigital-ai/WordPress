# MAD4B FAORE Constitution

**Version**: 1.0.0 | **Ratified**: 2026-11-05 | **Scope**: architecture/faore and explicitly linked FAORE implementations.

## Core Principles

1. **Federated authority**: Central plans and distributes; the company's Local Control Plane alone admits local actions. Never reroute a denied request via another provider, key, SSH endpoint or executor.
2. **Reuse before replacement**: Evolve Company Portfolio Manager, existing Control Planes, Broker 305/306/312/313, Native Executor, Source Release Manager and Coolify rather than create parallel systems.
3. **Exact identity, scope and idempotency**: Bind company, environment, UUID, operation, delivery and capability; enforce tenant boundaries and prevent replay before effects.
4. **Local autonomy**: Accepted local jobs continue without central connectivity; durable inbox/outbox, recovery and a local journal are mandatory.
5. **Evidence and data minimization**: Distinguish Observed/Verified/Unverified/Stale. Aggregate operational metadata and receipt references, never secrets or company customer data by default.
6. **Incremental, reversible reconciliation**: Use dependency graphs, source generations, compatibility and ownership overlays; preserve per-company configuration and rollback.
7. **Operational security without gratuitous blockers**: Authentication, authorization, tenant and environment isolation are mandatory. Optional certificates, license advisory and owner attestations must not introduce unrelated runtime blockers.
8. **Testing and observability**: Contract, tenant-isolation, duplicate delivery, offline recovery, readback and rollback scenarios precede fleet rollout.

## Delivery workflow
P0 Shared Kernel and FCP; P1 Execution Admission; P2 Source Release and Reconcile; P3 Wayfatours E2E; P4 Fleet operations. Each phase needs readback and explicit evidence before expanding scope.

## Governance
Amendments need documented rationale, compatibility impact and updated tests. The pre-existing root .specify constitution for ETG Dynamic Filter SEO Bridge is separate and must not be changed.

**Basis**: User-provided 12-section FAORE architecture brief, 2026-11-05.
