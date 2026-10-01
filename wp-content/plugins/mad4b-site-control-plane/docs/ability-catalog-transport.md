# Central ability catalog transport v1

The existing `mad4b/chatgpt-tool-projection-discover` Ability supports optional `transport_action`: `manifest`, `schema`, or `chunk`. Omitting it preserves the existing discovery contract. This does not increase the base tool count or grant execution authority.

## Client flow

1. Request `{ "transport_action": "manifest", "limit": 50 }`. Retain `snapshot`, schema digests and `next_cursor`.
2. Follow `next_cursor` with the same transport action. Signed cursors freeze snapshot, query, page size, authority context and expiration. Never merge pages from different snapshots.
3. Fetch a complete schema with `schema`, `snapshot`, `schema_sha256`; or fetch numbered chunks using `chunk`, the same references, `chunk_index` and `chunk_bytes`.
4. Base64-decode chunks, verify each `chunk_sha256`, concatenate raw bytes in index order, verify the aggregate `schema_sha256`, then parse UTF-8 JSON. UTF-8 characters may span chunks. Keep chunk size unchanged when resuming.
5. Refresh using `known_snapshot` to obtain changed entries and removed names. An expired delta base requires a full manifest. Apply removals idempotently; they repeat on delta pages.

Schemas are serialized completely, without a fixed byte rejection. Chunk size bounds response size only; they are not schema validity limits. Existing count-based projection limits remain separate from the transport universe. Standard MCP tools/list still receives complete inputSchema; custom chunks are not inserted into tool DTOs. Projection remains explicit through existing plan/apply.

## Storage and lifetime

One central transport implementation owns content-addressed schema objects and immutable manifest snapshots. WordPress transients use the configured object-cache backend, or the options database when none is configured. References are scoped to exact site binding, user capabilities, and step-up context. They are private; clients must not share cached content across identities.

Snapshots have a renewable retention window (default one hour; trusted server filter `mad4b_scp_catalog_snapshot_ttl`). Content digests are durable identities, not a promise of permanent storage. Expired or evicted objects require manifest refresh. Long-running clients retain verified objects locally and revalidate against fresh manifests. This intentionally does not create an unbounded permanent archive in WordPress.

Versioned contract `mad4b.ability-catalog-transport.v1` permits future backends and retention policies without changing client identity or projection permissions. First-page discovery captures the current registered universe; it is not a background registry service. `schema_bytes` and serialized-tool evidence measure actual size. No 64 KiB schema or 256 KiB catalog rejection is applied.

## Verification

Standalone runtime test covers schemas larger than 64 KiB, multi-byte chunk reconstruction, hash integrity, stable pages across universe changes, deltas, tampered cursors, identity/binding isolation and denied reads. CI additionally runs real WordPress projection and actual OAuth tools/call tests on the supported WordPress matrix.
