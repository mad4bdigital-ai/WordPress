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

REST catalog routes use the existing `mad4b-chatgpt` protected resource audience and the same OAuth application. The OAuth bridge verifies the bearer before endpoint permissions. WordPress cookie authentication retains its nonce requirements. No public schema download, alternative bearer audience, new grant, or new execution endpoint is introduced.

Responses are private and `no-store`. Binary responses carry `X-MAD4B-Content-SHA256`, `X-MAD4B-Schema-SHA256` and `X-MAD4B-Chunk-Count`; the server emits raw bytes, without JSON string quoting. HEAD has no response body. The MCP discovery Ability supports explicit `capabilities`, `manifest`, `schema`, and base64 `chunk` actions as a compatible alternative.

The contract is `mad4b.ability-catalog-transport.v2`. Source schemas and wire schemas have independent SHA-256 identities. Wire schemas are built using the same official Adapter builder as direct tool materialization. JSON objects, including `{}`, retain their type. Nonserializable schemas fail independently of other entries. Chunk boundaries may divide UTF-8 code points; clients decode only after assembling and verifying all bytes.

## Storage, revisions and lifecycle

Private durable nonautoloaded WordPress options replace transient-only snapshots. Schemas are content-addressed and split into 32 KiB native blocks. A chunk reads its covering blocks, not the entire schema repeatedly. Shared schema objects have no public access path: every request must prove membership in a snapshot scoped to site binding, WordPress user/capabilities, OAuth subject/client and scopes.

New payloads are staged under unique option names and an atomic SQL compare-and-swap publishes the directory. Concurrent publishers retry from the latest directory. Failed publication deletes its own drafts. Expired payloads are collected hourly; crash drafts have a one-hour grace period, longer than the 30-second publication deadline. WordPress cron must run. Maintenance failure does not authorize data access.

Default snapshot lifetime is one hour and object retention is seven days. The default 128 MiB retained storage capacity is a configurable server resource limit, not schema validity or a per-Ability byte rejection. Capacity exhaustion returns an explicit unavailable error; it does not publish a partial success. Building a publication may temporarily use additional staging space.

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

Task search and preparation use the protected `/mad4b/v1/capability-gateway` endpoint, or the same MCP discovery tool with `gateway_action`. Preparation serializes only selected Abilities. Full `sync` is an explicit catalog-mirror operation.

Configure REST only when the host supports it. Otherwise configure `callDiscover` and use MCP base64 transfer. The client never silently switches authenticated transports after denial. It rejects changed credential origins and redirects, validates the contract and authority scope, applies complete paginated revisions atomically, verifies each chunk and aggregate digest, resumes only within the same authority/schema namespace, and renews expired REST snapshots only if the same Ability schema still exists.

Latency adjusts the chunk size for subsequent downloads within configurable bounds. A download pins its size to keep resume indices stable. Transient transfer errors reduce the next transfer size and return the saved state; they never retry a mutation. Download concurrency is deliberately sequential. The default host limits are 1,024 manifest pages and 32 MiB per assembled schema; callers can choose stricter limits or explicitly raise them within hard client ceilings.

`execute` refreshes discovery, checks schema/classification pins, and delegates to existing governed read/write/developer dispatchers. Enrollment additionally requires the existing operation registration and policy pins. Direct execution requires the host to confirm the exact tool name from its actual refreshed tool catalog; claiming MCP capabilities alone is insufficient. The SDK does not promote tools or change permissions automatically. Original dispatchers still check schema, permissions, mounts, approval and original authority. Breakglass, internal and unsupported authority lanes require their explicit original routes.

## Authority and stale tool defense

Sensitive declared surfaces take precedence over the readonly annotation. Readonly describes side effects and cannot turn internal, developer or Breakglass authority into read authority. A mutation boundary is verified against the actual wrapper created by central Authorization, not a metadata claim.

At tool materialization, diagnostics capture source schema/classification, exact wire DTO digest and actual execution/permission callbacks. List filtering and call admission reject drift even when a new projection registry happens to match the latest Ability definition. Required base tools retain their existing independent contracts.

Tests cover large/multibyte schemas, chunk aggregation, object fidelity, frozen pages, paginated removals, query membership, cursor tampering, user/site isolation, unchanged publication, concurrent CAS and expiry cleanup; SDK REST/MCP transport, resume and corruption, origin/authority, expiry and governed dispatch; disposable WordPress/OAuth tests verify binary serving, missing/invalid tokens, wrong audience, sensitive readonly lanes, spoofed boundary and stale DTOs. Native ChatGPT host compatibility must be established separately from server CI.
