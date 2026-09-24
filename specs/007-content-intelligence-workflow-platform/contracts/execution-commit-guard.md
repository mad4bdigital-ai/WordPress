# Contract — Execution Commit Guard and Approval Invalidation

Contract: mad4b.execution-commit-guard.v1

## Problem

A valid plan/approval can become unsafe between planning and mutation because policy, target state, provider certification, kill switches or authority bindings change.

## Decision snapshot

An approved execution binds to:
- plan_sha256;
- target expected-state fingerprint/revision;
- policy snapshot fingerprint;
- grant revision/fingerprint;
- approval policy revision;
- approval decision revision;
- provider artifact/capability certification fingerprint;
- authority/subject binding revision;
- environment/site-profile revision;
- kill-switch revision;
- applicable rights/data-processing decision refs;
- release/candidate identity when relevant.

## Commit guard

Immediately before the irreversible/authoritative commit point, execution revalidates the critical dependency set.

Possible result:
- COMMIT_ALLOWED
- REPLAN_REQUIRED
- REAPPROVAL_REQUIRED
- RECERTIFICATION_REQUIRED
- TARGET_CHANGED
- KILL_SWITCHED
- DENIED

The guard is deterministic and auditable.

## Approval invalidation

Approval becomes invalid when any approval dependency marked MATERIAL changes.

Examples:
- plan body;
- target state;
- provider artifact/capability;
- risk classification;
- authority subject;
- Production/environment;
- policy that changes required approvers;
- rights/data-processing hard decision.

A non-material observability change does not invalidate approval.

## Point of no return

Every high-risk operation declares its commit point.
After the point of no return, changed policy may stop downstream work but cannot pretend the committed side effect did not occur; reconciliation/compensation is required.

## Race tests

- kill switch activates after approval;
- provider quarantined after plan;
- human edits target after approval;
- grant revoked immediately before mutation;
- rights record changes to prohibited;
- stale approval reused after candidate change.
