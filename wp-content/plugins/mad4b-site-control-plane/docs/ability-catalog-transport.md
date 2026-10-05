# Central ability catalog transport v2

All registered WordPress Abilities remain in the universe. Discovery, transfer, direct projection, and execution share Ability identity but have separate admission checks. Transfer never grants execution authority.

## Protocol and authentication

Authenticated GET endpoints under `/wp-json/mad4b/v1/ability-catalog/`:

| Endpoint | Purpose |
| --- | --- |
| `capabilities` | Contract, authority scope, supported transports and dispatch routes |
| `manifest` | Frozen cursor pagination, query filtering and paginated upsert/removal events |
| `schemas/{sha256}` | Complete source or official Adapter wire schema |
| `schemas/{sha256}/chunks/{index}` | Raw UTF-8 byte slices with integrity headers |

REST catalog routes use the existing `mad4b-chatgpt` protected resource audience and the same OAuth application. The OAuth bridge verifies the bearer before endpoint permissions. The host client defaults to `credentialMode: 'omit'`, so browser cookies are never sent implicitly; WordPress cookie authentication is available only when the caller explicitly opts into `same-origin` and still retains its nonce requirements. No public schema download, alternative bearer audience, new grant, or new execution endpoint is introduced.

Responses are private and `no-store`. Binary responses carry `X-MAD4B-Content-SHA256`, `X-MAD4B-Schema-SHA256` and `X-MAD4B-Chunk-Count`; the server emits raw bytes, without JSON string quoting. HEAD has no response body. The MCP discovery Ability supports explicit `capabilities`, `manifest`, `schema`, and base64 `chunk` actions as a compatible alternative.

The contract is `mad4b.ability-catalog-transport.v2`. Source schemas and wire schemas have independent SHA-256 identities. Wire schemas are built using the same official Adapter builder as direct tool materialization. JSON objects, including `{}`, retain their type. Nonserializable schemas fail independently of other entries. Chunk boundaries may divide UTF-8 code points; clients decode only after assembling and verifying all bytes.

## Storage, revisions and lifecycle

Private durable nonautoloaded WordPress options replace transient-only snapshots. Schemas are content-addressed and split into 32 KiB native blocks. A chunk reads its covering blocks, not the entire schema repeatedly. Shared schema objects have no public access path: every request must prove membership in a snapshot scoped to site binding, WordPress user/capabilities, OAuth subject/client and scopes.

New payloads are staged under unique option names and an atomic SQL compare-and-swap publishes the directory. Concurrent publishers retry from the latest directory. Failed publication deletes its own drafts. Superseded and expired payloads stay addressable for one hour after directory removal so overlapping readers can finish. Unchanged payload leases extend the existing immutable option. Collection uses persistent keyset pagination past live objects; crash drafts have a one-hour grace period, longer than the 30-second publication deadline. WordPress cron must run. Maintenance failure does not authorize data access.

Default snapshot lifetime is one hour and object retention is seven days. The default 128 MiB retained storage capacity is a configurable server resource limit, not schema validity or a per-Ability byte rejection. Capacity exhaustion returns an explicit unavailable error; it does not publish a partial success. Capacity accounting includes retired payloads and unreferenced crash drafts; concurrent publishers recheck physical storage before publication.

An unchanged manifest reuses its stored definitions and schema descriptors, avoiding per-Ability rewrites. It still scans current registered definitions to detect changes. Adapter/filter changes outside Ability metadata must update `mad4b_scp_catalog_wire_generation`. Snapshot cursors pin contract, query, delta base, authority scope and expiration. Removals and upserts share the page limit. Query deltas compare membership both before and after a change.

Manifest snapshots persist definitions only (`mad4b.catalog-definitions-only.v1`). Execution classification is observed anew for the returned page, including cursor continuations; building a two-item page does not inspect the entire provider universe. Classification failures or exhausted page budgets leave the affected row explicitly ineligible while preserving sibling definitions. Cached decisions in older, still-leased snapshots are ignored. Definition deltas do not represent changes in grants or provider readiness. Every execution still requires fresh single-target preparation and live dispatcher authorization.

