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
