# Unified Capability Gateway v1

The gateway chooses discovery, schema transfer and exposure strategy per client and per operation while keeping WordPress Abilities as the single source of truth.

## Invariants

- Client capability claims are compatibility evidence only. They never grant authority.
- REST is selected for catalog/schema work only after the client has successfully reached the OAuth-protected REST gateway.
- MCP remains the governed execution transport.
- Clients that prove they can refresh a changing tool catalog may use explicit dynamic projection when the existing projection authority gates also pass.
- Direct projection has an independent serialized `tools/list` budget (96 KiB for tool DTOs). Schema chunking does not bypass this budget: an oversized optional Ability remains available through task discovery, preparation and the governed dispatcher instead of being forced into `tools/list`.
- Dynamic projection is currently an explicit site-enrollment projection, not a per-session catalog. Negotiation is per client; projection application is never implicit and does not claim per-client projection isolation.
- Other clients remain on the stable discovery/info/dispatch tool set.
- Search is metadata-only: it ranks bounded labels, descriptions, categories and declared metadata without loading schemas or making final execution/projection decisions.
- Preparation serializes only selected Ability schemas. Matching schema fingerprints are reused; large schemas are chunked with bounded adaptive size/parallelism recommendations.
- Every governed read/write/developer execution carries the prepared input-schema identity back to the existing dispatcher; read execution without an exact schema pin fails closed. The host refreshes discovery before execution. Direct execution additionally requires the wire schema/tool identity to remain unchanged.
- The gateway never invokes a target Ability directly.
- Unclassified/internal/unsupported lanes remain visible for diagnosis but fail closed for projection and execution.
- Projected Breakglass is structurally registered without consulting pre-auth request identity, but authenticated visibility and call admission require exact ChatGPT step-up plus `server:mad4b-breakglass` or the exact `ability:<name>` scope. Original NHI grant, runtime gate, approval and Ability permission checks still run.

The MCP specification exposes tools/list pagination and list-changed notifications, but the gateway does not infer that a client will consume either feature. Client behavior is negotiated explicitly and remains non-authorizing.

## Resource bounds

The host client defaults to at most 1,024 manifest pages, 10,000 aggregate manifest events, 32 MiB for one assembled schema and four parallel REST schema fetches. Parallelism is adaptive and bounded; MCP schema transfer remains sequential by default unless a future host contract proves safe concurrent tool calls. Hosts may lower these limits or explicitly raise them within the client hard ceilings. Server response budgets, retained-storage capacity, chunk sizes, preparation batch sizes, the 36-tool count ceiling and the 96 KiB direct-tool DTO ceiling remain separately bounded. WordPress 6.9 is exercised with the declared minimum PHP 7.4 runtime; the latest WordPress matrix lane runs on PHP 8.3.

## Stable execution and operational continuity

`read` uses `read-execute`; `write`, `content` and `admin` use `write-execute`; normal `developer` uses `developer-execute`. The original lane is retained and revalidated, not relabeled as write authority. Mutation targets must remain mounted on the dedicated write surface and explicitly declare `readonly=false`. Enrollment still resolves its explicit operation contract; internal and Breakglass lanes never enter a generic fixed dispatcher.

Gateway clients forward `expected_execution_lane` and `expected_classification_sha256` alongside the mandatory input-schema pin. These two additional pins are optional for existing schema-pinned clients; when supplied they fail closed on drift. Read clients without the mandatory input-schema pin must refresh tool definitions and prepare the target again; no permissive legacy fallback is provided.

Fixed dispatch is the primary execution path. Direct projection is an optional hot set, sharing site-level state. The pinned MCP Adapter advertises `tools.listChanged=false`; no notification delivery is claimed. A client may explicitly refresh `tools/list` or reconnect before direct use. A different session replacing the hot set does not invalidate a schema/classification-stable fixed-dispatch target. The 64-entry selection registry and 36-total-tool/96-KiB DTO limits describe different resources and are not interchangeable.

REST discovery/schema endpoints accept either a verified OAuth bearer or an authenticated WordPress cookie session with the core REST nonce checks. Remote clients default to bearer authentication with cookies omitted. An invalid Authorization header never falls back to cookie authority. The manifest declares both modes and `Cache-Control: private, no-store` plus `Vary: Authorization, Cookie` prevents shared-cache reuse.

Full snapshot builds use a nonblocking, connection-owned MySQL/MariaDB named lock per authority scope/site. Contention returns 409, missing lock support returns 503. Same-request recursion is also rejected. The default cooperative build budget is 5,000 abilities, 10 seconds and 32 MiB of serialized definitions; filters may lower these budgets or raise them within 10,000 abilities / 30 seconds / 64 MiB. A callback cannot be forcibly preempted in PHP; deadlines are checked between callbacks and before publication. Immutable publication retains its independent 30-second deadline and physical capacity checks. A failed build never publishes a partial snapshot. Redundant force refreshes of the same fingerprint within 30 seconds reuse the current build; definition drift still rebuilds immediately. Normal no-change flushes perform no physical aggregate scan. Actual publication retains physical SQL accounting, including crash-orphan objects, rather than trusting an unsafe incremental estimate.

Local Connection > Endpoints deep diagnostics expose revision, stored/effective/stale counts, indexed catalog bytes, capacity and next GC. Indexed bytes are not a physical-storage measurement. Diagnostics do not silently reset governed projection state or delete retained objects.

Deactivation clears only `mad4b_catalog_gc`, including per-site cron on network deactivation. Projection, catalog objects, grants, approvals and audit data remain intact. Reactivation schedules GC again; ordinary uninstall intentionally preserves retained data under the repository's existing explicit decommission policy. There is no automatic destructive uninstall hook.

Multisite supports fresh requests to each enrolled site. Changing blogs inside a request does not rebuild WordPress's global Ability registry; gateway, catalog preparation and fixed read/write/developer dispatch reject a switched blog and require a fresh request. Restoring the original blog restores these paths. This is an explicit boundary, not a claim of arbitrary cross-blog registry compatibility.

Providers may supply at most 24 `meta.mcp.search_aliases` strings, each bounded to 80 UTF-8 bytes, for Arabic/English synonyms. Aliases only affect metadata relevance; they never grant execution or load schemas. Legacy discovery matches and paginates metadata before inspecting the selected rows. Metadata search retains only its top K results.

## Regression gates

The MCP compatibility matrix executes behavioral gateway and lifecycle fixtures on PHP 7.4/8.3, and real WordPress 6.9/latest tests verify original content/admin lanes, independent database connections and cron retention. The local OAuth matrix proves cookie+nonce access, nonce denial and invalid-bearer fallback denial through independent HTTP requests. Existing tests retain schema/wire drift, projection CAS, immutable storage races, UTF-8, chunk transport, oversized-tool fallback and original permissions.

`gateway-mutation-guards.py` introduces ten representative regressions into isolated source copies and requires the behavioral tests to reject each one. This protects against a test suite that passes while no longer exercising its intended invariant.
