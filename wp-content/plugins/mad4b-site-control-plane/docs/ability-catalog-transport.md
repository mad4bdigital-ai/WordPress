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

Latency adjusts the chunk size for subsequent downloads within configurable bounds. A download pins its size to keep resume indices stable. REST schema downloads use bounded parallel windows: the client starts conservatively, respects the server recommendation and its own hard ceiling, raises concurrency only after fast successful windows, and reduces it after slow windows or transfer errors. Successful chunks remain resumable when another chunk in the same window fails. MCP chunk transfer stays sequential by default because concurrent MCP tool-call behavior is host-dependent. Transient transfer errors reduce the next transfer size and parallelism; they never retry a mutation. The default host limits are 1,024 manifest pages, 32 MiB per assembled schema and at most four parallel REST schema fetches; callers can choose stricter limits or explicitly raise them within hard client ceilings.

`execute` always prepares the single selected Ability, including when given a full synchronized catalog, checks schema/classification pins, and delegates to existing governed read/write/developer dispatchers. Enrollment additionally requires the existing operation registration and policy pins. Direct execution requires the host to confirm the exact tool name from its actual refreshed tool catalog; claiming MCP capabilities alone is insufficient. The SDK does not promote tools or change permissions automatically. Original dispatchers still check schema, permissions, mounts, approval and original authority. Breakglass, internal and unsupported authority lanes require their explicit original routes.

## Authority and stale tool defense

Sensitive declared surfaces take precedence over the readonly annotation. Readonly describes side effects and cannot turn internal, developer or Breakglass authority into read authority. A mutation boundary is verified against the actual wrapper created by central Authorization, not a metadata claim.

Structural tool registration runs before bearer authentication and does not evaluate user-dependent Breakglass policy. Authenticated list filtering and call admission evaluate the current Breakglass policy. At tool materialization, diagnostics capture source schema/classification, exact wire DTO digest and actual execution/permission callbacks. List filtering and call admission reject drift even when a new projection registry happens to match the latest Ability definition. Required base tools retain their existing independent contracts.

Tests cover large/multibyte schemas, chunk aggregation, object fidelity, frozen pages, paginated removals, query membership, cursor tampering, user/site isolation, unchanged publication, concurrent CAS and expiry cleanup; SDK REST/MCP transport, resume and corruption, origin/authority, expiry and governed dispatch; disposable WordPress/OAuth tests verify binary serving, missing/invalid tokens, wrong audience, sensitive readonly lanes, spoofed boundary and stale DTOs. Native ChatGPT host compatibility must be established separately from server CI.

## Failure bounds and compatibility

The client forwards AbortSignal to REST fetch and host MCP callbacks, applies a configurable 15-second timeout to headers and body consumption, and reads REST streams within a byte limit (default 1 MiB for metadata, exact expected bytes for chunks). A stalled or oversized response fails without publishing a partial catalog. Per-schema limits bound assembled schema bytes, not total process heap: JSON parsing, decoded objects and parallel streams also consume memory. Host callbacks must honor the supplied signal to stop their underlying work.

The pinned Adapter converts WP_Error into text-only MCP errors. Catalog/gateway callbacks therefore emit a bounded `mad4b.catalog-error.v1` message containing only sanitized code, status and a generic message. The client preserves 410 for single-target lease renewal and preserves denials without changing transport. Production PHP supports 7.4; CI lints production files and exercises storage and registration on PHP 7.4 and 8.3. Failure tests cover cancellation during the final chunk, stalled headers/body, oversize streams, text-only MCP expiry and blocked execution. Independent loopback HTTP CI additionally boots WordPress for each OAuth/projection request.
