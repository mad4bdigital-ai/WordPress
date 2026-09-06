# MAD4B Site Control Plane

Companion plugin for the official `WordPress/mcp-adapter`. The MCP protocol stays upstream; this plugin registers explicit, permissioned WordPress Abilities for site-wide AI operations.

## MCP surfaces

- `/wp-json/mcp/mad4b-read` — authenticated read/discovery.
- `/wp-json/mcp/mad4b-content` — `edit_posts`; governed content/plugin-specific edits.
- `/wp-json/mcp/mad4b-admin` — `manage_options`; workflows, cache, plugins, filesystem and structured DB repairs.
- `/wp-json/mcp/mad4b-breakglass` — disabled by default; exceptional raw SQL recovery.

The official default endpoint remains `/wp-json/mcp/mcp-adapter-default-server`.

## v0.2 adapter architecture

`MAD4B_SCP_Adapter_Registry` loads adapters independently and merges their declared abilities into the correct MCP surface. New adapters can be registered without modifying the MCP transport implementation.

Supported adapters:

- Elementor
- JetEngine
- JetSmartFilters
- Bit Flows / Bit Pi
- WordPress Media
- SEO (Rank Math governed metadata; Yoast/SEOPress detected)
- WooCommerce
- Polylang
- LiteSpeed Cache

Use `mad4b/adapters-inventory` to discover adapter availability and versions. Use `mad4b/runtime-self-test` to verify Abilities registration, MCP Adapter presence, and active runtime contracts.

## Key adapter abilities

### Elementor

`elementor/get-document`, `elementor/list-widgets`, `elementor/get-dynamic-tags`, `elementor/validate-document`, `elementor/update-widget-settings`.

Widget edits require the observed `_elementor_data` SHA-256 and target one exact widget ID before the document is rewritten and Elementor cache is cleared.

### JetEngine

`jetengine/get-post-meta`, `jetengine/list-post-meta`, `jetengine/get-cpt-definition`, `jetengine/update-post-meta`.

Existing meta writes require SHA-256. Protected `_...` keys are denied unless explicitly allowed through `mad4b_scp_allow_protected_meta_write`.

### JetSmartFilters

`jetsmartfilters/list-filters`, `jetsmartfilters/get-filter`, `jetsmartfilters/get-query-binding`, `jetsmartfilters/update-filter-meta`.

The adapter discovers the registered filter CPT. Internal meta updates are admin-only, SHA-locked, and limited to keys that already exist; WordPress edit-lock/system keys are denied.

### Bit Flows

`bitflows/list-flows`, `bitflows/get-flow`, `bitflows/get-executions`, `bitflows/run-flow`.

The execution contract is the plugin's own `BitApps\Pi\Model\Flow` plus `BitApps\Pi\src\Flow\FlowExecutor::execute()`. No arbitrary node/PHP execution API is exposed. Only active flows can be triggered, and execution requires `manage_options` unless deliberately narrowed with the permission filter.

### Media and SEO

Media can be searched/read, metadata can be edited, and an existing image can be set as a featured image. Remote URL sideloading is intentionally not exposed in v0.2.

SEO detection supports Rank Math, Yoast and SEOPress. Governed metadata reads/writes currently target an explicit Rank Math field allowlist rather than arbitrary post meta.

### WooCommerce, Polylang and LiteSpeed

WooCommerce exposes governed product read/update fields through `WC_Product` public setters. Polylang exposes languages/translations and post-language assignment through public `pll_*` functions. LiteSpeed exposes same-site URL purge and purge-all through the plugin's purge hooks; external URLs are rejected.

## Core v0.1 abilities retained

Read: site/CPT/plugin/Abilities inventory, filesystem read/list, DB table/describe/bounded select, diagnostics.

Content: post/CPT read/update.

Admin: plugin activate/deactivate, SHA-locked filesystem write/patch, bounded structured DB update, audit trail.

Breakglass: raw database query.

## Breakglass

Disabled unless:

```php
define( 'MAD4B_MCP_BREAKGLASS_ENABLED', true );
```

Raw SQL writes also require `MAD4B_MCP_BREAKGLASS_WRITE_SQL_ENABLED === true`. DDL additionally requires `MAD4B_MCP_BREAKGLASS_DDL_ENABLED === true`.

Grants/revokes, DB user/password operations, `LOAD DATA`, and `INTO OUTFILE`/`INTO DUMPFILE` remain hard-denied.

## Filesystem and database boundaries

Filesystem roots are restricted to `wordpress`, `content`, `plugins`, `themes`, and `uploads`. `realpath()` containment rejects traversal and symlink escape. Existing writes require the current SHA-256 and use temp-file replacement with optional backup.

Structured database mutations operate only through the current WordPress `$wpdb` connection. Updates require a non-empty `where` and a bounded preflight row count.

## Host boundary

The plugin never attempts privilege escalation. SSH, Hostinger APIs, system services, host-level cron/logs, files outside the PHP account, and unrelated database credentials belong in a separate MAD4B Host Connector.

## Dependency and verification

- WordPress 6.9+
- PHP 7.4+
- official `WordPress/mcp-adapter`

The repository currently contains WordPress `7.2-alpha-63448`. CI lints the control plane on PHP 7.4, 8.1 and 8.3 and enforces adapter/security contracts including the ban on arbitrary command/PHP execution primitives.
