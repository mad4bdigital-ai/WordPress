# Adapter Coverage & Reversible Provider Contract

Contract: `mad4b.adapter-coverage.v1`

This contract extends the governed control plane without creating a second mutation authority. Discovery is read-only and advisory. It may identify missing support or runtime components, but it must never install a plugin/theme, generate executable adapter code, create an NHI/grant/approval, enable mutation, rewrite WordPress Core, or replace bootstrap-critical files.

## Repository package inventory and runtime inventories

1. **Repository plugin package inventory** — every `wp-content/plugins/*.zip` is classified by the repository CI contract. A package that has no known family falls back to `adapter_required`; it is never silently omitted.
2. **Runtime installed-plugin inventory** — WordPress `get_plugins()` plus active/network-active state is mapped to registered MAD4B adapters, provider certification, reversible contracts, risk, and runtime blockers.
3. **Repository runtime-component inventory** — WordPress Core markers, repository themes, MU-plugin packages, and recognized repository drop-ins are fail-closed against `mad4b.repository-runtime-components.v1`.
4. **Runtime component inventory** — WordPress Core, regular plugins, MU plugins, bootstrap drop-ins, themes, child themes, Astra, and Astra Child are discovered from the running WordPress installation without requiring repository presence.

Runtime read abilities include:

- `mad4b/plugin-adapter-coverage`
- `mad4b/adapter-support-requests`
- `wordpress-core/status`
- `mu-plugins/inventory`
- `drop-ins/inventory`
- `themes/inventory`
- `themes/get-theme`
- `astra-theme/status`
- `astra-child-theme/inventory`
- `runtime-components/inventory`

All discovery abilities above are non-public and read-only on `mad4b-read`. They must not be mounted on `mad4b-content`, `mad4b-write`, `mad4b-admin`, or `mad4b-breakglass`. Support request IDs are deterministic evidence only; `network_request_sent=false`, `authority_created=false`, and `normal_write_allowed=false`.

## Runtime component lifecycle classes

The runtime component registry distinguishes these classes explicitly:

- `wordpress_core`
- `regular_plugin`
- `mu_plugin`
- `drop_in`
- `theme`
- `child_theme`

This distinction is normative. MU plugins and drop-ins are not treated as ordinary activate/deactivate plugins, and WordPress Core is not treated as a plugin provider.

### WordPress Core

`wordpress-core/status` may expose local runtime facts, environment policy flags, and already-cached Core update evidence. It must not perform a remote checksum lookup, update Core, write Core files, or imply checksum verification that was not actually performed.

Core mutation is outside normal `mad4b-write` authority.

### Must-Use plugins

`mu-plugins/inventory` uses WordPress runtime discovery and preserves the lifecycle statement:

`must_use_always_loaded_no_activation_toggle`

MU plugin file replacement/deletion is bootstrap-critical and is outside normal `mad4b-write` authority.

### WordPress drop-ins

`drop-ins/inventory` treats recognized WordPress bootstrap files such as `object-cache.php`, `advanced-cache.php`, `db.php`, and related drop-ins as a separate lifecycle class. It may report bounded runtime signals, but it must not install, replace, delete, or toggle drop-ins.

Drop-in mutation is bootstrap-critical and is outside normal `mad4b-write` authority.

### Themes

`themes/inventory` and `themes/get-theme` resolve runtime parent/child topology, active stylesheet/template state, repository tracking, versions, and WordPress theme errors. Repository presence is not required for a runtime theme to be visible.

Generic theme file or Customizer mutation is not automatically authorized by theme discovery.

### Astra and Astra Child

Astra receives a specialized runtime adapter even when Astra is installed outside the repository:

- adapter `astra-theme`
- runtime stylesheet `astra`

Any installed theme declaring `Template: astra` is classified by the specialized `astra-child-theme` adapter.

The Astra adapter may expose version, active parent/stylesheet state, child-theme relationships, Astra Addon runtime presence, and a bounded theme-mod key/fingerprint summary. Theme-mod values are not exposed by the read adapter.

The Astra Child adapter may inventory bounded child-theme files and identify relative child files that override corresponding Astra parent files. It must not return arbitrary file contents. Filesystem scanning is bounded and excludes dependency/VCS directories such as `vendor`, `node_modules`, and `.git`.

Astra/Astra Child discovery does not authorize theme file modification or Customizer mutation.

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
- repository WordPress Core markers, repository themes, MU-plugin artifacts, and recognized drop-ins match the runtime-component manifest;
- the Runtime Components adapters register Core, MU Plugins, Drop-ins, Themes, Astra, Astra Child, and the aggregate inventory as read-only abilities;
- those component abilities mount on `mad4b-read` only and never on content/write/admin/breakglass surfaces;
- disposable WordPress 6.9/latest runtime tests discover a temporary MU plugin and drop-in;
- disposable runtime tests resolve Astra parent/Astra Child topology and identify a bounded child override without returning file contents;
- WordPress Core inspection never claims unperformed remote checksum verification or opens Core mutation authority;
- WooCommerce and Polylang remain priority external targets;
- unknown active runtime plugins create deterministic support requests without authority or network side effects;
- high-risk fixtures remain excluded;
- generic reversible Media mutation/undo/drift denial works on WordPress 6.9 and latest;
- exact packaged JetEngine is certified while its provider-native MCP parallel authority causes fail-closed mutation with approval and provider state left untouched.

This contract does not authorize Production deployment or mutation. T103 real Staging remains a separate mandatory boundary.