## Host client integration

`client/ability-catalog-client.mjs` is a host adapter, not automatic native ChatGPT integration:

```js
import { createAbilityCatalogClient } from './client/ability-catalog-client.mjs';
const client = createAbilityCatalogClient({
  baseUrl: 'https://example.com/wp-json/mad4b/v1/ability-catalog/',
  headers: async () => ({ Authorization: `Bearer ${await tokenProvider()}` }),
  callTool: host.callTool,
  maxSchemaBytes: host.availableSchemaMemory,
});
const matches = await client.search('booking status');
const prepared = await client.prepare(['vendor/booking-status']);
const catalog = prepared.catalogs.get('vendor/booking-status');
const definition = await client.readSchema(catalog, 'vendor/booking-status');
```

Task search and preparation use the protected `/mad4b/v1/capability-gateway` endpoint, or the same MCP discovery tool with `gateway_action`. Task search reads bounded metadata only and does not load input/output schemas, schema digests, classification pins, or execution eligibility. Preparation performs those checks only for the selected Abilities. Full `sync` is an explicit catalog-mirror operation.

Configure REST only when the host supports it. Otherwise configure `callDiscover` and use MCP base64 transfer. The client never silently switches authenticated transports after denial. It rejects changed credential origins and redirects, validates the contract and authority scope, applies complete paginated revisions atomically, verifies each chunk and aggregate digest, and resumes only within the same authority/schema namespace. An expired schema lease is renewed with single-Ability preparation and accepted only if the same authority scope and exact schema digest still match; it does not rebuild the full catalog merely to continue one schema download.

Latency adjusts the chunk size for subsequent downloads within configurable bounds. A download pins its size to keep resume indices stable. REST schema downloads use bounded parallel windows: the client starts conservatively, respects the server recommendation and its own hard ceiling, raises concurrency only after fast successful windows, and reduces it after slow windows or transfer errors. Successful chunks remain resumable when another chunk in the same window fails. MCP chunk transfer stays sequential by default because concurrent MCP tool-call behavior is host-dependent. Transient transfer errors reduce the next transfer size and parallelism; they never retry a mutation. The default host limits are 1,024 manifest pages, 10,000 aggregate manifest events, 32 MiB per assembled schema and at most four parallel REST schema fetches; callers can choose stricter limits or explicitly raise them within hard client ceilings.

`execute` always prepares the single selected Ability, including when given a full synchronized catalog, requires the fresh server-issued preparation receipt and authority-scope digest, checks schema/classification/original-lane pins, and delegates to existing governed read/write/developer dispatchers. For `write`/`content`/`admin`, hosts may pass `approvalTicketId` and `contextReceipt` execution options; the SDK snapshots the receipt before asynchronous preparation, enforces a 64 KiB UTF-8 transport budget, maps the reviewed values to the reserved dispatcher envelope, and refuses to attach them to read, Developer or direct-tool execution. Enrollment additionally requires the existing operation registration and policy pins. Direct execution requires the host to confirm the exact tool name from its actual refreshed tool catalog; claiming MCP capabilities alone is insufficient. The SDK does not promote tools or change permissions automatically. Original dispatchers require the signed prepared identity and still recheck current authority scope, schema, classification, permissions, mounts, approval and original authority. Breakglass, internal and unsupported authority lanes require their explicit original routes.

## Authority and stale tool defense

Selected-Ability structural classification is centralized in `MAD4B_SCP_Ability_Contract_Inspector` and hashed with a canonical, versioned classification contract. This avoids false identity drift from associative key ordering while still changing the digest for semantic metadata/output-schema changes. Existing stored projection rows created with an older classification digest fail closed and require fresh planning.

