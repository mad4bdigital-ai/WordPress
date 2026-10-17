# MAD4B dynamic adapters — review and acceptance

## Architectural contract
- Inventory all regular, network-activated, must-use, and drop-in WordPress plugins via WordPress APIs and current runtime registry.
- No plugin/vendor/version/namespace constants in the *generic discovery engine*. Family-specific adapters remain typed and source-reviewed.
- Distinguish **discovered** (installed package/registered names), **matched** (reviewed adapter contract), **available** (active and corresponding ability registered), **certified** (provider behavioral compatibility/rollback), **authorized** (exact site-specific grants, OAuth, owner consent) and **executed** (journaled governed action). None imply the next.
- `mad4b/plugin-capability-graph` is a read-only observation surface. Namespace-derived abilities are unreviewed hints only; the graph does not invoke callbacks.
- Registration is derived from classes already loaded by the bundled plugin, checked against their exact file origin. Separate reviewed JSON policy preserves the previous 18 adapter registrations. An unreviewed class can be listed as a candidate but cannot become an operational adapter automatically.
- No hardcoded version equals compatibility. Use provider/ability behavior, runtime readback, context contract and reversible canary to certify exact changes.

## Matrix
| Source | Discovery | Generic read path | New write authorization |
| --- | --- | --- | --- |
| Installed active normal plugin | Plugin headers + runtime ability names | Registered reviewed adapter reads and existing guarded WordPress abilities | Never by discovery |
| Inactive normal plugin | Plugin header only | Blocked until activated through independent owner operation | Never by discovery |
| Network-active plugin | Plugin inventory + active state | Same governed read gates | Never by discovery |
| Must-use plugin | WordPress MU inventory | Inventory-only | Never by discovery |
| WordPress drop-in | WordPress drop-in inventory | Inventory-only | Never by discovery |
| Unknown/new vendor | Namespace candidate and dependencies | Inventory plus documented candidate contract; no arbitrary invocation | Typed reviewed adapter + ability certification + owner grant only |
| Existing certified adapter | Exact declared registered reads | Current MCP governed read execution; permissions checked per ability | One-time approval, CAS, rollback, audit and site policy remain unchanged |

## Regression acceptance
- PHP fixture: `tests/dynamic-adapter-capability-graph-runtime.php` — new vendor appears without source changes, declared dependencies, inactive/missing dependency state, ability namespace proposal, drift fingerprint and incomplete scan handling, MU/drop-in isolation.
- PHP fixture: `tests/dynamic-adapter-registration-policy-runtime.php` — exact class origin, reviewed manifest, no external spoofing, no implicit adapter widening/duplicate overrides.
- Both fixtures execute from Feature 007 Pre-Staging Hybrid Audit. CI success is not a live host/provider certificate.
- ETG and All Royal Egypt Staging still require independently collected read-only package/ability graph snapshots after installing a build, exact provider canary/rollback receipts before write enablement, and separate owner review.
- Issue #409 MCP opaque transport errors are not resolved by source-level adapter discovery.
- Issue #413 Owner Intent and Elementor CSS runtime repair are separate; PR #414 owns code-level parity/CSS diagnostics.

## Explicitly excluded
No activation/deactivation, file changes, schema mutations, arbitrary REST invocation, imported vendor code, auto-authority, Developer, Breakglass or Production modifications.

## Governed functional route planning

The new `mad4b/plugin-capability-route-plan` read ability takes an exact `plugin_file`, a `dimension` (`inventory`, `read`, `write`, `rollback`), an optional exact declared `ability`, and optional `expected_graph_sha256`. It returns the relevant existing governed dispatcher and its **remaining** security gates. It never executes the callback, grants access, or considers a capability certificate to be an owner approval.

- Inventory: regular plugins, network-active plugins, MU plugins, and drop-ins; special packages are inventory-only.
- Read: exact declared and registered read ability, active dependency graph, available adapter source, stable snapshot and per-ability WordPress/OAuth permission checks.
- Write: exact reviewed registered write ability, eligible governed adapter state, provider behavioral certification, no unreviewed parallel MCP path, then existing write execution requires step-up OAuth, exact grant, owner one-time approval, CAS, audit and rollback.
- Rollback: reviewed reversible contract for the original operation, existing mutation journal/receipt, exact-before-state, current-state CAS, independent owner approval and verified compensation. This planner cannot undo without the existing recorded transaction and authorized dispatcher.
- Unknown plugin: safe inventory and namespace candidates. No guessed callbacks, vendor routes, version checks, provider auth or write path.
- Capability graph hash mismatch, partial scans, missing dependencies, inactive runtime, provider drift, unsafe parallel MCP, unreviewed ability and plugin class identity collisions all deny the operational plan with explicit reason codes.

### Source approval and legacy hooks

Existing 18 default built-in adapters are listed in `default_classes` and loaded without PHP class list hardcoding. Existing first-party hook-based adapters (ETG, Native Provider Bridge, Acceptance Target, provider canary, runtime components, diagnostics, repository families, etc.) are separately source-reviewed in `approved_classes`. Reflection verifies the loaded class's real file is inside the bundled adapter directory before *any* registration. The reviewed names are cached independently from class-load timing. New third-party adapters need a separately reviewed typed source binding, not just an `Adapter_Base` subclass or a WordPress action.

The one inert CI failure-evidence adapter is policy-reviewed and housed inside the bundle but is **not loaded by regular runtime bootstrap** and registers **no public Ability**. It is used exclusively to preserve the real governed mutation-failure/undo regression.

### Explicit release restrictions

Do not infer `release_ready` from this source-level capability matrix. All new-head CI and WordPress runtime E2E must pass. ETG/ARE Staging require actual live read graph evidence, behaviorally certified Elementor/JetSmartFilters and other active providers, reversible canary and owner-signed exact plan. Production stays unchanged.
