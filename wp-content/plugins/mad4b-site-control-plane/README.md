# MAD4B Site Control Plane

Companion plugin for the official `WordPress/mcp-adapter`. It keeps the MCP protocol layer upstream and registers governed WordPress Abilities for site-wide AI operations.

## Surfaces

- `/wp-json/mcp/mad4b-read` — `read`; no mutations.
- `/wp-json/mcp/mad4b-content` — `edit_posts`; governed content mutations.
- `/wp-json/mcp/mad4b-admin` — `manage_options`; plugin/filesystem/structured DB repair.
- `/wp-json/mcp/mad4b-breakglass` — disabled by default; exceptional raw SQL recovery.

The official default endpoint remains `/wp-json/mcp/mcp-adapter-default-server`; only the control plane's read-only abilities opt into its layered discovery.

## v0.1 abilities

Read: `site-info`, `list-post-types`, `list-plugins`, `abilities-inventory`, `filesystem-list`, `filesystem-read`, `database-list-tables`, `database-describe-table`, `database-select`, `diagnostics-health`.

Content: `content-get-post`, `content-update-post`.

Admin: `plugin-activate`, `plugin-deactivate`, `filesystem-write`, `filesystem-patch`, `database-update`, `audit-tail`.

Breakglass: `database-raw-query`.

## Breakglass

Disabled unless:

```php
define( 'MAD4B_MCP_BREAKGLASS_ENABLED', true );
```

Raw SQL writes also require:

```php
define( 'MAD4B_MCP_BREAKGLASS_WRITE_SQL_ENABLED', true );
```

DDL additionally requires:

```php
define( 'MAD4B_MCP_BREAKGLASS_DDL_ENABLED', true );
```

Even then, grants/revokes, DB user/password operations, `LOAD DATA`, and `INTO OUTFILE`/`INTO DUMPFILE` remain hard-denied.

## Filesystem contract

Allowed roots: `wordpress`, `content`, `plugins`, `themes`, `uploads`.

Paths are resolved with `realpath`; parent traversal, NUL bytes, root escape and symlink escape are rejected. Existing file writes require the current SHA-256. Patch operations use exact replacement with a replacement cap. Writes use a temp file + rename and can create a timestamped adjacent backup.

## Database contract

Structured DB operations use only tables visible to the current WordPress `$wpdb` connection. `database-update` requires a non-empty `where` and checks `COUNT(*)` before mutation against `max_affected`.

## Plugin strategy

Native Abilities from any plugin/theme are visible through `abilities-inventory`. `list-plugins` identifies known families for future deep adapters: Elementor, JetEngine, JetSmartFilters, Bit Flows/Bit Integrations, WooCommerce, SEO, Polylang and LiteSpeed.

Do not expose arbitrary PHP function execution. Deep adapters should register explicit contracts.

## Host control boundary

This plugin can access only files and DB resources available to the PHP process. SSH, host services, files outside that account, hosting APIs and other databases belong in a separate MAD4B Host Connector; the WordPress plugin must not attempt privilege escalation.

## Dependency

- WordPress 6.9+
- PHP 7.4+
- official `WordPress/mcp-adapter`

The target repository currently runs WordPress `7.2-alpha-63448`, so the Abilities API is already in core.
