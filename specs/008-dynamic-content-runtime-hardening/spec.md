# Specification — Dynamic Content Runtime Hardening & Operations Platform

## 1. Problem

PR #157 established a governed, runtime-dynamic WordPress content orchestration engine. The next risk is no longer missing core capability; it is operational complexity. The platform now depends on exact hashes, acceptance receipts, locks, bounded loops, provider hooks, compensation, transport/governance separation, and runtime discovery. These controls must become observable, recoverable, measurable, maintainable, and extensible without weakening the safety model.

## 2. Goals

### G1 — Maintainability without semantic drift
Decompose the large dynamic-content adapter into cohesive services while preserving public ability names, request schemas, response contracts, hash semantics, mutation ordering, and fail-closed behavior.

### G2 — Complete operation traceability
Every governed orchestration operation must expose a stable operation identity and stage timeline from discovery/planning through approval, mutation, readback, convergence, acceptance, publication, compensation, undo, or recovery escalation.

### G3 — Quantifiable runtime quality
Record bounded metrics for discovery, planning, mutation, convergence, repair, acceptance, publication, compensation, lock contention, stale-state rejection, and recovery.

### G4 — Scalable discovery
Large taxonomies/meta populations must not require unbounded enumeration. Discovery must support bounded summary/full modes, cacheable model digests, pagination, explicit budgets, and lazy expansion.

### G5 — Provider SDK
Third-party integrations must participate through an explicit provider contract supporting capability discovery, validation, findings, repair planning, reversible repair, verification, and side-effect declarations.

### G6 — Adaptive TTL policy
Acceptance and mutation-lock TTLs must be policy-derived, bounded, observable, refresh-safe, and protected from zombie operations.

### G7 — Crash recovery
Operations must durably journal checkpoints. A terminated PHP request/session must leave enough evidence to distinguish never-started, partially-mutated, accepted, published, compensated, and recovery-required states.

### G8 — Recovery reconciliation
Recovery must be a first-class governed lifecycle: inspect → plan → approve if required → apply → readback → close.

### G9 — Concurrency resilience
The platform must reject or safely resolve duplicate creates, stale updates, concurrent settings mutation, concurrent receipt use, term deletion/drift, post status drift, and concurrent human edits.

### G10 — Fault injection
Test-only fault boundaries must exercise compensation and recovery behavior after each meaningful write boundary without enabling production fault injection.

### G11 — Provider side-effect isolation
External/provider side effects must be explicitly classified as reversible, compensatable, irreversible, or external. Generic content rollback must never claim coverage it does not possess.

### G12 — Approval classes
Operations must be classified using policy-defined impact classes such as low, medium, high, publication, schema_change, external_side_effect, and irreversible. AI approval is allowed only where policy explicitly permits it.

### G13 — Dry-run and explainability
The planner must support plan/simulate/explain/diff modes that do not mutate state or mint mutation authority.

### G14 — Semantic diff
Before mutation and on recovery/acceptance mismatch, the system must produce a bounded human-readable semantic diff in addition to hashes.

### G15 — Pipeline visualizer
Administrators must be able to inspect registered stages, dependencies, conditions, mandatory stages, timing, status, and latest findings without changing execution authority.

### G16 — Extension SDK/runbook
Provide documented contracts and examples for validator, repair, condition, side-effect, recovery, and provider extensions.

### G17 — Staging certification
A governed Staging canary must certify create draft, update draft, acceptance+publish, stale-receipt rejection/revalidation, rollback/undo, concurrent edit, missing-term dependency, and provider-validation failure.

## 3. Non-goals

- No generic shell or arbitrary PHP execution.
- No wildcard ability/grant expansion.
- No new generic Production write scope.
- No raw SQL.
- No Breakglass enablement.
- No auto-generation of trusted callbacks from persisted settings.
- No silent rollback over concurrent human changes.
- No production chaos injection.
- No claim of provider side-effect rollback unless a provider adapter proves it.

## 4. Functional requirements

### FR-001 Component decomposition
The dynamic content runtime SHALL expose internal services for ModelDiscovery, BundleValidator, BundleExecutor, SnapshotManager, CompensationManager, AcceptanceManager, PublicationGuard, OperationJournal, RuntimeMetrics, SemanticDiff, RecoveryReconciler, ProviderRegistry, and TtlPolicy. Public contracts remain backward compatible.

