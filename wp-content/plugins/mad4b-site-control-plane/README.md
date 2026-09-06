# MAD4B Site Control Plane

Companion plugin for the official `WordPress/mcp-adapter`. MCP protocol/session/transport stays upstream; this plugin registers explicit WordPress Abilities and routes them through isolated MAD4B custom MCP servers.

Current version: **0.3.0**.

## MCP surfaces

- `/wp-json/mcp/mad4b-read` — read-only discovery; `manage_options` by default because it includes filesystem/database/plugin diagnostics.
- `/wp-json/mcp/mad4b-content` — governed post/plugin-specific edits.
- `/wp-json/mcp/mad4b-admin` — administrative workflows, cache, plugins, filesystem and structured DB repair.
- `/wp-json/mcp/mad4b-breakglass` — disabled by default; exceptional raw SQL recovery.

MAD4B abilities set `meta.mcp.public=false`; they are therefore not exported through the official default MCP server. They are registered explicitly into the intended MAD4B server. `mad4b/runtime-self-test` reports any adapter Ability that leaks into default-server exposure.

## v0.3 execution model

The preferred mutation lifecycle is:

1. discover/read current state;
2. obtain `modified_gmt`, SHA-256, current language/thumbnail, or flow fingerprint;
3. submit the expected state with the mutation;
4. reject stale writes;
5. execute through the provider's public/native API when available;
6. record correlated audit evidence.

Blind mutations are intentionally avoided.

## Adapter Registry

`MAD4B_SCP_Adapter_Registry` loads adapters independently and merges each adapter's declared `read`, `content`, and `admin` abilities into the matching custom server.

Supported adapters:

- Elementor
- JetEngine
- JetSmartFilters
- Bit Flows / Bit Pi
- WordPress Media
- SEO (Rank Math governed writes; Yoast/SEOPress detected)
- WooCommerce
- Polylang
- LiteSpeed Cache

Use `mad4b/adapters-inventory` for runtime discovery and `mad4b/runtime-self-test` for registration/isolation checks.

## Elementor

Abilities include `elementor/get-document`, `elementor/list-widgets`, `elementor/get-dynamic-tags`, `elementor/validate-document`, and `elementor/update-widget-settings`.

Updates require the observed Elementor document SHA-256. When Elementor registers its native `elementor/manage-elements` Ability, MAD4B delegates mutation to that Ability so Elementor owns validation and document-save lifecycle. Direct `_elementor_data` mutation exists only as an administrator-only legacy fallback and can be further controlled with `mad4b_scp_allow_elementor_legacy_write`.

## JetEngine

Abilities include meta discovery/read, WordPress CPT definition discovery, and SHA-locked existing-meta updates.

Protected `_...` keys are denied by default. Exact protected reads require administrator permission unless explicitly enabled. Creating a new meta key is fail-closed: `allow_create=true` is not sufficient; the caller must be an administrator and the site must explicitly allow it through `mad4b_scp_allow_jetengine_meta_create`.

The commercial JetEngine runtime schema is not assumed. Provider-specific field-type validation should be added only after runtime/source inspection proves the exact installed contract.

## JetSmartFilters

Detailed filter configuration reads and mutations remain administrator-only. Existing filter-meta mutation requires SHA-256 and cannot create arbitrary internal keys. Query-binding discovery is currently conservative/heuristic until the exact installed commercial runtime contract is inspected.

## Bit Flows

Read abilities expose Flow inventory/history and a redacted Flow definition plus `flow_sha256` fingerprint.

Execution is disabled by default. To enable it deliberately:

```php
define( 'MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED', true );
```

`bitflows/run-flow` also requires `expected_flow_sha256`. The fingerprint covers the Flow definition and `FlowNode` records, including node mappings/data/variables. A changed Flow is rejected before execution. `mad4b_scp_bitflows_flow_allowed` can apply a per-Flow policy.

Execution uses Bit Pi's own `BitApps\Pi\src\Flow\FlowExecutor::execute()`; no arbitrary PHP/node execution surface is exposed.

