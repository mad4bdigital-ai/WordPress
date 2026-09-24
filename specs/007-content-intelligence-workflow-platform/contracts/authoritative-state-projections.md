# Contract — Authoritative State, Events and Projections

Contract: mad4b.authoritative-state.v1

## Decision

Feature 007 v1 is NOT an event-sourced system.

Authoritative current state lives in explicitly designated aggregate/state records.
Append-only events are audit/history.
Artifacts are immutable domain outputs/evidence.
Read models/search indexes are projections and are rebuildable.

## Aggregate authority

Examples:
- ContentJob aggregate record = authoritative current lifecycle state/stage/revision.
- ProviderCertification aggregate = authoritative current certification state, subject to signed evidence/trust rules.
- AuthorityDescriptor aggregate = authoritative configured policy state.
- ReleaseRingRecord = authoritative current promotion state.

## Events

Events MUST:
- describe a completed or attempted transition;
- reference aggregate ID/revision;
- be append-only;
- never independently override current state.

A current aggregate update and its mandatory event append MUST be atomic when stored in the same transactional boundary.

## Artifacts

Artifacts are authoritative for the immutable content/evidence bytes they identify, not for mutable aggregate lifecycle state.

Example:
ArticleDraft v3 is immutable truth for that draft version.
It does not decide whether ContentJob is RUNNING, BLOCKED or COMPLETED.

## Projections

Indexes, dashboards, counters, link graphs, retrieval indexes and operational read models are projections.
They MAY lag.
They MUST be rebuildable from authoritative aggregates/artifacts/events as declared by their contract.

Projection staleness never widens authority.

## Consistency checker

A read-only consistency checker detects:
- aggregate revision not represented in required event chain;
- event references unknown aggregate revision;
- aggregate current-artifact ref missing/corrupt;
- projection ahead of authoritative state;
- impossible lifecycle state;
- orphan required edges.

Repair uses a separate RepairPlan.

## Recovery

If aggregate and event disagree after partial failure:
- transaction evidence determines whether transition committed;
- no heuristic "latest timestamp wins";
- uncertain high-risk state becomes BLOCKED_FOR_RECONCILIATION.
