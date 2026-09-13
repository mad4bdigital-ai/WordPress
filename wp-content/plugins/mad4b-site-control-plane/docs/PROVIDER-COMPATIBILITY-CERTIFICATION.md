# Provider Compatibility & Certification Engine — MCP Contract

Contract: `mad4b.provider-compatibility-certification.v1`

Behavioral evidence contract: `mad4b.provider-behavioral-evidence.v1`

Behavioral receipt contract: `mad4b.provider-behavioral-evidence-receipt.v1`

The Control Plane treats provider version or file-hash drift as an assessment trigger, not as sufficient evidence of semantic incompatibility. Artifact/runtime truth, structural compatibility, behavioral evidence, activation stage, MCP projection and execution authorization are separate authorities and must not overwrite one another.

## Authority separation

The engine keeps these facts independent:

1. **Artifact/runtime certification** — exact provider package/runtime evidence from `MAD4B_SCP_Provider_Contracts`.
2. **Structural compatibility** — bounded capability probes against the currently loaded runtime.
3. **Behavioral evidence** — signed, artifact-bound evidence for a specific capability.
4. **Activation stage** — `shadow`, `canary` or `active`; behavioral evidence alone never promotes a high-risk write to `active`.
5. **MCP mount eligibility** — computed per ability for cataloged providers.
6. **Execution authorization** — environment policy, NHI grants, exact approval, budgets, stale-state guards, mutation fencing and central authorization remain mandatory even when an ability is mount-eligible.

`provider_certification.runtime_contract_ok` is artifact/runtime truth. Capability certification never rewrites it.

## Pipeline

`artifact discovery -> structural/capability discovery -> risk classification -> trusted behavioral evidence -> certification level -> activation stage -> per-ability MCP projection`

The first cataloged providers are JetEngine, JetSmartFilters and Bit Flows. The catalog is capability-oriented and version-agnostic. It declares canonical capabilities, mapped MAD4B abilities, bounded risk class, structural probes, and reversible contracts where applicable.

## Certification levels

- `UNKNOWN`
- `DISCOVERED`
- `READ_COMPATIBLE`
- `BOUNDED_WRITE_COMPATIBLE`
- `REVERSIBLE_WRITE_CERTIFIED`
- `FULLY_CERTIFIED`
- `QUARANTINED`

A structurally compatible drifted artifact may reach `READ_COMPATIBLE`. Structural compatibility alone never opens a write surface.

For bounded writes on a drifted artifact, the specific capability may regain write certification only from trusted behavioral evidence bound to the current artifact and capability contract. A reversible capability also requires rollback evidence. This does not change the provider-level artifact state: a drifted provider may remain `compatible_unattested` while one bounded capability is behaviorally recertified.

Exact repository-baseline certification can certify bounded/reversible writes according to their declared capability contract. High-risk writes are deliberately different: exact artifact identity alone never activates them.

## Behavioral evidence trust boundary

Behavioral evidence is read-only evidence and is never mutation or activation authority.

An accepted receipt is bound to:

- `provider_id`;
- `capability_id`;
- current runtime artifact fingerprint;
- capability contract digest;
- verifier ID and issuer ID;
- issue and expiry timestamps;
- canonical behavioral and rollback observations;
- evidence digest and signature.

Receipts have a bounded TTL. Stale, wrong-artifact, wrong-capability, wrong-contract, malformed or unverifiable receipts fail closed.

Verifier discovery is local, but declaring `trusted=true` is not sufficient. The verifier callback implementation itself must resolve through `Reflection` to code owned by the MAD4B Control Plane source root. External plugin callbacks are excluded from the trusted verifier registry even if they attempt to self-declare as trusted.

The public evidence surface reports verifier provenance without exposing local filesystem paths.

## Activation stages

- `shadow` — observed but not eligible for normal write mount/execution.
- `canary` — behavioral evidence may make a high-risk capability eligible for a future owner-governed canary procedure, but it remains blocked from the normal write surface.
- `active` — only capabilities whose current certification policy permits normal write eligibility may reach this stage.

For `high_risk_write`, behavioral evidence can advance `shadow -> canary` only. It does not grant owner promotion, mutation authority or normal MCP write eligibility. `bitflows/run-flow` therefore remains blocked from `mad4b-write` while its capability is canary-only.

## Per-capability MCP compiler

Provider certification is decomposed into capability/ability evidence. Cataloged providers are compiled **per ability**, so one eligible bounded mutation can be mounted while a sibling mutation remains blocked.

Adapter status exposes these truths separately:

- `provider_certification` — immutable artifact/runtime certification;
- `capability_certification` — per-capability compatibility and evidence;
- `capability_mount_projection` — per-ability eligible/blocked write projection;
- `capability_certification_mode = per_ability_separate_from_artifact_truth`.

The previous provider-wide bridge that rewrote `provider_certification.runtime_contract_ok` from an `all_write_abilities_eligible` aggregate is removed. Mixed eligible/blocked abilities no longer require falsifying provider artifact truth.

Execution-time permission callbacks still re-run the ability-specific mutation guard. Providers not yet represented in the capability catalog retain the legacy exact provider-contract guard as a fail-closed fallback.

## MCP surfaces

Read-only, non-authorizing abilities:

- `mad4b/provider-compatibility-inventory`
- `mad4b/provider-capability-certification`
- `mad4b/provider-behavioral-evidence-status`
- `mad4b/provider-recertification-plan`
- `mad4b/provider-mcp-mount-plan`

The mount plan is evidence, not execution authority. Every returned status remains non-authorizing.

## Compatibility states

The engine records artifact evidence separately from structural and behavioral evidence:

- installed version;
- certified version set;
- certification authority;
- baseline package SHA when available;
- current exact-runtime integrity state;
- bounded runtime artifact fingerprint;
- structural fingerprint based on observed capability probes;
- per-capability contract digest;
- behavioral/rollback evidence state;
- certification source;
- activation stage.

`compatible_unattested` means the required structural contract is present but the provider artifact itself is not an exact certified baseline. It does not mean “trusted for mutation.” A specific bounded capability may still be behaviorally recertified without changing that provider-level artifact truth.

## Recertification planning

`mad4b/provider-recertification-plan` is advisory evidence only. Current classifications include:

- `CERTIFIED`;
- `AUTO_CERTIFIABLE_READ_COMPATIBILITY`;
- `BEHAVIORALLY_RECERTIFIED`;
- `OWNER_REVIEW_REQUIRED`;
- `QUARANTINED`.

The plan may identify `owner_governed_canary_execution_required`, but the compatibility engine does not implement owner authorization and cannot self-promote a capability.

## Safety invariants

The compatibility engine must remain fail closed:

- no caller-supplied `owner_approved` boolean;
- no behavioral receipt may self-grant mutation or activation;
- no stale/wrong-artifact receipt may restore write eligibility;
- no external verifier callback may become trusted merely by setting metadata flags;
- no high-risk capability may enter the normal write projection from exact artifact identity or behavioral evidence alone;
- no capability certification may rewrite provider artifact truth;
- mount eligibility never bypasses central mutation authorization.

## Current non-goals / next governed stages

The following are intentionally **not** implemented by this contract yet:

- owner-promotion authority for `canary -> active`;
- execution of high-risk canary mutations;
- persistent behavioral-certification cache;
- cross-request durable promotion ledger;
- automatic Production activation.

Those stages must use separately governed, candidate-bound evidence/authorization contracts. They must not be represented as caller booleans and must preserve the existing approval, replay protection, rollback and Production fail-closed boundaries.
