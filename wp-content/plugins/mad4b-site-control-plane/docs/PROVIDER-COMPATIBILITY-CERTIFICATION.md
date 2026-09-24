# Provider Compatibility & Certification Engine — MCP Contract

Contract: `mad4b.provider-compatibility-certification.v1`

Behavioral evidence contract: `mad4b.provider-behavioral-evidence.v1`

Behavioral receipt contract: `mad4b.provider-behavioral-evidence-receipt.v1`

Canary execution contract: `mad4b.provider-canary-execution.v1`

Canary execution evidence contract: `mad4b.provider-canary-execution-evidence.v1`

The Control Plane treats provider version or file-hash drift as an assessment trigger, not as sufficient evidence of semantic incompatibility. Artifact/runtime truth, structural compatibility, behavioral evidence, activation stage, MCP projection, execution authorization and promotion authority are separate authorities and must not overwrite one another.

## Authority separation

The engine keeps these facts independent:

1. **Artifact/runtime certification** — exact provider package/runtime evidence from `MAD4B_SCP_Provider_Contracts`.
2. **Structural compatibility** — bounded capability probes against the currently loaded runtime.
3. **Behavioral evidence** — signed, artifact-bound evidence for a specific capability.
4. **Activation stage** — `shadow`, `canary` or `active`; behavioral evidence alone never promotes a high-risk write to `active`.
5. **MCP mount eligibility** — computed per ability for cataloged providers.
6. **Execution authorization** — environment policy, NHI grants, exact approval, budgets, stale-state guards, mutation fencing and central authorization remain mandatory even when an ability is mount-eligible.
7. **Promotion authority** — successful canary execution is evidence only; it never grants `canary -> active` by itself.

`provider_certification.runtime_contract_ok` is artifact/runtime truth. Capability certification never rewrites it.

## Pipeline

`artifact discovery -> structural/capability discovery -> risk classification -> exact high-risk canary bootstrap or trusted behavioral evidence -> certification level -> activation stage -> per-ability MCP projection -> governed canary execution evidence -> separately governed promotion`

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
- `canary` — exact artifact + structural compatibility + explicit adapter canary opt-in may bootstrap the first separately approved high-risk canary; a current trusted behavioral receipt may additionally bind a repeat canary. The provider target remains blocked from the normal write surface.
- `active` — only capabilities whose separately governed promotion policy is satisfied may become normal write-eligible.

For `high_risk_write`, exact artifact + structural compatibility + explicit adapter canary opt-in can establish a deterministic `canary_basis_digest` and advance only into isolated `canary` eligibility. This bootstrap exists to avoid requiring evidence that can only be produced by the canary itself. A trusted behavioral receipt, when already present, is additionally bound to the request. Neither bootstrap evidence nor behavioral evidence grants owner promotion, mutation authority or normal MCP write eligibility. `bitflows/run-flow` therefore remains blocked from `mad4b-write` while its capability is canary-only.

## Governed high-risk canary execution

The Control Plane exposes one core wrapper mutation:

- `mad4b/provider-canary-execute`

The wrapper is not a bypass around provider certification. It is projected onto `mad4b-write` as `provider=core` through the canonical adapter compiler, while the high-risk provider target itself remains unmounted. The wrapper is hard-classified `high` impact and therefore requires the existing exact one-time approval path.

The internal `Provider Canary` adapter declares the wrapper on the dedicated adapter surface `write` only. It must not declare or mount the wrapper on `admin`, `content` or `read`. The canonical `mad4b-write` compiler consumes that `write` surface, while `mad4b-admin` continues to consume only `admin`; this prevents the canary wrapper from acquiring a second administrative transport path.

A canary request is bound to all of the following current facts:

