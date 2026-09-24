# Contract — Execution Plane, Leases and Fencing

Contract: mad4b.execution-plane.v1

## Separation

Control Plane responsibilities:
- plans;
- authority/policy;
- aggregate state;
- evidence identity;
- certification;
- approvals;
- commit guards.

Execution Worker responsibilities:
- leased work execution;
- external provider calls;
- artifact computation;
- bounded callbacks/results.

They MAY run in the same deployment in v1, but APIs and durable contracts MUST preserve this logical boundary.

## Lease

A work lease contains:
- work_id;
- worker_id;
- lease_epoch;
- acquired_at;
- heartbeat_at;
- expires_at;
- expected aggregate revision.

## Fencing token

lease_epoch is a monotonically increasing fencing token.

Every authoritative write resulting from leased work MUST include its lease_epoch.
Repository rejects writes where:
provided_epoch < current_epoch.

A zombie worker with an expired older lease cannot commit results after a newer worker acquires ownership.

## Reclaim

Expired work is not blindly re-executed.
Reclaimer:
1. increments fencing epoch;
2. reads aggregate/checkpoint;
3. reconciles external provider execution if one may already exist;
4. resumes or blocks.

## Worker independence

Worker identity is not an authority grant.
A worker executes only the already-authorized bounded work item/plan.

## Heartbeats

Heartbeats extend liveness only.
They do not refresh approval, certification or policy dependencies.

## Exactly-once statement

The platform does not claim network exactly-once delivery.
It targets:
- at-least-once delivery;
- fenced/idempotent effect-once authoritative mutations;
- provider reconciliation where ambiguity exists.

## Migration

Worker transport may later move outside WordPress/PHP without changing ContentJob or capability semantics.
