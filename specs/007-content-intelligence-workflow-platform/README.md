# Feature 007 — Content Intelligence + Governed Workflow Platform

This Spec Kit defines the next platform layer above MAD4B Site Control Plane rc.59.

It is intentionally generic:
- ETG is the first validation profile.
- Bit Flows is the first WorkflowProvider.
- Google Drive is the first major ContextProvider.
- Rank Math is the current SEO write provider.
- Future providers replace adapters, not domain contracts.

## Files

Core:
- supported-runtime-profiles.json
- gate-graph.json
- data-model-critical-kernel.md
- critical-kernel.md
- implementation-closure.md
- implementation-closure.json
- feature.json
- constitution.md
- spec.md
- research.md
- data-model.md
- plan.md
- tasks.md
- quickstart.md
- runbook.md
- traceability.md
- coverage-audit.md
- quality-model.md
- quality-scorecard.md
- data-model-authority-certification.md
- data-model-operations-lifecycle.md

Contracts:
- architecture-freeze.md
- formal-model-critical-state.md
- data-flow-policy.md
- audit-telemetry-retention.md
- offline-authorization-window.md
- runtime-profile-compatibility.md
- ai-eval-integrity.md
- publication-semantic-fingerprint.md
- privacy-safe-content-addressing.md
- intent-ownership.md
- capability-traits.md
- root-trust-recovery-plane.md
- gate-dag-bootstrap-liveness.md
- execution-commit-guard.md
- execution-plane-fencing.md
- authoritative-state-projections.md
- baseline-synchronization.md
- decommission-portability.md
- usage-ledger-chargeback.md
- experimentation-attribution.md
- eval-registry-alerting.md
- localization-accessibility-linkgraph.md
- fair-scheduling-local-autonomy.md
- provider-conformance-contract-lifecycle.md
- operator-control-doctor-deadletter.md
- ai-data-processing-residency.md
- content-rights-licensing.md
- publication-verification.md
- incremental-recompute.md
- artifact-storage-retrieval.md
- existing-site-bootstrap-content-inventory.md
- evidence-attestation-trust.md
- policy-resolution-separation-of-duties.md
- spec-isolation.md
- policy-drift-kill-switches.md
- verification-testing-strategy.md
- disaster-recovery-operability.md
- performance-capacity-cost.md
- tenant-privacy-retention.md
- source-trust-ai-evaluation.md
- supply-chain-secrets.md
- security-threat-model.md
- schema-contract-evolution.md
- durable-execution-resilience.md
- correctness-consistency-idempotency.md
- release-rings.md
- provider-resolver.md
- signed-workflow-bridge.md
- behavioral-probe-catalog.md
- workflow-provider-diagnostic.md
- dynamic-provider-certification.md
- mcp-protocol-evolution.md
- oauth-key-lifecycle.md
- multi-authority-live-certification.md
- subject-mapping.md
- multi-authority-registry.md
- architecture-boundaries.md
- release-lineage.md
- workflow-provider.md
- provider-certification.md
- plugin-onboarding.md
- content-job.md
- context-dispatch.md
- research-provider.md
- artifact-and-quality-gates.md
- skills-orchestration.md
- publishing.md
- host-connector.md
- governed-tool-execution.md
- cli-host-runner.md
- cron-and-growth.md
- observability-and-evidence.md
- generalization-rules.md

References:
- host-provider-validation-profile.md
- current-platform-baseline.md
- provider-observations.md

Checklist:
- checklists/requirements.md

## Merge rule

This specification branch is based on rc.59 integration lineage. Runtime implementation beyond specification work is gated by Phase 0 canonicalization in plan.md.

## Coverage rule

`coverage-audit.md` is the normative mapping from all supplied architecture inputs to Feature 007 contracts/tasks. A concern is not considered captured merely because it was discussed in chat; it must appear in the audit and be linked to a contract/task/gate.

## Cross-feature isolation

Feature 007 stores its metadata in this directory's `feature.json`. It MUST NOT replace the repository-global `.specify/feature.json` owned by Feature 001 CI. Shared/root specification metadata is treated as an externally owned compatibility surface unless a dedicated migration spec changes the repository convention.

## Quality rule

