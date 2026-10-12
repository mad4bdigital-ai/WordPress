# Unified capability discovery and dependency-scoped tool visibility

Status: **source-only phase 1**, PR #258. Not a release, Staging deployment certificate, or authorization change.

## Problem and decision

The WordPress Ability registry is much larger than ChatGPT's compact MCP `tools/list` budget. Attempting to expose every Ability directly makes the connector unreliable and crowds out essential dispatch/repair tools. **Do not remove transport byte, time or tool limits** to make the catalog appear unlimited.

Use stable, always-present **discovery / preparation / execution dispatch** tools for correctness; show individual direct tools only as an optional, bounded, explicitly approved hot set when the MCP host can refresh definitions. A capability can be available and executable without being a dedicated direct tool.

Logical search can traverse the entire authorized registry over multiple requests. Every request remains bounded. Tool projection is visibility, never permission.

## Phase 1 included in source

- `MAD4B_SCP_Unified_Capability_Gateway::search` searches registered WordPress Ability **metadata** without loading each schema or evaluating live authorization.
- Filters: `category`, `surface`, `ability_prefix`, `declared_readonly_only`, `declared_writeonly_only`, `match_mode=any|all`, `sort=relevance|name`, `min_score`, and explicit `browse=true` for an unfiltered metadata listing.
- Each page contains at most 25 matches, with `total_match_count`, `has_more`, `next_cursor`, `page_offset` and `search_snapshot_sha256`. No fixed ceiling applies to **total result count** returned across pages.
- HMAC-bound 10-minute cursor binds to search/filter digest, WordPress blog/user, Site Profile, build provenance and observed result fingerprint. Tampering, expiry, query drift, user/site drift and modified results reject continuation. Restart discovery; never turn a stale cursor into a write decision.
- MCP `mad4b/chatgpt-tool-projection-discover` forwards these facets through its existing `gateway_action=search` route. This is a **metadata** route, not projection apply.
- `client/ability-catalog-client.mjs` exposes async iterators `searchPages` and `searchItems` to consume cursors without building an unbounded client array. Schema transfer still uses the existing bounded snapshot/chunk transport; exact execution uses the existing prepared receipt and dispatcher.

The pagination cursor is scoped to the underlying site-user identity, not a globally usable grant. `declared_readonly` means **declared metadata**, not confirmed read permission. `writeonly` filters **declaration** rather than granting mutation rights.

## Phase 2 — federated indexing (NOT YET IMPLEMENTED)

The unified search must eventually consume **multiple authorized registries** behind normalized read-only source adapters:

1. WordPress Abilities / Adapter Registry / provider capabilities
2. Operation Registry and Remote Operation Parity
3. Managed Skills with explicit certification and freshness
4. Context, Content Jobs, SEO, Media, Workflow/Approval lifecycle descriptors
5. Staging-only host runner / source delivery alternatives, after separately verified enrollment
6. External app/connector catalogs only through host-authorized **federation**, never by claiming WordPress can enumerate ChatGPT apps or Hostinger SSH credentials

Deduplicate by site + source + immutable identity, not by a guessed display name. A missing or rejected provider must be displayed as `INCOMPLETE`, not silently converted into “no results.”

Provide facets at the **appropriate evidence stage**:
- Discovery: source, provider, capability family, type/lane, site, environment, stable labels, lexical query, declared read/write, provenance and availability.
- Preparation: actual ability schema, execution lane, authorization scope, risk, dependencies, missing grants, readiness and blockers, all from governed server-side verification.
- Execution: only via the already authorized dispatcher, with exact schema/classification/scope digests and one-time approvals where applicable.
- Recovery: independent readback / rollback receipts. Failure or timeout is not success.

The existing `Progressive_Operation_Discovery::MAX_OPERATIONS=2048` and remote catalog limits remain **independent catalog ceiling risks**. Replace the full-array inventory with source-indexed, lazy paginated descriptors before claiming unbounded multi-registry coverage. A single large array with a cursor on its final result is not truly streaming.

## Dynamic dependency-based visibility

A task should move through: intent -> filtered discovery -> relevant dependencies -> single Ability schema preparation -> governed dispatch -> readback. Only a small, optional set of frequently used direct tools should be suggested for `tools/list`. The **server** cannot force a ChatGPT client to refresh its cached tool declarations. Require an observed refresh/reconnect before relying on any new direct tool.

The current projection is site-global (`isolation_scope=site_global`), uses optimistic CAS, and is subject to the 36-tool budget. **Do not automatically rewrite the site-global projection per task**: multiple sessions could evict each other's tools. Do not implement connection/session-varying `tools/list` as an incidental effect of ordinary search: the MCP 2026-07-28 specification requires a stable set for the request's authorization context and does not permit per-connection variation. An auth-scoped projection may be considered only if the server and host reliably bind it to per-request credentials, deterministically order/paginate results, and support explicit tool-list refresh. Continue to use fixed dispatch otherwise.

