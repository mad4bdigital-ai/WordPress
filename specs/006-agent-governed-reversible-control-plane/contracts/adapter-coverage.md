# Adapter Coverage & Reversible Provider Contract

Contract: `mad4b.adapter-coverage.v1`

This contract extends the governed control plane without creating a second mutation authority. Plugin discovery is read-only and advisory. It may identify missing support, but it must never install a plugin, generate executable adapter code, create an NHI/grant/approval, or enable mutation.

## Two inventories

1. **Repository package inventory** — every `wp-content/plugins/*.zip` is classified by the repository CI contract. A package that has no known family falls back to `adapter_required`; it is never silently omitted.
2. **Runtime installed-plugin inventory** — WordPress `get_plugins()` plus active/network-active state is mapped to registered MAD4B adapters, provider certification, reversible contracts, risk, and runtime blockers.

Runtime read abilities:

- `mad4b/plugin-adapter-coverage`
- `mad4b/adapter-support-requests`

Both are non-public, read-only abilities on the governed read surface. Support request IDs are deterministic evidence only; `network_request_sent=false`, `authority_created=false`, and `normal_write_allowed=false`.

## Coverage states

- `supported_reversible` — active adapter, exact reversible contract, and required provider certification are valid with no attributed native MCP side-channel blocker.
- `supported_governed` — governed adapter support exists, but no certified reversible writer is claimed.
- `read_only_supported` — only read support is present.
- `adapter_registered_inactive` — adapter exists but the provider is inactive/unavailable.
- `adapter_present_certification_required` — adapter implementation exists but exact runtime provider certification is absent/drifted.
- `adapter_present_side_channel_blocked` — adapter/provider are present, but a provider-native parallel MCP write plane is detected and must be isolated before normal MAD4B mutation.
- `adapter_required` — plugin is known or discovered but no registered adapter covers its plugin-specific writer.
- `excluded_high_risk` — normal adapter writer is intentionally forbidden; only read-only inventory or a separately reviewed exceptional recovery design may be considered.
- `priority_external_missing` — a first-class external provider is not installed on the current target.

Unknown plugin write default is always **DENY**.

## First-class external providers

WooCommerce and Polylang are first-class coverage targets even when their ZIP archives are not committed to this repository.

### WooCommerce

The current reversible implementation is limited to bounded **product fields only**. Orders, payments, refunds, credentials, and unrestricted commerce internals are outside the normal writer contract.

Rollback contract: `mad4b.rollback.woocommerce-product.v1`.

Runtime provider certification remains mandatory before write or restore.

### Polylang

The current reversible implementation covers bounded post-language assignment.

Rollback contract: `mad4b.rollback.polylang-post-language.v1`.

A previously unassigned post is not claimed reversible until a provider-safe unassignment restore contract is certified. Runtime provider certification remains mandatory.

## Generic reversible adapter envelope

Adapter mutations may opt in only by declaring an exact named restore contract. The durable envelope contract is:

`mad4b.rollback.adapter.v1`

The sequence is:

`capture before-state → persist mutation envelope → provider write → provider readback → after-state fingerprint → exact approved undo → drift check → provider-safe restore → restore readback → child recovery evidence`.

Rollback payloads contain bounded data only. Executable callbacks, class names to invoke, arbitrary PHP, or other executable instructions are never persisted as rollback authority.

External providers are re-certified at undo time. Version/hash/runtime drift blocks restore before provider mutation.

## Current reversible implementations

- WordPress Media metadata — `mad4b.rollback.media-metadata.v1`
- WordPress featured image — `mad4b.rollback.featured-image.v1`
- Rank Math allowlisted post meta — `mad4b.rollback.rank-math-meta.v1`
- WooCommerce bounded product fields — `mad4b.rollback.woocommerce-product.v1`
- Polylang post language — `mad4b.rollback.polylang-post-language.v1`
- JetEngine explicitly allowlisted post meta — `mad4b.rollback.jetengine-post-meta.v1`

Implementation presence is not equivalent to runtime mutation readiness. Provider certification and all global governance gates still apply.

## JetEngine native MCP boundary

The certified JetEngine 3.8.11.2 package contains a provider-native MCP plane in namespace `jet-engine/v1`, including MCP tool routes/protocol behavior. When the runtime peer inventory attributes a foreign MCP route in that namespace, C1 requires normal MAD4B mutation to fail closed.

The runtime coverage state must then be `adapter_present_side_channel_blocked`, with support reason `parallel_mcp_write_plane_requires_isolation` and requested contract `native_mcp_isolation`.

The control plane must not bypass `MAD4B_SCP_MCP_Peer_Governance` merely to make the JetEngine adapter writer executable. A future success path requires a provider-native, exact-version-certified way to disable or isolate the parallel mutation plane, followed by runtime proof.

## High-risk exclusions

Code execution/file-manager/role/database-rewrite style plugins such as Code Snippets and Better Search Replace are not automatically promoted into ordinary adapter writers. Discovery classifies configured high-risk families as `excluded_high_risk`.

## CI evidence

`MAD4B Adapter Coverage` must prove:

- all repository plugin ZIPs receive a strategy;
- unknown packages default to `adapter_required`;
- WooCommerce and Polylang remain priority external targets;
- unknown active runtime plugins create deterministic support requests without authority or network side effects;
- high-risk fixtures remain excluded;
- generic reversible Media mutation/undo/drift denial works on WordPress 6.9 and latest;
- exact packaged JetEngine is certified while its provider-native MCP parallel authority causes fail-closed mutation with approval and provider state left untouched.

This contract does not authorize Production deployment or mutation. T103 real Staging remains a separate mandatory boundary.
