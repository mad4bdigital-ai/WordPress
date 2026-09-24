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
- cron-and-growth.md
- observability-and-evidence.md
- generalization-rules.md

References:
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
