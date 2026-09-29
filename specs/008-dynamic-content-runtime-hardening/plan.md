# Implementation Plan

## Phase 0 — Repository integrity and characterization
Repair workflow hygiene; freeze rc.83 baseline; capture characterization fixtures; define canonicalization/domain separation and operation identity.

## Phase 1 — Minimal evidence foundation
Implement OperationContext, append-only event primitive, chained event hash, heartbeat/deadline and safe redaction before refactor.

## Phase 2 — Decomposition
Extract ModelDiscovery → BundleValidator → SnapshotManager → BundleExecutor → CompensationManager → AcceptanceManager → PublicationGuard. Every extraction commit remains semantic-no-op against characterization fixtures.

## Phase 3 — Telemetry privacy/security
Add access control, bounded retention, core aggregate metrics, tamper evidence and optional exporters.

## Phase 4 — Discovery scale
Add summary/expanded modes, digest cache, TTL/fingerprint invalidation, lazy expansion, budgets and mandatory live apply revalidation.

## Phase 5 — Provider bridge
Extend existing provider registry/contracts with versioned manifest metadata. No parallel authority registry.

## Phase 6 — TTL/lock
Deterministic short/standard/long tiers, refresh threshold, hard deadline and safe interruption boundaries.

## Phase 7 — Side effects and approval
Five-dimensional side-effect model; orthogonal impact level/flags; AI approval default-deny.

## Phase 8 — Recovery model/runtime
Define persistence, orphan classification, exact plan expiry/binding, inspect/plan/apply/readback/close and manual_required.

## Phase 9 — Simulation/diff
Add uncertainty-aware simulation and privacy-safe bounded semantic diff.

## Phase 10 — Concurrency/chaos
Adversarial races plus package-excluded test fault injection.

## Phase 11 — Migration/performance/soak
Fresh/upgrade/repeat/partial migration, retention/decommission, 50k discovery budget, 100-operation soak, 10-way concurrency.

## Phase 12 — UI/docs
Non-authoritative visualizers and extension/recovery/security runbooks.

## Phase 13 — Staging certification
Functional, crash, lock-loss, external-effect, stale-config, double-receipt, concurrency and soak canaries.

One PR is retained, but commits MUST be phase-cohesive. Refactor commits MUST NOT mix semantic feature changes.
