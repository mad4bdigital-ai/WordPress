# Specification — Dynamic Content Runtime Hardening & Operations Platform

## 1. Problem

PR #157 established the governed runtime-dynamic content engine. Feature 008 MUST harden that runtime without widening authority. Residual risk is operational complexity across hashes, receipts, locks, convergence, provider hooks, compensation, discovery, crash recovery and debugging.

## 2. Identity model

Three identities are mandatory and MUST NOT be conflated:

1. **operation_key** — stable business/idempotency identity. Reuse is expected for retries of the same intent.
2. **operation_id** — unique execution-attempt identity. Every attempt/retry/recovery attempt gets a distinct value.
3. **operation_binding_sha256** — immutable exact execution binding over canonical operation facts and policy digests.

A retry MAY reuse operation_key but MUST use a new operation_id. Approval and recovery execution bind operation_binding_sha256.

## 3. Goals

- G1: Decompose the adapter only after characterization and minimal journaling.
- G2: Produce tamper-evident operation traces.
- G3: Maintain bounded internal metrics independent of external telemetry systems.
- G4: Scale discovery while ensuring cache NEVER authorizes mutation.
- G5: Extend the existing trusted provider ecosystem; do not create a parallel authority registry.
- G6: Use deterministic TTL tiers (short/standard/long) before any predictive/adaptive algorithm.
- G7: Persist durable crash-recovery evidence.
- G8: Govern recovery as inspect → plan → approve when required → apply → readback → close.
- G9: Certify concurrency and CAS invariants.
- G10: Keep fault injection test-only and absent from Production packages.
- G11: Model provider side effects across independent dimensions.
- G12: Separate impact_level from additive impact_flags; AI approval is default-deny.
- G13: Simulation declares uncertainty and requires apply-time revalidation.
- G14: Semantic diff is bounded, privacy-aware and supplemental to hashes.
- G15: Visualizer is a non-authoritative projection.
- G16: Version provider/journal/telemetry/recovery contracts.
- G17: Run expanded Staging crash/concurrency/soak certification.

## 4. Non-goals

No generic shell, wildcard grant, generic Production write scope, raw SQL, Breakglass enablement, executable persisted callbacks, cache-based authorization, silent overwrite of human drift, Production chaos injection, false full-rollback claims, or second provider authority registry.

## 5. Functional requirements

### FR-001 Characterization before refactor
Normalized rc.83 fixtures MUST exist before extraction. Compatibility means no removed public ability/field, old-field canonical projection unchanged, and additive versioned fields allowed.

### FR-002 Evidence foundation before decomposition
Implement minimum OperationContext, append-only event journal, safe redaction, heartbeat/deadline and journal head hash before extracting mutation services.

### FR-003 Component decomposition
Internal services: ModelDiscovery, BundleValidator, BundleExecutor, SnapshotManager, CompensationManager, AcceptanceManager, PublicationGuard, OperationContext, OperationJournal, RuntimeMetrics, SemanticDiff, RecoveryReconciler, ProviderContractBridge, TtlPolicy.

### FR-004 Operation identities
operation_key, operation_id and operation_binding_sha256 are distinct and versioned.

### FR-005 Structured events and metrics
Events contain safe operational metadata only; metrics use bounded internal aggregates with optional exporters.

### FR-006 Discovery modes
Use detail=summary|expanded. Return complete, truncated_sections, applied_limits, model_digest and cache_hit. Expanded remains bounded.

### FR-007 Cache safety
Before mutation, live checks revalidate post type/taxonomy existence and attachment, current capabilities, environment policy, target state, provider eligibility and exact state/pipeline hashes.

### FR-008 Provider SDK bridge
Provider contracts extend existing trusted registration. Manifest presence never authorizes.

### FR-009 Provider callback budgets
In-process elapsed budgets are soft. Hard timeouts may only be claimed when transport supports enforceable cancellation/deadlines.

### FR-010 TTL tiers and interruption
TTL tier is deterministic and receipt-bound. Hard deadline does not interrupt a critical local write between ownership change and its required readback/checkpoint; finish bounded critical section, then stop and classify recovery.

### FR-011 Journal/event model
Journal is append-oriented. checkpoint, lifecycle_state and terminal_outcome are distinct. Optional phases do not imply one linear state machine.

### FR-012 Durable storage/retention
Use dedicated versioned custom tables for high-volume journal events. wp_options is prohibited for journal rows. Retention, compaction and cleanup are bounded and explicit.

### FR-013 Tamper evidence
Journal events chain previous_event_sha256 with domain-separated hashes or reuse an equivalent append-only audit chain.

### FR-014 Recovery exact binding
Recovery plan binds operation identities, current_state_sha256, journal_head_sha256, provider_state_digest, pipeline/policy digests, environment, generated_at and expires_at.

### FR-015 Recovery close
Close is bookkeeping-only after verified recovery. Content mutation occurs only in recovery-apply.

### FR-016 Orphan detection
Use heartbeat_at, lock_expires_at, hard_deadline_at and stale_after. Session disconnect alone never proves failure.

### FR-017 Side-effect model
Each provider effect declares effect_scope, ownership, reversibility, compensation_support, externality and verification method.

### FR-018 Impact/approval
impact_level=low|medium|high. impact_flags are additive: publication, schema_change, external_side_effect, irreversible, recovery, provider_mutation. AI approval defaults false and binds exact policy/environment/effect/operation digest and expiry.

### FR-019 Simulation
Return simulation_confidence, unresolved_runtime_effects and must_revalidate_at_apply=true.

### FR-020 Semantic diff privacy
Unregistered meta is redacted by default. Large text uses digest/length plus bounded preview only if policy permits.

### FR-021 Visualizer
Visualizer is read-only and labeled projection, not execution truth.

### FR-022 Canonicalization
Every new hash defines sorting, Unicode, omitted/null semantics, scalar normalization, array order and domain separation.

### FR-023 Migration/decommission
Test fresh install, rc.83 upgrade, repeated migration, partial migration recovery and downgrade behavior. Uninstall never destroys journal/recovery data automatically.

### FR-024 Performance/soak
Large-site tests assert query count, elapsed time, memory and result bounds. Staging soak covers 100 cycles and concurrency.

### FR-025 Canary provenance
Canary evidence binds exact code SHA, build/package digest, site identity, environment, policy digest and pipeline digest.

## 6. Safety invariants

Cache never authorizes mutation; discovery/provider manifests never expand authority; persisted config never contains executable callbacks; mandatory structural/acceptance stages remain mandatory; publication remains acceptance-bound; human drift beats compensation; rollback success requires exact readback; external/irreversible effects block false atomicity; AI approval is default deny; telemetry is bounded/privacy-filtered/access-controlled/tamper-evident; Production package contains no fault injection; Staging certification never authorizes Production.

## 7. Success criteria

Repository Spec Consistency green; rc.83 characterization green pre/post decomposition; schema migration green for fresh/upgrade/idempotent/partial cases; 50k-term discovery within query/time/memory/result budgets; at most one success for concurrent receipt consume; no overwrite of human edits; deterministic interrupted-operation classification; no false rollback over irreversible external effects; no fault injection in Production package; 100-operation soak without leaked locks/zombies; 10 concurrent independent operations preserve invariants; all expanded Staging canaries produce exact provenance.
