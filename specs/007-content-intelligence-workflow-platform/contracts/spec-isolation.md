# Contract — Cross-Feature Specification Isolation

Contract: mad4b.spec-isolation.v1

## Problem

The repository currently contains legacy/global Spec Kit metadata used as an executable CI contract by Feature 001. A new feature must not assume repository-global metadata is a safe singleton to overwrite.

## Rules

1. Feature-specific metadata MUST live under that feature's own specification directory unless the repository convention is deliberately migrated under a separate compatibility plan.
2. A feature MUST NOT mutate another feature's constitution, feature metadata, CI expectations, package identity, or release gates merely to register itself.
3. Root/shared Spec Kit files are compatibility surfaces. Changes require an explicit cross-feature impact analysis.
4. Spec-only branches MUST pass all pre-existing unrelated feature contract checks that are expected to remain compatible.
5. Every feature directory SHOULD contain its own machine-readable metadata file.
6. CI SHOULD validate a selected feature by explicit path/ID rather than implicit global singleton when the repository evolves to multi-feature Spec Kit support.
7. Until that migration occurs, Feature 007 preserves the legacy global `.specify/feature.json` exactly.

## Feature 007 location

Machine-readable metadata: `specs/007-content-intelligence-workflow-platform/feature.json`

The repository-global `.specify/feature.json` remains owned by the existing Feature 001 workflow contract.

## Acceptance

- diff against rc.59 shows no modification to `.specify/feature.json`;
- ETG DFSB CI Feature 001 metadata assertions pass;
- Feature 007 documents and tooling can be located without a global pointer change;
- future multi-feature metadata migration, if desired, is a separate change with compatibility tests.

## Spec maintenance during implementation

Once Feature 007 enters `implementation`, specification maintenance remains valid on isolated branches matching `spec/007-*` when and only when every changed path is inside the workflow-owned specification allowlist.

This does not widen runtime implementation authority:
- runtime/plugin/tool changes remain restricted to the exact implementation branch;
- cross-feature files remain denied unless the implementation branch uses the separately declared dependency allowlist;
- `feature.json` cannot widen the workflow-owned allowlist;
- baseline ancestry/current-baseline metadata gates still apply;
- Spec validation/gate-liveness still applies.

This rule prevents an implementation-status flag from freezing the specification while preserving phase isolation.