- exact enrolled governed non-production Site Profile origin and environment;
- exact live candidate source SHA;
- exact live build fingerprint;
- provider id;
- capability id;
- exact target provider ability;
- current runtime artifact fingerprint;
- current capability contract digest;
- current deterministic `canary_basis_digest` from exact artifact, structural probes and adapter opt-in;
- current accepted trusted behavioral evidence digest when a receipt already exists;
- bounded target input digest.

Immediately before side effects the wrapper re-runs those read-only guards and requires:

- capability risk = `high_risk_write`;
- activation stage = `canary`;
- `canary_eligible=true`;
- `write_eligible=false`;
- target provider ability is still absent from normal `mad4b-write`;
- adapter has explicitly opted the exact target ability into canary execution.

Adapters are fail-closed by default. Bit Flows is the first explicit opt-in and only for `bitflows/run-flow`. Its canary path delegates back through the existing Bit Flows runtime guards, including `MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED`, `can_run_flow`, exact flow fingerprint/stale check, per-flow allowlist policy and adapter audit.

The wrapper itself is governed by the existing `mad4b-write` authority: exact NHI grant, target fingerprint, short-lived one-time approval, budget reservation, replay protection and execution fence remain required. The provider target does not receive a normal write grant merely because a canary execution is approved.

Successful execution returns `mad4b.provider-canary-execution-evidence.v1` bound to the exact candidate, artifact, capability contract, canary bootstrap basis, optional current behavioral receipt, and target input/result digests. That evidence is explicitly non-authorizing:

- `activation_granted=false`;
- `promotion_granted=false`;
- activation stage remains `canary` after the execution.

## Per-capability MCP compiler

Provider certification is decomposed into capability/ability evidence. Cataloged providers are compiled **per ability**, so one eligible bounded mutation can be mounted while a sibling mutation remains blocked.

Adapter status exposes these truths separately:

- `provider_certification` — immutable artifact/runtime certification;
- `capability_certification` — per-capability compatibility and evidence;
- `capability_mount_projection` — per-ability eligible/blocked write projection;
- `capability_certification_mode = per_ability_separate_from_artifact_truth`.

The previous provider-wide bridge that rewrote `provider_certification.runtime_contract_ok` from an `all_write_abilities_eligible` aggregate is removed. Mixed eligible/blocked abilities no longer require falsifying provider artifact truth.

Execution-time permission callbacks still re-run the ability-specific mutation guard. Providers not yet represented in the capability catalog retain the legacy exact provider-contract guard as a fail-closed fallback.

The internal `Provider Canary` adapter is not an external provider certification shortcut. It exists only to project `mad4b/provider-canary-execute` through the same canonical write compiler. It has `provider=core`, no provider runtime certification requirement, no arbitrary target execution interface, and a write-only adapter surface.

## MCP surfaces

Read-only, non-authorizing abilities:

- `mad4b/provider-compatibility-inventory`
- `mad4b/provider-capability-certification`
- `mad4b/provider-behavioral-evidence-status`
- `mad4b/provider-recertification-plan`
- `mad4b/provider-mcp-mount-plan`

Governed mutation wrapper:

- `mad4b/provider-canary-execute` — high-impact, non-idempotent, destructive, one-time approval required; projected only through the dedicated `write` adapter surface and produces canary evidence only.

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

## Surface-aware structural compatibility

Capability declarations and actually mounted adapter surfaces are separate facts. Each capability now reports:

- `abilities` — catalog-declared abilities;
- `mounted_abilities` — the subset currently declared by the adapter;
- `surface_exposed` — whether the capability can participate in the current adapter surface.

A structural probe failure on a catalog-only latent capability is retained as evidence but does not by itself mark the provider as a breaking contract. Exposed incompatibilities remain fail-closed. Mixed exposed compatibility is reported as `partially_compatible`; a provider whose installed artifact exists but whose adapter runtime is not available is reported as `adapter_runtime_unavailable`.

`mad4b/provider-mcp-mount-plan` separates latent catalog abilities from mounted eligible/blocked abilities. A latent capability can never become mount-eligible merely because it exists in the capability catalog.

