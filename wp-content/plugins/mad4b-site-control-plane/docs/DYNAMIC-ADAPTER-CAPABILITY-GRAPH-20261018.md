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

## Owner-pinned External PHP Adapters and native MCP endpoints

An external PHP Adapter is **not the same thing** as a plugin's **existing native MCP endpoint**. Native MCP endpoints should be discovered and reviewed *before* duplicating their operations with custom PHP. The official WordPress MCP Adapter also exposes WordPress Abilities via its default/custom MCP servers; an Ability already registered locally must be reused through its existing WordPress authorization layer rather than blindly proxied back into the same site.

### External PHP Adapter admission

This PR now supports third-party PHP subclasses of `MAD4B_SCP_Adapter_Base` **without copying their source into MAD4B**:

- Operator supplies `MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_PATH` and `MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_SHA256` as protected, pre-plugin-bootstrap configuration constants, never through request inputs, WordPress options, filters, plugin-controlled paths or MCP tool payloads.
- The JSON manifest must live **outside WordPress content, plugin and MU-plugin directories**. The pinned SHA-256 must exactly match the whole manifest. The manifest is deployed independently of the external plugin, e.g. by the owner/operator in a filesystem location not writable by plugins.
- Each declaration binds the actual PHP class to an exact active plugin basename, exact relative PHP source file SHA-256, adapter id, exact declared read/content/admin/write ability names and exact reversible-contract map.
- The external plugin must already have loaded its own class; MAD4B never runs external files by scanning directories or accepting a class string supplied by a remote client. Registration is opt-in through the existing `mad4b_scp_register_adapters` hook.
- A change in the external plugin's code, declared abilities, expected plugin activation or reversible contract denies the registration until an operator independently reviews, pins and deploys a new manifest. A successful admission grants no OAuth scope, WordPress capability, approval ticket, write authority, provider certification, or MCP transport bypass.

Example operator-reviewed manifest (illustrative; the 64-hex values must be actual computed digests):

```json
{
  "contract": "mad4b.external-adapter-trust.v1",
  "adapters": [{
    "class": "MyVendor\\\\SiteAdapter",
    "adapter_id": "my-vendor",
    "plugin_basename": "my-vendor/my-vendor.php",
    "source_relpath": "my-vendor/includes/class-site-adapter.php",
    "source_sha256": "<actual sha256 of the php source>",
    "abilities": {"read": ["my-vendor/status"], "content": [], "admin": []},
    "reversible_contracts": {}
  }]
}
```

Owner controls the protected file and its pinned manifest digest, which allows adding/removing reviewed sources without edits to MAD4B PHP. Review explicitly **does not** turn an arbitrary third-party PHP extension into a reviewed native MCP endpoint.

### Native MCP endpoint acceptance: three cases

1. **WordPress-local Abilities + official MCP Adapter**: use the existing locally registered Ability and its `permission_callback` through the canonical governed wrapper. Do not make a same-site HTTP round trip or duplicate an adapter.
2. **Plugin-owned MCP endpoint on the WordPress origin**: surface the route as an observed candidate. After explicit endpoint identity verification, negotiate the supported MCP protocol: `server/discover` then `tools/list` on 2026-07-28-capable peers, with `initialize` fallback for older revisions (e.g. 2025-11-25); record tool schema, annotations, protocol version, auth audience, origin and deterministic fingerprint. For the official WordPress default MCP server, also inspect its layered `mcp-adapter-discover-abilities` and `mcp-adapter-get-ability-info` metadata rather than assuming three generic MCP tools equal all provider functions. An endpoint route or plugin filename alone is not certification.
3. **Provider-owned remote MCP service**: same review, plus operator-pinned HTTPS origin and path, provider-specific least-privilege OAuth, DNS/IP and redirect restrictions, independent rate limits and audit receipts. Never reuse the site's owner bearer token as a provider credential.

Provider selection is native-first only **after** positive independent trust/contract evidence. Until then the legacy provider MCP path isolation and foreign peer mutation guards remain intact, rather than creating an undocumented parallel Write plane.

Required MCP transport states: `DETECTED_UNVERIFIED`, `TRANSPORT_AUTHENTICATED`, `CATALOG_SNAPSHOT_REVIEWED`, `READ_CERTIFIED`, `WRITE_BEHAVIOR_CERTIFIED`, `GOVERNED_EXECUTION_ELIGIBLE`, with an explicit `SUSPENDED_ON_DRIFT` state whenever route, principal, schemas or code changes. PR #415 now implements bounded, operator-pinned MCP catalog negotiation and reviewed read-only execution (detailed below). These source-level capabilities are **not** independent ETG/ARE live MCP federation certification and grant no Write authority.

### Distinguish integration from authorization

A native MCP `tools/call` is an **execution API**, not an approval. Any bridge to it requires separately bound per-tool owner approval, exact target/arguments, verified provider subject, current-state CAS, local operation journal, bounded receipt, independent readback and a feasible restoration/compensation plan. If a native tool is inherently non-reversible, label and gate it as such instead of inventing rollback support.

