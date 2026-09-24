# Contract — Operator Control, Doctor and Dead-Letter Operations

Contract: mad4b.operator-control.v1

## Purpose

Provide a human-operable control surface for long-running jobs, approvals, provider failures, drift, repair and replay without granting unrestricted mutation.

## Operator Console

Minimum read views:
- jobs by state/stage/site/tenant;
- blockers/reason codes;
- current artifact/gate status;
- provider/certification health;
- pending approvals;
- queue/lease/retry state;
- publication verification;
- policy/drift status;
- incident/kill-switch state;
- cost/budget status.

## Human actions

Governed actions MAY include:
- approve/deny;
- retry from checkpoint;
- cancel;
- pause/resume;
- re-run quality gate;
- accept/reject repair plan;
- requeue dead-letter item;
- quarantine provider/capability;
- activate/deactivate bounded kill switch;
- bulk action on explicitly selected IDs.

Every action produces plan/diff/evidence and obeys normal authority.

## Doctor

Doctor is read-only by default.

It diagnoses:
- stuck/expired leases;
- orphan artifacts;
- broken lineage;
- stale gates;
- missing blobs;
- provider drift;
- mismatched site profile;
- authority/certification drift;
- queue saturation;
- publication mismatch;
- dead-letter growth.

Doctor emits:
- finding ID;
- severity;
- evidence refs;
- probable cause;
- affected objects;
- suggested RepairPlan;
- mutation_performed=false.

## RepairPlan

A RepairPlan contains exact targets, expected state, bounded actions, reversibility and authority requirements.
Doctor never executes it implicitly.

## Dead-letter queue

Poison/repeatedly failing work moves to DLQ after configured policy.

DLQ item records:
- original work/event ID;
- job/provider/site;
- attempts;
- last error class;
- request/input hash;
- checkpoint;
- provider execution ref;
- first/last failure times;
- replay safety classification;
- evidence.

## Replay

DLQ replay:
- uses a new execution attempt ID;
- preserves original item;
- enforces idempotency;
- requires current policy/provider eligibility;
- cannot replay irreversible unknown-state work without readback/reconciliation.

## Bulk safety

Bulk actions require bounded selection/count, preview and blast-radius budget.

## Tool/runner Doctor coverage

Doctor additionally diagnoses:
- executor missing/uncertified;
- CLI binary/version drift;
- runner unavailable;
- stale runner heartbeat;
- stuck/fenced lease;
- queue age/backlog;
- dead-lettered host jobs;
- target-root mismatch;
- path-permission drift;
- provider API/CLI auth readiness;
- output-limit/timeouts;
- recovery-runner readiness;
- side-channel executor discovered outside policy.

Suggested RepairPlan references semantic operation IDs. Doctor never emits or executes arbitrary shell text.
