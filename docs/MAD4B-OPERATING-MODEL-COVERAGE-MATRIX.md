# MAD4B Operating Model Coverage Matrix

This is a delivery contract, not a marketing checklist. A requirement is **implemented** only when code and tests enforce it. Uncertified provider mutation remains **fail-closed**.

## Generic operating-system coverage

| Requirement | State | Implementation |
| --- | --- | --- |
| Capability-first / Skill-driven separation | Implemented | bounded abilities/adapters; Skills are non-authorizing |
| Machine-readable Contracts | Implemented foundation | operating-model contract + bundle validation |
| Ownership model | Implemented foundation | mad4b.ownership-model.v1 |
| Universal ownership-driven provider reconciliation | Partial | ownership + semantic identity are defined, but every provider reconciler does not consume them automatically yet |
| Semantic identity vs physical IDs | Implemented | mad4b/semantic-identity-map |
| Desired vs Observed State | Implemented | mad4b/state-diff |
| Independent operation planner | Implemented | mad4b/operation-plan |
| Exact plan authority binding | Implemented for new lifecycle/workflow writes | expected_plan_sha256 is mandatory and revalidated immediately before plugin lifecycle or Bit Flows execution; approval tickets bind that exact input plus target/NHI/build/Site Profile |
| First-class rollback | Implemented | reversible mutation envelope + readback + undo drift guard |
| Evidence dependency graph | Implemented | dependency graph + mad4b/evidence-invalidation-plan |
| Persistent universal Evidence Graph store/query API | Partial | dependency/invalidation engine and governed receipts exist; evidence is not yet unified into one persistent graph query surface |
| Dependency-scoped evidence reuse | Implemented planning primitive | transitive stale-set calculation |
| Small composable Skills | Implemented foundation | canonical Skill pack + release coordinator |
| Declarative Site/Feature bundles | Implemented foundation | Site Profile v2 + bundle validator |
| Capability-level certification | Implemented | capability catalog + behavioral certification |
| Discovery/promotion lifecycle | Implemented | discovery/read/bounded/reversible/full + shadow/canary/active |
| Thin site configuration | Enforced for new generic layer | contract guard rejects ETG literals |
| ETG as domain provider | Mostly implemented; compatibility debt remains | inherited elementor/set-etg-dynamic-tag is temporary PR #15 compatibility |
| Generic Browser Acceptance | Implemented core | generic provider registry + plan/result digest/signature |
| Generic assertion DSL | Partial | provider plan/result schemas exist; no generic expression DSL is authority |
| Workflow compiler | Implemented read-only | DAG/cycle validation; mechanics routing; MAD4B mutation boundary |
| Independent Policy Engine | Implemented | policy + authorization + exact authority |
| Candidate state machine | Implemented | explicit bootstrap-safe state progression |
| Declarative invariants | Implemented | mad4b/invariant-evaluate |
| Provider-neutral workflow contract | Implemented | Workflow Provider v1 |

## Bit Flows coverage

| Requirement | State |
| --- | --- |
| Replaceable execution provider, never source of truth | Implemented |
| list/get/execution-status | capability-gated |
| execute | high-risk governed/fail-closed unless certified |
| create/enable/disable/retry/cancel | fail-closed pending certification |
| direct provider DB writes | forbidden |
| caller-supplied credentials | forbidden |
| production authority | forbidden to workflow provider |
| prefer Free/public integration surfaces | contracted: webhook, HTTP API, WordPress hooks, Custom App |
| Bridge trigger/action model | contracted, not activated pending public provider contract |
| Bit Flows Addon/Bridge activation | Contracted, not activated | requires provider-public API/hook/Custom App contract plus exact-runtime behavioral certification; no internal DB/class fallback |
| Pro-only features as core dependency | not required |

## Compatibility debt

The inherited PR #15 repair path still exposes elementor/set-etg-dynamic-tag. It is retained so this stacked PR does not silently break the exact ETG Staging repair lineage. The generic replacement direction is elementor/set-dynamic-tag plus domain/site contract bindings. Migration must happen after #15 and preserve rollback/readback behavior.

## Completion rule

Generic architecture is covered when each axis has executable read-only planning/governance or an existing governed runtime implementation. Provider-specific write support is never claimed from internal classes alone. Bit Flows lifecycle writes and bridge activation remain blocked until provider-supported contracts pass exact-runtime behavioral certification.
