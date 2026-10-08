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

The follow-up connector `mad4b/assistant-solution-discover` is a read-only adapter wrapping the existing Assistant Planning GAP decisions, described in `docs/ASSISTANT-SOLUTION-ROUTER.md`. Private Abilities appear to the admin only as opaque canonical names (their descriptions are redacted); the discovery result is NEVER a permission grant.
