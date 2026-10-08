# Universal Solution Discovery — runtime read slice (Feature 007)

Status: implemented source-only in PR #258; no live deployment or automatic execution certification.

## Purpose

Unknown / Unmapped plugins are normal. Search a **capability problem**, never just a preselected tool (SSH, a named plugin or a given vendor). The new registered read ability `mad4b/solution-discover` returns possible paths without provider-specific hardcoded plugin maps.

## Contract

Required: `expected_profile_digest`, `expected_runtime_generation`, `intent`.
Optional: `related_terms` (language/tool-neutral query expansions), `mode` (`match` or `inventory`), `offset`, `limit`, `external_hints` (untrusted source-class metadata). Every request is pinned to the live Context generation/restore epoch and refuses stale bindings.

The registry enumerates installed WordPress plugins by metadata (`get_plugins` when available; active-only option fallback otherwise), and registered WordPress Abilities via the core `wp_get_abilities()` API. It does NOT load unknown plugin code, call registered ability callbacks, read wp-config or secrets, scrape arbitrary endpoints, mutate plugin state or choose an executor.

`match` ranks deterministic lexical token overlap across goal + related terms and discovered names/descriptions; `inventory` pages through actual observed candidates, allowing human/agent semantic review when no lexical match exists. No claim of semantic embeddings, proof of plugin features or complete cross-platform discovery is made. `external_hints` cover known skills/Hostinger/MCP/SSH *as untrusted descriptions only*, not implicit mounted connectors.

Every candidate is `UNMAPPED_OR_UNVERIFIED`; `execution_allowed=false` always. A certified provider path requires a *separate* exact effect/scope/consent/host safety evaluation, current provider certification, governed plan + approval and execution readback. A candidate name that resembles a capability is NEVER an authority grant. Root file edits (e.g. wp-config.php), production/host writes and Breakglass are not available through discovery.

## Assistant usage

1. Before asking for SSH or another new credential, call `mad4b/solution-discover` with the desired operation as intent, including bounded alternate terms. If no match, call `mode=inventory` with pagination; extend discovery to connected services as explicit external hints if available.
2. Display candidates *and* coverage flags: plugin inventory may be active-only; external discovery is not guaranteed complete.
3. Compare candidates using the existing Assistant Convergence / provider certification tools; obtain exact native function/effect evidence. Never silently elevate plugin from "installed" to "can patch wp-config.php".
4. For a host-config task, prefer a governed host configuration channel with backup/readback. WP File Manager is a candidate only; file access must be independently established. No automation through unknown WordPress admin plugin endpoints.

## Acceptance

`php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-solution-discovery.php`
`php wp-content/plugins/mad4b-site-control-plane/tests/solution-discovery-runtime.php`

The test covers dynamic unmapped candidates, introspected Abilities, ambiguous hints, deterministic ranking, pagination, input injections, duplicate refusal, stale generation and absence of write authority.

Source tests are not staging/real browser/host or external provider certification. Re-run on the latest exact PR HEAD before any release claim.

The follow-up connector `mad4b/assistant-solution-discover` is a read-only adapter wrapping the existing Assistant Planning GAP decisions, described in `docs/ASSISTANT-SOLUTION-ROUTER.md`. Private WordPress Abilities (`show_in_rest=false`) remain completely absent from this general discovery response. Governed MAD4B private operations must be discovered through their separately authorized MCP catalog; the discovery result is NEVER a permission grant.

## Other WordPress capability surfaces (2026-10-09)

The live registry now explicitly reads **ordinary plugins, must-use plugins and core drop-ins**, as well as REST-visible WordPress Abilities, without vendor-specific lookup tables. WordPress supplies `get_plugins()`, `get_mu_plugins()`, and `get_dropins()` from its trusted core plugin admin helper. Must-use plugin presence is active by core semantics; drop-in **presence** is only `registered` (not automatically proven loaded or usable).

All metadata is treated as a non-executable hint. A failed function, malformed plugin main-file path, unsupported/inaccessible helper, or truncated inventory marks a corresponding `*_inventory_complete=false`; a no-match result on incomplete coverage returns `INVENTORY_INCOMPLETE_RETRY`. Offset pagination is available past the old 400-result boundary, subject to a hard 1024-result safety ceiling. Scanning never invokes the unknown provider code or grants a file/host write.

## Exact inventory pagination (continuation safety)

Supply `expected_snapshot_sha256` from the first response when reading subsequent pages of the same scope, goal and external hints. If a plugin version/status, environment binding, extension registry or hint changes, the server refuses with `mad4b_solution_discovery_snapshot_changed`. `snapshot_sha256` is an integrity continuity identifier, not provider certification and not a cryptographic authorization token. A lost snapshot requires restarting the inventory scan, not mixing pages.


## Reuse existing MAD4B discovery and certification systems

Feature 007 also includes `MAD4B_SCP_Capability_Atlas`, which composes WordPress plugin-family inventory with site/browser provider *capability claims*. **This is a different evidence layer**, not a competing authorized executor or a replacement for the metadata fallback:

| Layer | Existing facility | Proven meaning |
| --- | --- | --- |
| Installed-source observation | `mad4b/solution-discover` | Plugin, MU-plugin, drop-in, REST-visible Ability or bounded external hint exists; no capability certification |
| Assistant gap search | `mad4b/assistant-solution-discover` | Matched candidates and bounded unranked fallback for `Assistant_Planning` gaps; no selection or execution |
| Claimed capability graph | `mad4b/capability-atlas` | Plugin family/browser provider declares capability; every node remains unverified |
| Qualification | Existing G3 descriptors, signed declarative adapter manifests and shadow-read recipes | Exact independent effect/scope/compatibility checks; no implicit write authority |
| Runtime acceptance | Real provider-specific WordPress/host and external browser reducers | Separate trust-bound functional evidence required before any certification |

For cases such as WP File Manager, the generic source search may find an installed but unmapped plugin. A capability graph can add *review-only* context if a compatible provider declares its features. Neither metadata nor declaration can verify access to `wp-config.php`: approval, backup and independently checked host/file scope remain necessary.

The generic solution discovery must not reimplement browser CPT/taxonomy recognition, browser site-driver selection, provider behavior certification, or the G3 adapter registry. Those belong to their existing owners. When a client is connected to Skill/MCP/Hostinger catalogs, an authorized external collector may pass *safe non-secret descriptors* using `external_hints`; WordPress cannot list the user's ChatGPT connections itself. Such hints are never credentials, and are not proof that a connector is active.

Known limitations: metadata enumeration is source-bounded and may return partial coverage above registry limits; lexical matching is not semantic matching; the unranked fallback pool needs independent review and may require pagination; no auto-install or file manager admin-UI automation is authorized. Source-only tests do not certify Staging, PHP 7.4/8.3 or Production.