Do not demote or silently remove core dispatcher tools to free optional slots.

## Safety boundaries

- No permissions follow automatically from capability discovery, search ranking, display, scope hints or tool projection.
- No raw SQL, Developer Breakglass or Production mutation exposure changes.
- Do not disable schema / authority / approval / site binding / release certification to solve reachability.
- Token, nonce and SSH key values never enter capability labels, cursors or search output.
- Retrieval limits, rate budgets, timeouts, memory ceilings and response size limits remain mandatory. “Unlimited” means eventual coverage by cursor, **not** unbounded work per request.
- No automatic publishing. Draft and publish use separate governed transitions and postcondition readback.

## Acceptance evidence required

1. Native PHP 7.4/8.3 syntactic and fixture tests; Python contract assertions and Node client syntax/runtime tests.
2. More than 25 matching Ability descriptors over multiple pages, no loss/duplicates; metadata facets and Arabic query normalization.
3. Reject forged, expired, replayed-cross-user/site, query-drifted and changed-snapshot cursors.
4. Verify read-only and mutation filters do not turn missing/unknown annotations into permission.
5. Verify cancelled searches, network failures and changed head leave no partial writes or false completion.
6. Schema chunks verified by SHA-256; prepare receipts and dispatcher authority revalidated immediately before execution.
7. Concurrent ChatGPT clients still find fixed dispatchers and do not unintentionally change site-global projection.
8. Federation acceptance separately demonstrates complete provider coverage and distinguishes unauthorized/unavailable/incomplete sources.
9. Source ownership, G9 manifests, signed candidate build and **actual** Staging E2E acceptance are updated for the exact new HEAD.

Current code covers only Phase 1 WordPress Ability search and MCP/client forwarding. Other source catalogs and ephemeral projection isolation remain backlog; this document does not certify them.

## Implemented integrated extension (source-only, October 12, 2026)

- `class-mad4b-scp-capability-search-index.php` materializes a short-lived (60s) **authority-scope-bound** WordPress Ability metadata index keyed by the sorted registration-name fingerprint. Query result sets containing more than 25 matches can reuse a cached ranking across Cursor pages. The cache is size-bounded (1 MiB/record). A missing persistent cache degrades performance, not permissions. New registration names immediately invalidate the name-set key; changing metadata without changing names may remain visible for **up to the 60-second index lease**. Actual execution still revalidates live schema/authority.
- Search cursor identity uses the same `MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope()` as the canonical catalog, including OAuth issuer/client/token scopes and current WordPress caps. The cursor remains non-authorizing and HMAC-bound.
- The existing `Unified_Capability_Gateway` now offers `action=dependencies` for exact operations from `Operation_Registry`, using the canonical `pipeline_profile` and `stage_binding` definitions. It **does not** generate new operations, infer approvals, execute a plan or evaluate conditional stages; unknown/plan-bound stages are explicitly unresolved. The existing `Adaptive_Operation_Router` includes that graph in explicit operation planning, not in implicit natural-language execution.
- `action=sources` returns a conservative coverage census for WordPress Abilities, canonical operations, managed skills and remote-operation parity, plus a deliberately unavailable external-federation source. Rejected/truncated/unavailable providers are marked incomplete and do not become eligible executors. **This is source discovery, not finished cross-source federated search.**
- MCP forwards both read-only actions through the existing discovery tool. The JS client exposes `sources()`, `dependencies()` and existing `searchPages()/searchItems()`.
- No DB schema migration, new write authority, client-specific mutable `tools/list` set, Production exposure or automatic publishing.

### Current performance and safety limits

This phase improves repeated pagination for the currently modest WordPress Ability universe, but its one-time metadata build still scans all WordPress Abilities and checks the sorted-name fingerprint at each request. **Do not advertise true unbounded server streaming**. For very large registries, a durable, source-generation-aware inverted index with incremental deltas and provider-specific cursors is still required. Query caching for large result sets may create transients under high query cardinality; add telemetry, rate limits and bounded eviction before broad public use.

`Skill_Registry::MAX_SCAN_SKILLS`, `Progressive_Operation_Discovery::MAX_OPERATIONS`, and the 500-entry remote catalog filter are **not removed** by these changes. External federation requires independently authenticated connector access and catalog completeness attestations.

### Native test enrollment (not a PASS claim)

`feature007-manual-preflight.py` now includes:
- `unified-capability-pagination-runtime.php` (indexed paging, canonical OAuth scope, cache reuse and rejection paths)
- `unified-capability-dependency-runtime.php` (canonical graph/missing abilities/approvals)
- `unified-capability-sources-runtime.php` (partial/truncated/external unknown coverage)
- `unified-capability-gateway-contract.py` (cross-file static binding invariants)

Do not call the implementation accepted until exact-head native PHP/Node tests, CI/independent signed release evidence and **real** Staging MCP/REST acceptance succeed. A GitHub commit or static fixture presence is not an acceptance receipt.
