# CSO01 — Canonical system architecture

## One route through MAD4B

ChatGPT intent → existing MCP/OAuth resource and subject → read-only capability discovery → certified Adapter Registry → immutable typed form schema → draft and local validation → current-value read and redacted diff → exact impact/side-effect/compensation plan → existing Policy + NHI grants + approval + step-up → existing Execution Fence/Mutation Manager → native plugin Ability or certified adapter → independent readback → redacted receipt → optional compensating plan.

A form renderer, Workflow Builder, Bulk Planner and Multi-site Command Center are NON-AUTHORIZING clients of the existing policy plane.

## Ownership

| Plane | Components | May authorize execution? |
|---|---|---|
| User experience | conversation classifier, Site Explorer, Form Renderer, Smart Suggest, Contextual Help, preview and history | No |
| Schema and provider | field schema compiler, typed storage selector, plugin/version adapter traits, compatibility matrix | No |
| Secure ingress | user-initiated verified first-party HTTPS session, one-time nonce, provider-secret-store bridge | No by itself |
| Planning | immutable exact-source diff, target revision, risk, budget, template and workflow DAG | No |
| Governance | existing MAD4B Site Profile, OAuth/NHI, grants, policy, approval, risk and isolation | Existing bounded authority only |
| Execution | existing governed Abilities, provider callbacks, Mutation Manager, journal, conditional compensators | Only exact authorized scope |
| Verification | independent postcondition readback, signed external Browser/Host/provider attestation | No authority retroactively |
| Operations | monitor/alerts, durable status, cancellation, read-only Doctor and client evidence | No |
| Release | signed Staging artifact and separate Production admission and rollback | Explicit separate Production decision |

## Plugin storage

Prefer registered WordPress Ability with input/output schema and permission callback, then certified vendor adapter using native callbacks/hooks/sanitizers, else read-only unsupported. Options/serialized arrays and private custom tables are NOT generic write targets. A vendor-specific driver needs schema/version attestation, update hooks, conditional revisions, compensator and independent validation.

## Orchestration

A conversation workflow compiles to an acyclic typed DAG of registered operations and explicit dependency/output bindings. Stage progression: PLANNED → AUTHORIZED → RUNNING → VERIFYING → SUCCEEDED; alternate PARTIAL, UNCERTAIN, FAILED, COMPENSATING and COMPENSATED. Waits, Cron and webhooks schedule work but never create authority. Multi-site requests compile independent per-site child plans.

## Compatibility

WordPress 6.9+ Abilities API is discoverable but does not automatically certify plugin configuration writes. Runtime schemas must stay within WordPress-supported JSON Schema subset; compile domain schemas accordingly. Required WordPress/PHP, plugin vendor, REST/MCP and client capabilities are feature-detected. Upgrade or schema drift invalidates forms and plans.
