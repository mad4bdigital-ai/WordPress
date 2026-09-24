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
