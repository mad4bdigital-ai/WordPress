# CSO01 — MAD4B Conversational Site Operations | Spec Kit

**Parent:** Feature 007 / PR #258 · **scope:** CSO01 read-foundation implemented in a candidate PR; remaining families are SPEC_BACKLOG_ONLY · **authority:** none · **production:** not approved.

This extension designs a generic, portable WordPress operating experience where a conversation may discover certified data fields, display editable forms, propose plans and submit independently authorized operations. **No field discovery grants permission to change its value.** CSO01 is the architectural umbrella for Universal Conversation Form Bridge plus all 12 operational additions and Smart Autocomplete, Dependency-Aware Forms, Reusable Templates and Contextual Help.

## Reading order

1. `constitution.md` — unchanged MAD4B governance and secret boundaries.
2. `spec.md`, `requirements.json`, `use-cases.json` — complete product scope and negative cases.
3. `architecture.md`, `data-model.md`, `ui.md` — domain and user experience.
4. `contracts/` — typed form, operation, secrets, provider, workflow and multisite interfaces.
5. `abilities.json` — **proposed only**, not registered runtime WordPress abilities.
6. `threat-model.md`, `acceptance.md`, `gates.json` — deny cases and independent evidence.
7. `plan.md`, `tasks.md`, `tasks.json`, `traceability.md` — phased delivery and ownership.
8. `validate.py`, `test_validate.py` — offline-only, no-CI Spec Kit consistency checks.

## Explicit boundary

Reuses the current site Control Plane's Unified Capability Gateway, capability descriptors, semantic field contracts, NHI/OAuth and subject mapping, Site Profile, budget/approval/Mutation Manager, provider certification, signed receipts, Content Jobs, and Host authority without a second policy/execute plane. Vendor discovery is a **hint**, not writable certification.

No generic `wp_options` dump, arbitrary custom-table SQL, arbitrary `wp eval`, generic shell, unrestricted plugin-option setter, secret transmission in the chat, automatic Production promotion, or forged browser/rollback success.

The WordPress Abilities API supplies individually registered abilities with an input/output schema and permission checks; it does **not** itself offer an arbitrary-configuration bridge or guarantee that any plugin's option is safely mutable. WP 6.9+ support is feature-detected. Schemas exposed as WordPress abilities must remain compatible with the WP-supported JSON Schema subset.

**Scope accounting:** 27 requirement families, 81 OPEN tasks, 11 OPEN gates, 27 proposed ability endpoints, 26 negative-tested user journeys. None change the frozen parent Feature 007 release gates or its current acceptance/CI denominator.

Reference integration: `../../contracts/governed-tool-execution.md`, `../../contracts/schema-contract-evolution.md`, `../../contracts/evidence-attestation-trust.md`, `../../contracts/data-flow-policy.md`, `../competitive-experience/README.md`.

## Runtime foundation candidate (non-authorizing)

The first **read-only source slice** registers `cso/discover`, `cso/form-schema`, `cso/form-validate`, and `cso/form-explain` using the existing `mad4b-read` server, Site Profile, Capability Descriptor Registry, and explicitly enrolled administrator permission. It does **not** infer writable adapters from plugin names or allow secret fields. Form compilation requires the existing read-server tool allowlist, a canonical read execution-lane binding, an explicitly readonly WordPress Ability and a restricted scalar typed schema. Readback re-checks site identity; validation never saves or echoes submitted values. All CSO01 requirements and acceptance gates remain OPEN pending native CI, Staging/Browser and owner certification.

To obtain editable forms, secure handoff, transactional bulk/workflow execution or Production promotion requires *separate* future certified slices and fresh grants. This slice does not add any write/approval/grant endpoint.