Sensitive declared surfaces take precedence over the readonly annotation. Readonly describes side effects and cannot turn internal, developer or Breakglass authority into read authority. A mutation boundary is verified against the actual wrapper created by central Authorization, not a metadata claim.

Structural tool registration runs before bearer authentication and does not evaluate user-dependent Breakglass policy. Authenticated list filtering and call admission evaluate the current Breakglass policy; projected Breakglass additionally requires exact ChatGPT step-up plus `server:mad4b-breakglass` or the exact Ability scope. At tool materialization, diagnostics capture source schema/classification, exact wire DTO digest and actual execution/permission callbacks. List filtering and call admission reject drift even when a new projection registry happens to match the latest Ability definition. Required base tools retain their existing independent contracts.

Tests cover large/multibyte schemas, chunk aggregation, object fidelity, frozen pages, paginated removals, query membership, cursor tampering, user/site isolation, unchanged publication, writer/reader publication races, dependency leases and GC beyond 500 objects; SDK REST/MCP transport, structured 410 renewal, abort propagation, timeouts, oversize bodies, resume/integrity, authority/origin and governed dispatch; disposable WordPress/OAuth tests verify binary serving, missing/invalid tokens, wrong audience, sensitive readonly lanes, spoofed boundary, stale DTOs and authorized one-time Breakglass over independent HTTP requests. Native ChatGPT host compatibility must still be established separately from server CI.

## Failure bounds and compatibility

The client forwards AbortSignal through negotiation, paginated manifest sync, selected-target preparation, schema transfer and execution callbacks. It applies a configurable 15-second timeout to REST headers/body and host MCP callbacks, and reads REST streams within a byte limit (default 1 MiB for metadata, exact expected bytes for chunks). A stalled or oversized response fails without publishing a partial catalog. Per-schema limits bound assembled schema bytes, not total process heap: JSON parsing, decoded objects and parallel streams also consume memory. Host callbacks must honor the supplied signal to stop their underlying work.

The pinned Adapter converts WP_Error into text-only MCP errors. Catalog/gateway callbacks therefore emit a bounded `mad4b.catalog-error.v1` message containing only sanitized code, status and a generic message. The client preserves 410 for single-target lease renewal and preserves denials without changing transport. Production PHP supports 7.4; CI lints production files on PHP 7.4/8.3 and runs the disposable WordPress 6.9 OAuth/catalog/projection stack on PHP 7.4, with the latest WordPress lane on PHP 8.3. Failure tests cover cancellation during the final chunk, stalled headers/body, oversize streams, text-only MCP expiry and blocked execution. Independent loopback HTTP CI additionally boots WordPress for OAuth/projection/Breakglass requests.

## Direct projection budget

Catalog transport and direct MCP projection are deliberately separate resource problems. Source/wire schemas may be transferred in chunks without a small per-Ability rejection, but a direct MCP tool must be serialized into `tools/list` as one DTO. The direct projection preflight therefore enforces both the 36-tool ceiling and a 96 KiB aggregate serialized-tool ceiling. Required transport tools fail closed if they cannot fit; optional/dynamic tools are excluded deterministically and remain reachable through discovery, lazy preparation and the governed dispatcher.

A size exclusion does not grant authority. Classified, execution-eligible read Abilities use the governed read dispatcher independently of direct catalog membership. Other authority lanes retain their original explicit routes.

Read execution revalidates registered Ability classification and execution eligibility independently of fixed tools/list membership. Explicit readonly metadata, signed preparation receipt, exact authority scope, original lane/classification, the original permission callback, input validation and exact schema pin remain mandatory. Oversized classified read targets execute through mad4b/read-execute while their schemas remain chunked. MCP text is bounded before JSON parsing; structuredContent is checked against the same serialized budget after the host has allocated it. Header providers receive {signal} and run inside the request timeout; providers must honor the signal to stop underlying credential work.
