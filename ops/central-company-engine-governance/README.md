# Central Company Engine Governance v1

**Ownership:** MAD4B Central Company Management. This is not a replacement for global Core Governance.

## Capabilities
- Validated dependency-ordered registry of nine company-management engines.
- Live source-module inventory and exact mapping to existing MAD4B Core Operation Registry.
- Non-authorizing company/site/environment-bound assessment.
- Read-only native WordPress Core registry readback from central Staging.
- Fail-closed tests for authority, duplicate dispatcher, missing Core mappings, and protected scope.

## No execution authority
It cannot generate grants, dispatch shell/WP-CLI commands, update WordPress, migrate taxonomies, install plugins, assign companies, or approve a restore/production release. The original Core Operation Registry, Dispatcher, Native Executor, authorization boundary and signed receipts remain authoritative.

Core operation mapping proves registry presence only, **not** certified execution. The operation for schema evolution cannot be enabled by merely writing a placeholder.

## Central VPS usage
Run: python3 governance.py --output central-company-engine-audit.json
Run: python3 test_governance.py
Run: python3 runtime_probe.py > central-core-native-readback.json

Run the live probe only on the enrolled central VPS. Do not publish credentials, site-private config, data rows or site-bound identities in version control.

## Acceptance gates
1. Independently prove ownership and provider capability contracts.
2. Bind real operation to the original Core Registry and the reviewed native planner/executor; no parallel dispatchers.
3. Independently verify exact company/site identity and fresh scoped authority.
4. Certify provider-specific adapters, versioned source definitions, restore checkpoint.
5. Staging native apply + readback + real restore; promote via the same Core with risk-based Production authorization.
6. Send identity/drift conflicts to Reviews; never implicitly transfer company or site data.

## Observed on 2026-11-01
Nine engines inventoried; nine have all declared source modules; two mapped to existing Core operations. Live readback on tours.dev.mad4b.com confirms company.plugin-sync.request and wordpress.runtime.converge mapped; wordpress.taxonomy.evolve unregistered. No WordPress or company data mutation. **Not** a nine-engine runtime certification.

## Native Control Plane read-only integration candidate

The original plugin bootstrap registers the protected read-only ability
mad4b/central-company-engine-governance-status on wp_abilities_api_init.
It maps the company-specific engine catalog to the ORIGINAL Core Operation Registry.
No mutation endpoint, second dispatcher or grant is introduced.

A separate native PHP negative-test suite validates hook metadata, the
two known Core operation mappings, duplicate-operation and dependency-cycle
refusal and caller-injected authority refusal. VPS Shadow ran assess_manifest()
in actual central WordPress CLI; isolated runtime bootstrap promotion and
full native deployment certification remain outstanding.

## Core-native bounded signed-evidence comparison (source candidate)

The original Operation Registry now registers a real typed **read-only** operation:
company.code-reconciliation.plan (Core planner
mad4b/company-code-reconciliation-plan; Core comparator
mad4b/company-code-reconciliation-compare). Both are registered as read
Abilities, using the existing Core dispatcher and Capability Descriptor Registry.
A purpose-specific governed_read_only_v1 profile has strict executor allowlisting,
signed-inventory evidence checks, original-Core receipt verification, exact
company/site fingerprint binding, scoped page limits (max 200 entries per
manifest), owner-preserving SHA diff decisions, and no file transfer/Apply.

**Important:** The new comparator's functional tests use hermetic receipt
verifier mocks and fixture inventories. This source addition is NOT a live
provider-issued signed inventory receipt, a full site diff, or a Staging release.
A separate exact native inventory-page issuer must be certified, pages must
be covered end-to-end, and live target readback/restore must be proven before
reconciliation can be marked completed or expanded into any write operation.