Functional coverage also separates healthy reads from blocked writes. `read_ready_write_blocked` means the provider has a usable governed read surface while mutation remains blocked by exact/capability certification. If an exposed read capability itself is structurally incompatible, the provider remains `safety_blocked`.

Runtime self-test treats drift from an installed-but-inactive provider as advisory evidence rather than platform degradation. The same drift becomes blocking when that provider is active. This classification changes health reporting only; it never grants mutation authority.

Premium candidate metadata is diagnostic, not certification. If an installed premium version differs from the repository package candidate (for example a patch-suffix difference), the candidate relation is reported explicitly and mutation stays fail-closed. Live/runtime hashes are never accepted as self-attestation authority.

## Evidence, artifact authority, capability certification and mutation authority

These are separate trust layers:

1. **Repository evidence** proves what package trees or contracts exist in the reviewed repository. It is evidence, not certification.
2. **Artifact authority** binds an installed provider identity to a trusted provider contract/baseline. A repository ZIP or live version string does not create this authority by itself.
3. **Capability certification** evaluates individual mounted abilities against structural and, where required, behavioral/rollback evidence.
4. **Mutation authority** remains a separate execution-time gate requiring all normal MAD4B authorization controls.

A provider may therefore be `READ_COMPATIBLE` while `artifact_authority_bound=false`. This is valid for structurally bounded reads, but any write capability must remain `DISCOVERED`, `write_eligible=false`, and `artifact_authority_required=true`. Behavioral or rollback probes cannot promote that write until artifact authority is established.

Rank Math intentionally exercises this state in rc.52: the repository contains Rank Math package evidence and the SEO adapter exposes bounded read/write abilities, but no certified Rank Math provider baseline has been created. Its reads can be structurally classified; `seo/update-meta` remains fail-closed and its recertification plan must establish artifact authority before behavioral recertification.

Functional-gap `ready` is also not provider closure. It is retained only as a backward-compatible alias for `evaluation_complete`. Consumers should use `provider_closure_ready`, `followup_required`, and `decision_handoff.groups` to determine whether governed follow-up remains.

## Recertification planning

`mad4b/provider-recertification-plan` is advisory evidence only. Current classifications include:

- `CERTIFIED`;
- `AUTO_CERTIFIABLE_READ_COMPATIBILITY`;
- `BEHAVIORALLY_RECERTIFIED`;
- `OWNER_REVIEW_REQUIRED`;
- `QUARANTINED`.

The plan may identify `owner_governed_canary_execution_required`. That action now has a bounded governed execution path, but the compatibility engine still does not implement owner promotion and cannot self-promote a capability.

## Safety invariants

The compatibility/canary system must remain fail closed:

- no caller-supplied `owner_approved` boolean;
- no exact package may bootstrap a high-risk canary without structural compatibility and explicit adapter opt-in;
- no behavioral receipt may self-grant mutation or activation;
- no stale/wrong-artifact receipt may restore write eligibility;
- no external verifier callback may become trusted merely by setting metadata flags;
- no high-risk provider target may enter the normal write projection from exact artifact identity, behavioral evidence or a canary approval alone;
- no canary execution evidence may self-promote `canary -> active`;
- no capability certification may rewrite provider artifact truth;
- no nested MAD4B approval envelope may be forwarded to a canary target;
- no canary wrapper may be mounted on `mad4b-admin` or `mad4b-content`;
- mount eligibility never bypasses central mutation authorization.

## Current non-goals / next governed stages

The following are intentionally **not** implemented by this contract yet:

- owner-promotion authority for `canary -> active`;
- promotion-policy consumption of successful canary execution evidence;
- persistent behavioral-certification cache;
- cross-request durable promotion ledger/state;
- automatic Production activation.

Those stages must use separately governed, candidate-bound evidence/authorization contracts. They must not be represented as caller booleans and must preserve the existing approval, replay protection and Production fail-closed boundaries.
