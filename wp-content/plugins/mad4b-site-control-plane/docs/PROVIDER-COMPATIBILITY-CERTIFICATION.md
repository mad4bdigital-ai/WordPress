# Provider Compatibility & Certification Engine — MCP Contract

Contract: `mad4b.provider-compatibility-certification.v1`

The Control Plane treats provider version or file-hash drift as an assessment trigger, not as sufficient evidence of semantic incompatibility. Exact artifact certification remains authoritative for mutation eligibility until capability-specific behavioral and rollback evidence is governed by a future evidence authority.

## Pipeline

`artifact discovery -> structural/capability discovery -> contract verification -> risk classification -> certification level -> MCP mount plan`

The first cataloged providers are JetEngine, JetSmartFilters, and Bit Flows. The catalog is capability-oriented and version-agnostic. It declares canonical capabilities, mapped MAD4B abilities, bounded risk class, structural probes, and reversible contracts where applicable.

## Certification levels

- `UNKNOWN`
- `DISCOVERED`
- `READ_COMPATIBLE`
- `BOUNDED_WRITE_COMPATIBLE`
- `REVERSIBLE_WRITE_CERTIFIED`
- `FULLY_CERTIFIED`
- `QUARANTINED`

A structurally compatible drifted artifact may reach `READ_COMPATIBLE`. Structural compatibility alone never opens a write surface. A mutation may become write-eligible only when its specific capability reaches a governed write certification level.

## MCP surfaces

Read-only, non-authorizing abilities:

- `mad4b/provider-compatibility-inventory`
- `mad4b/provider-capability-certification`
- `mad4b/provider-recertification-plan`
- `mad4b/provider-mcp-mount-plan`

The mount plan is evidence, not execution authority. Environment policy, NHI grants, exact approvals, budgets, stale-state guards, mutation fencing, rollback contracts and the central authorization layer remain mandatory.

## Compatibility model

The engine records artifact evidence separately from structural evidence:

- installed version;
- certified version set;
- certification authority;
- baseline package SHA when available;
- current exact-runtime integrity state;
- bounded runtime artifact fingerprint;
- structural fingerprint based on observed capability probes.

`compatible_unattested` means the required structural contract is present but the changed artifact has not earned governed write certification. It does not mean “trusted for mutation.”

## Per-capability authority

Provider certification is decomposed into capability/ability evidence. A provider can therefore expose compatible reads while one mutation remains pending or quarantined. Mutation guards use the capability-specific certification for cataloged providers and retain the legacy exact provider guard as a fail-closed fallback for providers not yet migrated.

## MCP compiler bridge

The existing server compiler still consumes one provider-level runtime boolean. For cataloged adapters, Adapter Base now derives that boolean from the capability engine and requires **all mutation abilities declared by that adapter** to be write-eligible. This is a deliberately fail-closed compatibility bridge: mixed eligible/blocked mutations never cause the adapter as a whole to mount. Capability evidence remains per ability, and the execution-time permission callback re-runs the ability-specific mutation guard.

This lets JetEngine, JetSmartFilters, and Bit Flows participate in capability-first MCP governance without weakening the existing server boundary. A future compiler revision may consume the per-ability projection directly, but it must preserve the same fail-closed semantics.

## Long-term extension seams

Future stages may add trusted behavioral receipts, rollback receipts, delta certification, source-artifact attestations, dependency-graph invalidation, external acceptance probe registry, shadow/canary states, and a persistent certification cache keyed by artifact fingerprint + adapter contract + policy version. None of those future evidence sources may be caller-supplied booleans or implicitly grant mutation authority.