This is specifically relevant to the already-declared transport descriptors for JetEngine, Fluent Forms, Elementor, ElementsKit, Hostinger AI Assistant, WP-Media and WordPress MCP Adapter default server. Descriptor presence indicates a reviewed *pattern* or isolation interest, **not** a confirmed live endpoint or functional tool list.

## Implemented native-MCP hybrid path in PR #415

Implementation: `includes/class-mad4b-scp-mcp-hybrid.php`. Registered read-only Abilities:

| Ability | Behavior |
| --- | --- |
| `mad4b/native-mcp-candidates` | Passive local provider route candidates, reviewed transport patterns, configured peer IDs; no external HTTP |
| `mad4b/native-mcp-catalog` | Owner-pinned HTTP peer: negotiate protocol, perform bounded paginated native `tools/list`, fingerprint schemas and compare each reviewed binding |
| `mad4b/native-mcp-hybrid-plan` | Select the eligible reviewed native read, otherwise a registered and reviewed local WP/adapter Ability per exact semantic capability; reports unavailable native reasons |
| `mad4b/native-mcp-read-call` | Actual owner-reviewed, explicitly read-only native `tools/call` with expected catalog fingerprint and existing MAD4B read permission callback |
| `mad4b/native-mcp-hybrid-read` | Dynamically dispatch exact read capability to reviewed native MCP or registered approved WordPress Ability through its standard `execute()` permission and schema validation, without retrying remote execution after a failure |

Modern `2026-07-28`: stateless `server/discover`, request `_meta`, `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name` headers. Legacy `2025-11-25` / `2025-06-18` / `2025-03-26`: `initialize` + `notifications/initialized`, session id and `tools/list`. Only explicit method/version incompatibility triggers downgrade. Tool lists are bounded by four pages/256 tools, JSON or bounded SSE, and refuse duplicate names, cursor loops, malformed result envelopes, HTTP redirects and unexpected versions. Each read executes in the same negotiated session as its exact catalog verification.

### How an owner configures peers

The operator provides two server-side constants **before WordPress plugins bootstrap**:

- `MAD4B_SCP_MCP_PEER_MANIFEST_PATH` — a trusted JSON file outside the WordPress content/plugin/MU directories, writable only by the operator
- `MAD4B_SCP_MCP_PEER_MANIFEST_SHA256` — 64-digit hex digest of **exact manifest bytes**
- Optional `MAD4B_SCP_MCP_PEER_TOKENS` — an array keyed by reviewed peer ID containing separately provisioned provider-scoped bearer tokens (never the MAD4B site owner bearer)

Manifest skeleton:

```json
{
  "contract": "mad4b.mcp-hybrid.v1",
  "peers": [{
    "id": "example-native",
    "endpoint": "https://reviewed-provider.example/mcp",
    "bindings": [
      {
        "ability": "example/inspect",
        "tool": "inspect",
        "dimension": "read",
        "schema_sha256": "actual-64-character-lowercase-sha256-of-canonical-inputSchema",
        "read_execution_reviewed": true
      }
    ]
  }]
}
```

Replace all examples with independently verified details. This deliberately does not scrape tokens, infer a URL from a plugin name, or grant external network access to a discovered unknown endpoint. New peers and bindings require an operator manifest repin, but do **not** require modifications to MAD4B PHP. The provider's MCP server is used directly when certified; MAD4B never installs a second implementation of its existing tools.

### Hybrid selection semantics

- A listed tool without a matching **exact owner-pinned inputSchema SHA-256** is `MCP_SCHEMA_DRIFT`, not operational.
- A tool marked read in a provider's self-description is not executable until **operator reviewed** `read_execution_reviewed=true` and live `annotations.readOnlyHint=true`, without a destructive hint.
- When native read is missing, unreviewed, not read-only, a protocol cannot be negotiated, or no peer was configured, a separately source-reviewed and currently registered local WordPress Ability/Adapter may be selected for the **same** requested ability. Never attempt a fallback by guessing a similar name.
- Native Write tools are **candidates only**. No direct `tools/call` for mutation is exposed. Reviewed existing local Write/Undo paths continue to require live certification, owner approval, write grants, operation receipts and state-safe rollback. A native Write implementation needs an equivalent provider-specific before/after/restore contract, not merely a tool schema.
- Read call responses that report error or interactive input-required cannot be automatically retried or silently reclassified as success.
- For native hybrid reads, the caller must acknowledge the exact current catalog SHA-256 before invocation. Local fallback can execute without a native peer: it must have a separate registered approved Adapter, and WordPress `WP_Ability::execute()` must still validate the input and enforce its own permission callback.
- The MCP hybrid execution wrapper **never retries** a failed native call as a local call: unknown partial side effects must not be duplicated.
- Unknown endpoints stay visible as candidates, not executable. Existing peer isolation/fail-closed Write governance is not bypassed.

### CI and release proof

`tests/dynamic-mcp-hybrid-runtime.php` exercises mocked **HTTP-only** modern and legacy protocols, cursor pagination, SSE, catalog drift, genuine read call, denial of native Write, unpinned local fallback, and passive candidate discovery. The adapter coverage and Pre-Staging workflows run the fixture plus PHP lint. These tests do **not** replace real authenticated ETG/ARE Staging canaries or Production certification.