## Media, SEO, WooCommerce and Polylang

Media metadata updates require SHA-256. Featured-image changes require the expected current thumbnail ID.

Rank Math SEO writes use an explicit field allowlist and require SHA-256. Canonical URLs are restricted to valid HTTP(S) URLs and robots directives are allowlisted. Yoast and SEOPress are detected but remain read/write-provider gaps until explicit adapters are implemented.

WooCommerce product updates use public `WC_Product` setters, require SHA-256, validate publish capability, constrain status/stock values, and use `wc_format_decimal()` for prices.

Polylang language assignment requires the expected current language and validates the target language against the configured language inventory.

## Sensitive filesystem policy

Allowed roots remain `wordpress`, `content`, `plugins`, `themes`, and `uploads`; `realpath()` containment rejects traversal and symlink escape.

Sensitive configuration/credential files are denied by default, including `wp-config` variants, `.env` variants, `.ssh`, `.htpasswd`, private keys/certificate containers, and common credential JSON files.

File mutations require SHA-256 for existing files. Backups are never created adjacent to PHP/source files. They are written under a protected temp backup root outside WordPress web roots, with restrictive permissions. Atomic replacement preserves the target file's existing mode.

## Structured database policy

Structured reads/writes are not a substitute for raw SQL.

By default, sensitive WordPress tables such as users, usermeta, options and sitemeta are denied from structured access. Secret/authentication-looking columns are also denied. Site filters can deliberately extend or override these classifications.

Structured writes require a non-empty WHERE, bounded `max_affected`, and a transaction/`FOR UPDATE` locked preflight when supported. Sensitive/auth tables stay outside this path; exceptional repair belongs in Breakglass.

## Breakglass

Disabled unless:

```php
define( 'MAD4B_MCP_BREAKGLASS_ENABLED', true );
```

Raw writes require `MAD4B_MCP_BREAKGLASS_WRITE_SQL_ENABLED === true`; DDL also requires `MAD4B_MCP_BREAKGLASS_DDL_ENABLED === true`.

SQL comments are normalized before classification. Multi-statements are denied. Privilege/user/role changes, password changes, `LOAD DATA`, `LOAD_FILE()`, `INTO OUTFILE`, and `INTO DUMPFILE` remain hard-denied even when DDL/write gates are enabled.

## Plugin lifecycle protection

The normal admin surface cannot deactivate MAD4B Site Control Plane itself or its official `mcp-adapter` dependency. This prevents an ordinary MCP action from severing its own control transport.

## Audit

Audit entries include a request correlation ID plus `previous_hash`/`entry_hash` linkage. The current storage remains a bounded WordPress option and is suitable for MVP operational evidence, but a dedicated append-only table is still the preferred future hardening for high-concurrency/tamper-resistant environments.

## Known runtime-evidence gaps

The following are intentionally not claimed as complete until tested against the exact deployed plugins:

- JetEngine field schema/type validation;
- JetSmartFilters provider/query internal API mapping;
- Yoast/SEOPress governed writes;
- end-to-end MCP authentication/session behavior on the target site;
- provider-side behavior after real Elementor/Bit Flows/LiteSpeed mutations.

`mad4b/runtime-self-test` is the first live acceptance step after deployment.

## Host boundary

The WordPress plugin never attempts privilege escalation. SSH, Hostinger APIs, system services, host-level cron/logs, files outside PHP permissions, and unrelated database credentials remain a separate MAD4B Host Connector concern.

## Dependency and CI

- WordPress 6.9+
- PHP 7.4+
- official `WordPress/mcp-adapter`

CI lints on PHP 7.4, 8.1 and 8.3 and enforces default-server isolation, sensitive filesystem/database boundaries, mutation fingerprints, Bit Flows opt-in, Breakglass hard-denies, adapter contracts, four custom MCP surfaces, and the ban on arbitrary PHP/shell execution primitives.
