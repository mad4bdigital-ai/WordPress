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
- data-model-authority-certification.md

Contracts:
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
