# ETG Bounded Evidence Provider Contract v1

## Purpose

ETG remains the canonical owner of Dynamic Filter SEO Bridge domain evidence while a central MAD4B MCP / Control Plane owns transport, authentication, pagination orchestration, export and materialization.

This contract exists to prevent large `runtime-inventory` responses from becoming a transport or chat-context bottleneck without duplicating ETG diagnostic semantics inside the MCP layer.

## Provider identity

- Provider ID: `etg-dfsb`
- Contract: `etg.dfsb.evidence-provider.v1`
- `authorizing=false`
- `read_only=true`
- `profile_mutation=false`
- `transport_owned_by_provider=false`

The provider MUST NOT register its own REST/MCP transport, create approval tickets, mutate Profiles, alter Elementor/JetEngine/JetSmartFilters configuration, publish SEO state, or grant Production authority.

## Central registry hook

ETG registers itself through the WordPress filter:

`mad4b_mcp_evidence_providers`

The registry entry is keyed by `etg-dfsb` and exposes:

- `provider_id`
- `contract`
- `read_only`
- `authorizing`
- `profile_mutation`
- `descriptor_callback`
- `query_callback`

The central MCP / Control Plane is responsible for invoking these callbacks only after its own authentication, authorization, environment and request-boundary checks.

For local/plugin-native discovery, ETG also exposes the provider instance through:

`etg_dfsb_evidence_provider`

## Canonical source of truth

The provider does not reimplement diagnostic semantics. Its callbacks project the existing canonical sources:

- `RuntimeInventory::collect()`
- `InventoryReconciler::analyze()`
- configured `ProfileRegistry` state

The provider may cache those results only within the current request/provider instance.

## Bounded query sections

### `summary`

Returns compact Runtime Inventory, JetSmartFilters Diagnostic v2 and Elementor topology summary fields without returning full surface arrays.

### `unresolved_surfaces`

Parameters:

- `offset` optional, default `0`
- `limit` optional, default `25`, maximum `50`

Returns only candidate filter surfaces whose identity state is not `resolved`, together with source truncation and identity-resolution counts.

### `filters`

Parameters:

- `filter_ids` required; array or comma/space separated string
- maximum 50 unique positive IDs
- `offset` optional
- `limit` optional, maximum `50`

Returns matching bounded surface records plus matching definition-lifecycle issues and semantic-drift records. This section is intended for targeted evidence such as `15032`, `15033`, `15034`, `16084`, and `16086`.

### `profile_reconciliation`

Parameters:

- `profile_id` required
- `offset` optional
- `limit` optional, maximum `50`

Returns only reconciliation findings scoped to the requested profile, the bounded profile authority fields, matching runtime route bindings, and provider-group drift that actually belongs to that profile's configured route IDs.

This section must not attach unrelated global drift to a profile merely because it exists in the same site inventory.

### `provider_group_drift`

Parameters:

- `template_id` optional
- `node_id` optional
- `offset` optional
- `limit` optional, maximum `50`

Returns bounded global provider-group drift records, optionally narrowed to an exact Elementor template/node such as `44320` / `b417678`.

## Response envelope

Every query response uses:

```text
contract = etg.dfsb.evidence-provider.v1
provider_id = etg-dfsb
section = <requested section>
state = ok | invalid_request | provider_unavailable
authorizing = false
read_only = true
profile_mutation = false
snapshot_fingerprint = <Runtime Inventory fingerprint when available>
collected_at_gmt = <Runtime Inventory collection time when available>
errors = []
payload = {...}
```

Paged payloads include:

- `total`
- `offset`
- `limit`
- `returned`
- `has_more`
- `next_offset`
- `items`

The central transport may translate `next_offset` into an opaque cursor, but must not change the underlying ETG evidence or infer missing records.

## Fail-closed rules

- Unsupported section: `state=invalid_request` / `unsupported_section`.
- Missing `filter_ids`: `state=invalid_request` / `filter_ids_required`.
- Missing or unknown `profile_id`: `state=invalid_request` with the exact reason.
- Runtime Inventory callback unavailable/error: `state=provider_unavailable`.
- No query response authorizes mutation or acceptance by itself.
- Source truncation remains visible and must not be converted into evidence completeness.

## Transport boundary

The central MCP implementation may add:

- authentication and capability checks,
- environment binding,
- opaque cursors,
- response byte ceilings,
- streamed export,
- file materialization,
- provenance envelopes,
- audit logging.

Those capabilities belong to the central transport and MUST NOT cause ETG to duplicate or fork Runtime Inventory / reconciliation logic.