### FR-002 Operation identity
Every plan/apply/readback/recovery path SHALL carry `operation_id` or derive a stable operation binding. Logs and receipts SHALL correlate by this identity.

### FR-003 Structured runtime events
Events SHALL include: operation_id, post_id when known, post_type, mode, stage_id, iteration, plan_sha256, bundle_sha256, state_sha256_before/after, pipeline_settings_sha256, approval reference, receipt_sha256, lock status, elapsed_ms, outcome, recovery_required, and timestamp. Sensitive content SHALL NOT be emitted by default.

### FR-004 Metrics
Metrics SHALL cover count, latency, convergence iterations, repair attempts, compensation, failed compensation, stale rejection, lock contention, acceptance expiry, publish drift rejection, provider failures, and recovery closure.

### FR-005 Discovery budgets
Discovery SHALL support `detail=summary|full`, explicit limits, lazy term/meta expansion, cache key/model digest, and invalidation hooks. Full discovery SHALL remain bounded.

### FR-006 Provider SDK
A provider SHALL declare support, preconditions, capabilities, side effects, validation hooks, repair planner, repair executor, verifier, reversible evidence, and version/certification metadata.

### FR-007 TTL policy
TTL decisions SHALL be bounded by min/max policy and recorded in receipts. Mutation locks SHALL refresh before expiry and SHALL have a hard maximum lifetime.

### FR-008 Durable journal
Checkpoint records SHALL be append-only or monotonic and SHALL not move backward. Required states: planned, approval_bound, lock_acquired, mutation_started, post_written, meta_written, taxonomy_written, media_written, validation_started, accepted, publish_started, published_verified, receipt_consumed, compensated, completed, recovery_required.

### FR-009 Recovery abilities
Read-only inspection SHALL be separate from mutation. Recovery apply SHALL remain governed and exact-state-bound.

### FR-010 Concurrency invariants
The implementation SHALL test and preserve exact CAS semantics around state, pipeline settings, operation key, acceptance receipt, mutation lock, and compensation ownership.

### FR-011 Fault injection
Fault points SHALL be available only when an explicit test constant/environment gate is enabled. Production runtime SHALL fail closed if a fault-injection request is attempted.

### FR-012 Side-effect scope
Provider side effects SHALL be represented in plan and receipt with coverage: none, read_only, reversible, compensatable, irreversible, external.

### FR-013 Approval policy
Impact classification SHALL be derived from deterministic facts and policy; AI approval SHALL never imply transport scope, NHI grant, or Production authority.

### FR-014 Simulation
Simulation SHALL resolve dependencies and calculate expected semantic changes without performing writes or consuming approvals.

### FR-015 Semantic diff
Diffs SHALL be bounded, redact sensitive meta by policy, distinguish absent from empty, support multi-value meta, taxonomy term IDs/slugs, featured media, and post fields.

### FR-016 Visualizer
Admin UI SHALL render the pipeline graph and latest execution diagnostics while keeping configuration writes behind existing governed/settings controls.

### FR-017 Canary certification
The Staging certification SHALL emit machine-readable evidence for each scenario and SHALL fail if exact readback or recovery verification is missing.

## 5. Safety invariants

1. Dynamic model discovery must never imply dynamic authority expansion.
2. Persisted settings never contain executable callbacks.
3. Structural validation and terminal acceptance remain mandatory.
4. Publication remains acceptance-bound and status-only for managed content.
5. Compensation is CAS-safe and cannot overwrite drifted human state.
6. Recovery-required is explicit; the system never reports rollback success without readback proof.
7. Provider external/irreversible side effects prevent false atomicity claims.
8. Test-only fault injection is impossible in normal runtime.
9. Metrics/events must not leak protected meta values or secrets.
10. Staging certification does not authorize Production mutation.

## 6. Success criteria

- Existing rc.83 contract tests remain green.
- New feature contract, journal, recovery, metrics, SDK, semantic-diff, concurrency, fault-injection, and canary tests are green.
- Large-site discovery test proves bounded behavior for at least 50k synthetic terms without full enumeration.
- Two concurrent uses of one acceptance receipt result in at most one successful consume.
- Compensation drift test proves no overwrite of a concurrent human edit.
- Recovery journal can deterministically classify interrupted operations at each injected boundary.
- Provider with irreversible side effect blocks claims of full rollback.
- Visualizer and dry-run are read-only.
- Staging canary evidence covers all eight scenarios.
