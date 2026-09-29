# Implementation Plan

## Phase 0 — Freeze baseline
- Pin master `282f837814cb6e5bb50582027c31a42ea050858f`.
- Capture rc.83 behavior as compatibility fixtures.
- No public ability rename during the refactor.

## Phase 1 — Internal decomposition
Create internal services and route the existing adapter through them without changing schemas:
- ModelDiscovery
- BundleValidator
- BundleExecutor
- SnapshotManager
- CompensationManager
- AcceptanceManager
- PublicationGuard

Acceptance: byte-equivalent normalized contract fixtures for existing abilities where nondeterministic timestamps/IDs are excluded.

## Phase 2 — Operation telemetry
Add OperationContext, structured events, RuntimeMetrics, and operation correlation. Provide bounded read-only status/trace abilities and admin trace view.

## Phase 3 — Discovery scaling
Add summary/full modes, model digest caching, explicit budgets, lazy term/meta detail, invalidation events, and synthetic large-site tests.

## Phase 4 — Provider SDK
Add ProviderRegistry and provider manifest contract. Implement compatibility adapters for existing generic source-fidelity/SEO/frontend hooks before plugin-specific adapters.

## Phase 5 — TTL policy
Add policy service, min/max bounds, refresh threshold, hard lifetime, receipt fields, and expiry metrics.

## Phase 6 — Durable journal and recovery
Persist monotonic checkpoints; classify interrupted operations; add inspect/plan/apply/readback/close recovery lifecycle with exact state bindings.

## Phase 7 — Semantic diff and simulation
Add normalized semantic diff and extend planner with plan/simulate/explain/diff read-only modes.

## Phase 8 — Concurrency and chaos
Implement adversarial concurrency tests and test-only fault points after every mutation boundary. Verify compensation ownership and receipt single-use.

## Phase 9 — Side-effect coverage + approval classes
Add provider side-effect declarations and impact policy classes. Fail closed when rollback coverage is incomplete.

## Phase 10 — Pipeline visualizer and SDK docs
Add read-only graph/timeline UI and extension documentation/examples.

## Phase 11 — Staging canary
Build/certify package, update Staging, run all eight scenarios, collect exact evidence, and publish certification receipt. Production remains out of scope.

## Delivery strategy
One feature branch and one PR are permitted, but commits should remain phase-cohesive. CI must gate each phase contract independently to avoid a monolithic un-debuggable release.
