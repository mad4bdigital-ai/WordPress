# Contract — Offline Authorization Windows and Central Dependency Failure

Contract: mad4b.offline-authorization-window.v1

## Tension

Local autonomy during central outage conflicts with immediate revocation propagation.

The platform resolves this explicitly by risk-classed maximum offline authorization windows.

## OfflineWindowPolicy

For each operation/risk class:
- central dependency;
- cached evidence type;
- maximum age/TTL;
- reads allowed;
- writes allowed;
- Production allowed;
- Developer/Breakglass allowed;
- behavior after expiry.

Exact durations are deployment policy, not hardcoded by this contract.

## Rules

- expiry fails closed for the affected operation;
- no cached evidence creates new grants/certification;
- revocation refresh occurs immediately on reconnection;
- a shorter dependency TTL dominates a longer one when both are required;
- high-risk classes MAY require online central validation with zero offline window.

## Evidence

Execution records the age/version of cached central evidence used.

## Recovery

If action occurred during an allowed offline window and central state later shows revocation inside that window, reconciliation/incident policy determines containment; history is never rewritten.

## Deferred Host Runner jobs

A queued HostRunnerJob does not obtain perpetual authority merely because it was authorized when enqueued.

Before commit, runner revalidates:
- job/plan expiry;
- candidate/target identity;
- operation/executor certification;
- kill switches;
- approval validity;
- authority/grant dependencies;
- offline-window policy.

Risk classes:
- read-only diagnostics MAY execute during a bounded authority outage only when offline policy explicitly permits cached evidence;
- reversible host writes default to pause unless their offline window is explicit and all dependencies remain within TTL;
- Host Execution Breakglass, Recovery mutation and Production Host mutation require current online authority unless an emergency policy explicitly defines otherwise.

When cached/offline evidence is used, receipt records its age, policy and reason.
