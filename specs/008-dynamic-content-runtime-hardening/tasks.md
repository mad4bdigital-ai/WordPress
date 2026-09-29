# Tasks

## P0 Repository/spec integrity
- [x] T001 Create Feature 008.
- [x] T002 Repair exact-head checkout/concurrency/cancel workflow hygiene.
- [ ] T003 Add rc.83 normalized characterization fixtures.
- [ ] T004 Add canonicalization/domain-separation tests.
- [ ] T005 Add identity-binding tests.
- [ ] T006 Keep repository Spec Consistency green.

## P1 Evidence foundation
- [ ] T010 OperationContext.
- [ ] T011 Separate operation_key / operation_id / operation_binding_sha256.
- [ ] T012 Append-only journal primitive.
- [ ] T013 Chained event hash / journal_head_sha256.
- [ ] T014 Heartbeat/deadline metadata.
- [ ] T015 Safe redaction layer.
- [ ] T016 Bounded trace read model.

## P2 Decomposition
- [ ] T020 ModelDiscovery.
- [ ] T021 BundleValidator.
- [ ] T022 SnapshotManager.
- [ ] T023 BundleExecutor.
- [ ] T024 CompensationManager.
- [ ] T025 AcceptanceManager.
- [ ] T026 PublicationGuard.
- [ ] T027 Route adapter through services.
- [ ] T028 Old-field projection unchanged.
- [ ] T029 No public ability/schema removals.

## P3 Telemetry/privacy/metrics
- [ ] T030 Event schema v1.
- [ ] T031 Unregistered-meta default redaction.
- [ ] T032 Trace access control.
- [ ] T033 Retention/compaction policy.
- [ ] T034 Bounded core metrics.
- [ ] T035 Optional exporter hook.
- [ ] T036 Tamper-evidence tests.
- [ ] T037 No-secret/no-PII fixtures.

## P4 Discovery scale
- [ ] T040 summary/expanded modes.
- [ ] T041 complete/truncated_sections/applied_limits.
- [ ] T042 Model digest cache.
- [ ] T043 Cache TTL + schema fingerprint.
- [ ] T044 Lazy term/meta expansion.
- [ ] T045 Apply-time live revalidation.
- [ ] T046 Query/time/memory budgets.
- [ ] T047 50k synthetic term test.

## P5 Provider bridge
- [ ] T050 Provider manifest v1.
- [ ] T051 Bridge existing trusted registry.
- [ ] T052 Manifest never authorizes.
- [ ] T053 Validation/repair/verifier metadata.
- [ ] T054 Reversible evidence contract.
- [ ] T055 Soft vs enforceable deadline semantics.

## P6 TTL/locks
- [ ] T060 short/standard/long tiers.
- [ ] T061 Deterministic mapping.
- [ ] T062 Refresh threshold.
- [ ] T063 Hard deadline.
- [ ] T064 Safe interruption boundaries.
- [ ] T065 Expiry/refresh metrics.

## P7 Effects/approval
- [ ] T070 Side-effect five dimensions.
- [ ] T071 impact_level.
- [ ] T072 additive impact_flags.
- [ ] T073 AI approval default deny.
- [ ] T074 Exact AI policy/environment/effect binding.
- [ ] T075 No authority widening.
- [ ] T076 Fail closed on unowned irreversible/external effects.

## P8 Recovery
- [ ] T080 RecoveryCase persistence.
- [ ] T081 Orphan classification.
- [ ] T082 Recovery plan expiry.
- [ ] T083 Bind plan to state/journal/provider/policy/environment.
- [ ] T084 recovery-inspect.
- [ ] T085 recovery-plan.
- [ ] T086 governed recovery-apply.
- [ ] T087 recovery-readback.
- [ ] T088 bookkeeping-only recovery-close.
- [ ] T089 manual_required.

## P9 Simulation/diff
- [ ] T090 plan/simulate/explain/diff.
- [ ] T091 simulation_confidence.
- [ ] T092 unresolved_runtime_effects.
- [ ] T093 must_revalidate_at_apply=true.
- [ ] T094 Bounded semantic diff.
- [ ] T095 Meta redaction.
- [ ] T096 Text hash/length/preview fallback.

## P10 Concurrency/chaos
- [ ] T100 Concurrent same operation_key create.
- [ ] T101 Human edit during compensation.
- [ ] T102 Pipeline setting drift.
- [ ] T103 True concurrent receipt consume.
- [ ] T104 Lock expiry/refresh race.
- [ ] T105 Term deletion plan/apply race.
- [ ] T106 Manual status drift.
- [ ] T107 Stale recovery-plan race.
- [ ] T108 Package-excluded fault injection.
- [ ] T109 Production artifact absence assertion.

## P11 Migration/performance/soak
- [ ] T110 Journal schema migration.
- [ ] T111 Fresh install.
- [ ] T112 rc.83 upgrade.
- [ ] T113 Idempotent repeated migration.
- [ ] T114 Partial migration recovery.
- [ ] T115 Downgrade behavior.
- [ ] T116 Retention cleanup.
- [ ] T117 Decommission policy.
- [ ] T118 50k discovery budget.
- [ ] T119 100-operation soak.
- [ ] T120 10 independent concurrent operations.
- [ ] T121 Same-target contention.

## P12 UI/docs
- [ ] T130 Non-authoritative graph model.
- [ ] T131 Operation timeline.
- [ ] T132 Projection labeling.
- [ ] T133 Provider SDK guide.
- [ ] T134 Recovery examples.
- [ ] T135 Security/privacy guide.

## P13 Staging
- [ ] T140 Create draft.
- [ ] T141 Update draft.
- [ ] T142 Accept + publish.
- [ ] T143 Stale receipt revalidate.
- [ ] T144 Rollback/undo.
- [ ] T145 Human concurrent edit.
- [ ] T146 Missing-term dependency.
- [ ] T147 Provider validation failure.
- [ ] T148 Worker termination after write.
- [ ] T149 Lock loss during repair.
- [ ] T150 External side-effect failure.
- [ ] T151 Pipeline drift.
- [ ] T152 Concurrent receipt consume.
- [ ] T153 100-operation soak evidence.
- [ ] T154 Exact provenance receipt.
- [ ] T155 Production remains unauthorized.
