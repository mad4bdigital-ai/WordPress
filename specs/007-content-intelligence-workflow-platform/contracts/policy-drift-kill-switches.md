# Contract — Policy Drift, Feature Flags and Safe Shutdown

Contract: mad4b.policy-drift.v1

## Desired state

Authority policies, provider certifications, grants, site profiles, quality thresholds and release-ring state have versioned desired-state fingerprints.

Runtime status distinguishes:
- desired;
- observed;
- drifted;
- stale evidence.

## Drift

Security/authority drift never auto-reconciles by widening permissions.

Safe auto-reconciliation may narrow or restore exact previously approved state when contract explicitly permits it.

## Feature flags

Feature flags:
- default off for new high-risk mutation families;
- are environment/site scoped;
- are versioned/audited;
- do not replace grant/certification/approval.

## Kill switches

Kill switches are evaluated before ordinary enable flags for affected high-risk operations.

## Config rollout

Policy/config changes use:
plan → diff → approval if required → apply → readback → evidence.

## Unknown state

If runtime cannot determine whether a high-risk feature is enabled/disabled or which policy is active, affected writes fail closed.

## Tool execution kill switches

Independent desired-state kill switches exist for:
- Host Write;
- Host Execution;
- Host Runner pickup;
- provider API channel;
- provider CLI channel;
- bounded SSH;
- Recovery Runner mutation.

A Host Runner kill switch may stop new leasing while allowing safe terminal readback/receipt persistence for already executed work.

Recovery mutation kill switch is separate from recovery read health.

Provider-channel drift or executor fingerprint mismatch can quarantine only the affected channel/operations without disabling unrelated WordPress reads.

No kill switch may silently route work to a broader executor.
