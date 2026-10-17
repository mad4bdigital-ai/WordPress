# MAD4B Capability-first Provider Health — Rollout 1

**Status:** non-authorizing code candidate on dedicated PR; not a blanket compatibility certification and not a Production deployment.

## Objective

Reuse the existing `MAD4B_SCP_Provider_Contracts`, `MAD4B_SCP_Provider_Compatibility_Certification`, `MAD4B_SCP_Provider_Health_View`, `MAD4B_SCP_Capability_Traits`, `MAD4B_SCP_Site_Capability_Discovery`, `MAD4B_SCP_Unified_Capability_Gateway`, `Audit v2`, `Operation Journal`, `Governed Write`, and `owner_gate`. No new registry, provider credentials, OAuth app, permission bypass, or global version-specific read block.

## Implemented contract

`MAD4B_SCP_Provider_Health_View::capability_first(provider_id, runtime_evidence, certification_evidence)` returns `mad4b.capability-first-provider-health.v1`. The `provider()` and `normalize()` paths now include this additive projection and use its **validated** rows for their existing capability-scoped health summary.

1. Observe capabilities only from the existing `mad4b.provider-capability-certification-result.v1` contract with an **exact** provider ID.
2. Accept at most 64 entries, with typed `surface_exposed` and `structural_compatible` flags. Invalid or foreign evidence cannot promote an existing health result.
3. Distinguish `READ_COMPATIBLE`, `WRITE_CANDIDATE_UNAUTHORIZED`, `UNKNOWN`, `ISOLATED`, and `NOT_EXPOSED`.
4. A version change alone is not incompatible: a live read-capability observation may remain `READ_COMPATIBLE`; absent providers never remain operationally read-compatible.
5. Partial/malformed inventories are `DEGRADED` or `UNKNOWN`, **never** silently `HEALTHY`. Compatible read entries stay visible for investigation.
6. No method grants mutation. `mutation_authorized=false`, `write_grants_created=false`, `owner_gate_bypassed=false`, and `governed_dispatch_required_for_mutation=true` are invariant, including for a reported write certification candidate.
7. Revalidation still belongs to the original dispatch path and its exact authority evidence, not a health projection. Provider status `runtime_contract_mutation_eligible` is **not** a grant and remains constrained by the existing deep runtime contract.

## Coverage

The test matrix uses provider IDs **observed in the existing catalog**, including `jetengine`, `jetsmartfilters`, `bit_pi`, `rank_math`, `elementor`, `google_drive_context`, `litespeed`, `polylang`, `woocommerce`, and `wp-import-export`. It tests **synthetic** runtime/certification evidence. This does not certify those installed integrations or add a new provider driver.

## Rollout tests and constraints

- PHP 7.4 and 8.3: `php wp-content/plugins/mad4b-site-control-plane/tests/provider-health-view-runtime.php`
- Existing Admin Route Registry contract unchanged.
- On each real Staging site, record current Site Profile UUID, provider availability and exact capability evidence **read only** before claiming operational compatibility.
- Where a provider's plugin version changes but semantic read behavior stays compatible, only its observed read capability may be reported as compatible; write and publication remain governed.
- If a capability is missing, foreign, malformed, non-exposed or uncategorized, report unknown/degraded; never assume success or infer an actor.
- Real Elementor/JetEngine/WPML/Rank Math/Fluent Forms behavior and independent Security Observer require separate Staging acceptance before release.
- No Production mutations, automatic package installation, role changes, recovery or quarantine.

## Remaining stages

1. GitHub required CI and exact-head owner approval for this dedicated PR; no merge while pending.
2. Review all call sites that consume the additive `capability_first` field; verify no client equates `READ_COMPATIBLE` to a policy grant.
3. Incrementally attach provider-specific behavioral readback fixtures to the existing `Provider Compatibility Certification` surface (not another registry).
4. Staging-only read-side acceptance for All Royal Egypt and Egypt Tour Gates, with time/memory budget; confirm cross-tenant isolation.
5. Only after separate explicit approval consider a governed mutation canary; Production writes stay blocked.
