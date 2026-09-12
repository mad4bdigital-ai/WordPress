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

For provider-group route attribution, the provider follows the same canonical rule as `InventoryReconcilerBindingTrait::routeProviderGroupDrift()`: a blocking drift belongs to a route when that route ID appears in the drift record's `expected_provider_query_ids`. The observed/misbound query ID by itself MUST NOT attach the drift to an unrelated Profile.

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

Filter IDs are deduplicated before the 50-ID ceiling is evaluated. Duplicate IDs do not consume the unique-ID budget. More than 50 unique positive IDs MUST fail closed with `state=invalid_request` / `filter_ids_limit_exceeded`; the provider MUST NOT silently truncate the requested ID set. Non-positive values are not rewritten into positive IDs.

Returns matching bounded surface records plus matching definition-lifecycle issues and semantic-drift records. This section is intended for targeted evidence such as `15032`, `15033`, `15034`, `16084`, and `16086`.

### `profile_reconciliation`

Parameters:

- `profile_id` required
- `offset` optional
- `limit` optional, maximum `50`

Returns only reconciliation findings scoped to the requested profile, the bounded profile authority fields, matching runtime route bindings, and provider-group drift that actually belongs to that profile's configured route IDs.

Provider-group drift attribution follows canonical expected-route semantics: only blocking drift whose `expected_provider_query_ids` intersects the Profile route IDs is returned in `route_provider_group_drift`. A wrong `observed_provider_query_id` remains visible in the returned evidence but is not itself used to assign the drift to a Profile.

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
- Missing or wholly invalid/non-positive `filter_ids`: `state=invalid_request` / `filter_ids_required`.
- More than 50 unique positive `filter_ids`: `state=invalid_request` / `filter_ids_limit_exceeded`.
- Missing or unknown `profile_id`: `state=invalid_request` with the exact reason.
- Runtime Inventory callback unavailable/error: `state=provider_unavailable` / `runtime_inventory_unavailable`.
- ProfileRegistry callback unavailable/error: `state=provider_unavailable` / `profile_registry_unavailable`.
- Inventory Reconciliation callback unavailable/error/empty result: `state=provider_unavailable` / `reconciliation_unavailable`.
- Provider/source failures MUST NOT be represented as `invalid_request`.
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

## Central MCP integration handshake

The central transport should discover providers by applying `mad4b_mcp_evidence_providers` to an empty registry after WordPress `plugins_loaded` has completed. It should reject duplicate provider IDs or registry entries whose declared invariants are not read-only/non-authorizing.

For `etg-dfsb`, discovery should first call `descriptor_callback` and require all of the following before exposing a public read surface:

```text
provider_id = etg-dfsb
contract = etg.dfsb.evidence-provider.v1
read_only = true
authorizing = false
profile_mutation = false
transport_owned_by_provider = false
```

The central transport may then invoke `query_callback` with the documented request arrays. Recommended Live Acceptance calls are:

```text
{section: summary}
{section: unresolved_surfaces, offset: 0, limit: 25}
{section: filters, filter_ids: [15032,15033,15034,16084,16086], offset: 0, limit: 50}
{section: profile_reconciliation, profile_id: tours, offset: 0, limit: 50}
{section: provider_group_drift, template_id: 44320, node_id: b417678, offset: 0, limit: 25}
```

The MCP layer should bind every response to the exact environment/site identity it authenticated before invoking the provider and should preserve ETG `snapshot_fingerprint` and `collected_at_gmt` unchanged. It may wrap these fields with stronger transport provenance, but it must not synthesize missing ETG evidence or reinterpret review-only evidence as blocking authority.