Functional coverage is not sufficient for release. `quality-model.md` defines independent hard quality gates for correctness, resilience, security, data/privacy, performance/cost, evaluation, compatibility and recovery. Hard blockers are not averaged into a single score.


## Long-lived operating model

Feature 007 is not limited to greenfield content generation. It MUST inventory existing site state, resolve intent ownership, store immutable artifacts, recompute only affected dependencies, verify public publication, support human operations and eventual provider/site decommission. Multi-site fairness, rights, data-processing, localization, accessibility, experiments and economic usage are first-class platform concerns rather than vendor-specific add-ons.


## Critical Kernel freeze

The architecture is now frozen for first implementation scope. Broader maturity contracts remain valid design context, but a new CORE abstraction must satisfy `contracts/architecture-freeze.md`.

The active implementation target is `critical-kernel.md`, not automatic execution of every contract in this directory.

The PR must also contain the current target-branch base SHA as an ancestor. Green spec CI on a stale baseline is not sufficient.

## Governed execution rule

Host/CLI automation is expressed as semantic ToolOperations with certified executor adapters. MCP, WP-CLI, standalone CLI, Host Runner and provider API/CLI are not separate authority planes.

Hostinger is a validation profile only. Generic contracts must remain portable to other hosting providers and executor channels.

Ordinary capabilities MUST NOT expose arbitrary shell, arbitrary `wp eval`, arbitrary PHP or unrestricted raw SQL.


## Unified implementation closure

`implementation-closure.md` is the normative program for all remaining Feature 007 work. `implementation-closure.json` is its machine-readable workstream ledger and is validated by `validate_spec.py`.

The closure program separates:
- first vertical-slice hard blockers;
- live mutation preconditions;
- required platform maturity;
- intentionally deferred maturity.

Phase 36 in `plan.md` and `tasks.md` is the execution umbrella. Existing phases remain authoritative for detailed semantics; Phase 36 does not duplicate or supersede their contracts.

The first vertical slice cannot close while repository governance is only committed but not externally enforced, protected backup/recovery is unready, the exact installed workflow provider is uncertified, or the ContentJob-to-public-verification chain lacks exact ETG evidence.

## Optional competitive experience extension

[CE01](extensions/competitive-experience/README.md) retains four original competitor ZIPs with source hashes and defines 59 capability requirements, 35 local phases and 175 implementation tasks. Its independent ledger is outside the frozen release scope.

## Optional Adaptive Content Intelligence OS target Spec Kit (ACI01)

[ACI01](extensions/adaptive-content-intelligence-os/README.md) is the full, **non-authorizing** target architecture for multi-site/brand/locale discovery, governed research and evidence, content/experience planning, native Post Meta/WPML relation integrity, independent QA, publication and postpublish learning. It defines 43 requirements, 71 OPEN tasks, 11 local gates, 5 decision loops and bounded provider/economic/authority contracts. This optional documentation extension neither changes the frozen Feature 007/CE01 release denominator nor claims deployed runtime or Production readiness. Its validator and denial tests run in Feature 007 Spec Quality CI.

## Optional Conversational Site Operations Spec Kit (CSO01)

[CSO01](extensions/conversational-site-operations/README.md) specifies the full conversational WordPress operating experience, beyond Universal Conversation Form Bridge: discovery, dynamic fields, Smart Autocomplete, dependent forms, diff/preview, isolated API secret ingress, bulk jobs, workflow DAGs, history/compensation, content/media/SEO, multi-site orchestration, approvals, monitoring, Staging-to-Production planning, reusable templates, contextual help, self-diagnostics, provider certification and lifecycle. The package has **27 functional requirement families, 81 OPEN tasks, 11 OPEN gates, 27 PROPOSED (unregistered) Abilities and 26 negative-tested journeys** plus typed domain JSON Schemas and an offline validator.

This is `SPEC_BACKLOG_ONLY`, explicitly non-authorizing and outside the frozen Feature 007 release/task denominator. It does not register generic option/database writes, collect secrets in ChatGPT, mint Product authority or claim a site deployment or live acceptance. Its schema/security/cross-file consistency is checked by its own `validate.py` and `test_validate.py`; native WP/provider/Host/Browser acceptance remains external.
