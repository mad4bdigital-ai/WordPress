# Tasks

## P0 — Spec and compatibility
- [x] T001 Create Feature 008 Spec Kit package.
- [ ] T002 Add rc.83 normalized compatibility fixtures.
- [ ] T003 Add Feature 008 CI contract.

## P1 — Decomposition
- [ ] T010 Add ModelDiscovery service.
- [ ] T011 Add BundleValidator service.
- [ ] T012 Add BundleExecutor service.
- [ ] T013 Add SnapshotManager service.
- [ ] T014 Add CompensationManager service.
- [ ] T015 Add AcceptanceManager service.
- [ ] T016 Add PublicationGuard service.
- [ ] T017 Route existing dynamic adapter through services.
- [ ] T018 Prove no public schema/ability drift.

## P2 — Observability
- [ ] T020 Add OperationContext and stable operation correlation.
- [ ] T021 Add structured event emitter with redaction.
- [ ] T022 Add RuntimeMetrics counters/histograms.
- [ ] T023 Add read-only operation trace/status contract.
- [ ] T024 Add admin runtime trace view.

## P3 — Discovery scale
- [ ] T030 Add summary/full discovery mode.
- [ ] T031 Add model digest/cache.
- [ ] T032 Add cache invalidation hooks.
- [ ] T033 Add lazy term/meta expansion.
- [ ] T034 Add per-section budgets/time limits.
- [ ] T035 Add 50k-term bounded synthetic test.

## P4 — Provider SDK
- [ ] T040 Add provider manifest schema.
- [ ] T041 Add ProviderRegistry.
- [ ] T042 Add validation/repair/verifier interfaces.
- [ ] T043 Add reversible-evidence interface.
- [ ] T044 Add side-effect declaration.
- [ ] T045 Bridge existing provider-neutral validators.

## P5 — TTL policy
- [ ] T050 Add adaptive acceptance TTL.
- [ ] T051 Add adaptive mutation-lock TTL.
- [ ] T052 Add refresh threshold.
- [ ] T053 Add hard operation lifetime.
- [ ] T054 Add TTL metrics and receipt evidence.

## P6 — Journal/recovery
- [ ] T060 Add durable monotonic operation journal.
- [ ] T061 Add checkpoint writes at mutation boundaries.
- [ ] T062 Add recovery classification.
- [ ] T063 Add recovery-inspect.
- [ ] T064 Add recovery-plan.
- [ ] T065 Add governed recovery-apply.
- [ ] T066 Add recovery-readback/close.
- [ ] T067 Add abandoned/zombie operation detection.

## P7 — Dry-run/diff
- [ ] T070 Add planner mode plan/simulate/explain/diff.
- [ ] T071 Add SemanticDiff engine.
- [ ] T072 Add protected-meta redaction.
- [ ] T073 Add multi-value/absent-vs-empty diff rules.
- [ ] T074 Add expected side-effect/rollback coverage to simulation.

## P8 — Concurrency/chaos
- [ ] T080 Same operation-key concurrent create test.
- [ ] T081 Concurrent human edit during compensation test.
- [ ] T082 Pipeline setting drift test.
- [ ] T083 Double receipt-consume test.
- [ ] T084 Lock expiry/refresh race test.
- [ ] T085 Missing/deleted term between plan/apply test.
- [ ] T086 Manual publish/status drift test.
- [ ] T087 Add test-only fault injection registry.
- [ ] T088 Fault after each mutation boundary.
- [ ] T089 Assert production fault injection unavailable.

## P9 — Policy/side effects
- [ ] T090 Add impact classes low/medium/high/publication/schema_change/external_side_effect/irreversible.
- [ ] T091 Add AI approval allowlist by class/environment.
- [ ] T092 Prevent approval from widening transport/NHI/environment authority.
- [ ] T093 Fail closed on unowned irreversible side effects.

## P10 — Visualizer/docs
- [ ] T100 Add pipeline graph model.
- [ ] T101 Add read-only visualizer UI.
- [ ] T102 Show conditions/dependencies/mandatory/timing/result.
- [ ] T103 Add provider SDK guide.
- [ ] T104 Add validator/repair/condition examples.
- [ ] T105 Add recovery and operational runbook.

## P11 — Staging certification
- [ ] T110 Create draft canary.
- [ ] T111 Update draft canary.
- [ ] T112 Acceptance + publish canary.
- [ ] T113 Stale receipt reject → revalidate → publish canary.
- [ ] T114 Rollback/undo canary.
- [ ] T115 Concurrent human edit canary.
- [ ] T116 Missing-term dependency canary.
- [ ] T117 Provider-validation failure canary.
- [ ] T118 Emit machine-readable certification evidence.
- [ ] T119 Verify Full Staging Authority remains exact and Production remains unauthorized.
