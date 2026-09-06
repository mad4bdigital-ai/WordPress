# MAD4B Site Control Plane

Companion plugin for the official `WordPress/mcp-adapter`. MCP protocol/session/transport stays upstream; this plugin registers explicit WordPress Abilities and routes them through isolated MAD4B custom MCP servers.

Current version: **0.3.0**.

## MCP surfaces

- `/wp-json/mcp/mad4b-read` — read-only discovery; `manage_options` by default because it includes filesystem/database/plugin diagnostics.
- `/wp-json/mcp/mad4b-content` — governed post/plugin-specific edits.
- `/wp-json/mcp/mad4b-admin` — administrative workflows, cache, plugins, filesystem and structured DB repair.
- `/wp-json/mcp/mad4b-breakglass` — disabled by default; exceptional raw SQL recovery.

MAD4B abilities set `meta.mcp.public=false`; they are not exported through the official default MCP server. They are registered explicitly into the intended MAD4B server. `mad4b/runtime-self-test` reports any adapter Ability that leaks into default-server exposure.

## v0.3 execution model

The preferred mutation lifecycle is:

1. discover/read current state;
2. obtain `modified_gmt`, SHA-256, current language/thumbnail, or Flow fingerprint;
3. submit the expected state with the mutation;
4. reject stale writes;
5. execute through the provider's public/native API when available;
6. record correlated audit evidence.

Blind mutations are intentionally avoided.

## Certified provider baseline

The repository's packaged provider ZIPs are treated as a certification input, not merely as install media. `config/certified-providers.json` pins the exact reviewed version and archive SHA-256. CI extracts the ZIPs into a temporary directory without executing provider PHP, verifies required contracts, inventories native MCP/Abilities surfaces, and fails if version or archive bytes drift.

Current certified packages:

| Provider | Version | Control mode |
| --- | --- | --- |
| WordPress MCP Adapter | `0.5.0` | Official protocol/transport layer |
| Elementor | `4.1.4` | WordPress Abilities + governed MAD4B fallback |
| JetEngine | `3.8.11.2` | Native JetEngine MCP + governed MAD4B adapter |
| JetSmartFilters | `3.8.3.1` | Governed MAD4B adapter; no native MCP layer detected in this package |
| Bit Pi / Bit Flows | `1.24.0` | FlowExecutor contract; packaged MCP role is client, not server |

`MAD4B_SCP_Provider_Contracts` compares the deployed plugin version with this baseline. A package-backed provider reports `certified`, `version_drift`, or `unavailable`; `mad4b/runtime-self-test` becomes `degraded` when a certified active provider or the MCP Adapter drifts.

## Native MCP security certification

Native provider MCP surfaces are inspected separately from MAD4B's four custom servers so a provider-owned endpoint cannot silently become a side channel.

### JetEngine 3.8.11.2

The certified package exposes three MCP REST controller paths under `jet-engine/v1`. CI enforces the reviewed permission chain:

- MCP tools listing: `manage_options`.
- MCP JSON-RPC route: `manage_options`.
- Direct feature run route: valid `wp_rest` nonce **plus** `Feature::check_permissions()`.
- Default `Feature::check_permissions()` behavior: fail closed to `current_user_can( 'manage_options' )` when no feature-specific permission callback exists.
- Feature execution: resolve through the Registry, then execute the resolved Feature.
- Debug runner: administrator-gated.

The security workflow fails if the JetEngine native route set changes, any of these gates disappears, or the execution chain no longer passes through the Feature permission contract.

### Elementor 4.1.4

The certified package exposes MCP behavior through the WordPress Abilities API rather than a separate custom REST MCP server. CI requires the packaged MCP module to retain capability checks based on `edit_posts` and post-specific `edit_post` checks, and fails if a new custom MCP REST route appears without re-certification.

The packaged native abilities include:

- `elementor/list-pages`
- `elementor/get-page-structure`
- `elementor/update-page-settings`
- `elementor/get-globals`
- `elementor/create-page`

`elementor/manage-elements` is **not** present in the certified 4.1.4 package. MAD4B therefore cannot assume that newer upstream mutation contract exists on this baseline.

### Bit Pi 1.24.0

The packaged MCP implementation is a client (`McpClient`), not a WordPress MCP server. CI fails if server-side `register_rest_route()` surfaces appear in its MCP package paths without explicit re-certification. MAD4B Flow execution remains separately disabled by default and uses the reviewed `Flow` / `FlowNode` / `FlowHistory` / `FlowExecutor` contracts.

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

Use `mad4b/adapters-inventory` for runtime discovery and `mad4b/runtime-self-test` for registration, isolation and provider-version certification checks.

## Elementor

Abilities include `elementor/get-document`, `elementor/list-widgets`, `elementor/get-dynamic-tags`, `elementor/validate-document`, and `elementor/update-widget-settings`.

Updates require the observed Elementor document SHA-256. If a future certified Elementor version registers `elementor/manage-elements`, MAD4B can delegate mutation to that native Ability so Elementor owns validation and document-save lifecycle. On the current certified Elementor 4.1.4 package that Ability is absent, so direct `_elementor_data` mutation remains an administrator-only legacy fallback and can be further controlled with `mad4b_scp_allow_elementor_legacy_write`.

## JetEngine

Abilities include meta discovery/read, WordPress CPT definition discovery, and SHA-locked existing-meta updates.

Protected `_...` keys are denied by default. Exact protected reads require administrator permission unless explicitly enabled. Creating a new meta key is fail-closed: `allow_create=true` is not sufficient; the caller must be an administrator and the site must explicitly allow it through `mad4b_scp_allow_jetengine_meta_create`.

The certified JetEngine 3.8.11.2 package also contains its own MCP feature registry for post types, taxonomies, meta boxes, Query Builder, listings, Custom Content Types, glossaries, configuration, macros, and modules. These native capabilities are treated as provider-owned surfaces and are security-certified separately rather than blindly reimplemented inside MAD4B.

Exact field-type/schema semantics for every commercial JetEngine field remain a runtime/provider-contract item; MAD4B does not invent private schema calls that the certified package has not proven safe for the requested mutation.

## JetSmartFilters

The exact packaged 3.8.3.1 build has been statically inspected. It exposes the JetSmartFilters accessor, providers, query variables and indexer contracts but no native MCP layer was detected.

Detailed filter configuration reads and mutations remain administrator-only. Existing filter-meta mutation requires SHA-256 and cannot create arbitrary internal keys. Query-binding discovery remains conservative until a stable provider-owned mutation API is proven for the exact operation.

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

Rank Math SEO writes use an explicit field allowlist and require SHA-256. Canonical URLs are restricted to valid HTTP(S) URLs and robots directives are allowlisted. Yoast and SEOPress are detected but remain explicit provider gaps until governed adapters are implemented.

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

## Live acceptance still required before merge

Repository and packaged-provider certification cannot prove deployment behavior. The PR should remain Draft until the exact target site proves:

1. installed provider versions match the certified baseline;
2. `mad4b/runtime-self-test` returns `passed`, `custom_server_isolation=true`, no missing Abilities and no provider version drift;
3. the official default MCP server does not discover MAD4B Abilities;
4. real MCP authentication/session behavior is correct for the dedicated control identity;
5. Hostinger's resolved backup root is outside WordPress/web roots;
6. one intentional stale-write test rejects an outdated content mutation;
7. the actual Elementor mutation path behaves correctly for the deployed 4.1.4 baseline or any deliberately re-certified replacement;
8. one approved benign Bit Flow runs only after explicit enablement and exact Flow fingerprint confirmation;
9. one non-sensitive structured DB repair succeeds while sensitive tables remain denied;
10. Breakglass remains inaccessible while its enable constant is absent;
11. audit evidence is produced for success and rejection paths.

## Remaining implementation gaps

- exhaustive JetEngine commercial field schema/type governance for mutations that need it;
- deeper JetSmartFilters provider/query mutation APIs where stable contracts can be proven;
- Yoast/SEOPress governed writes;
- append-only/high-concurrency audit storage;
- live MCP/auth/provider side-effect certification on the deployed site.

## Host boundary

The WordPress plugin never attempts privilege escalation. SSH, Hostinger APIs, system services, host-level cron/logs, files outside PHP permissions, and unrelated database credentials remain a separate MAD4B Host Connector concern.

## Dependency and CI

- WordPress 6.9+
- PHP 7.4+
- official `WordPress/mcp-adapter`

CI now enforces:

- PHP 7.4 / 8.1 / 8.3 syntax;
- exact packaged provider version and archive SHA-256 certification;
- native provider MCP security invariants for JetEngine, Elementor and Bit Pi;
- default-server isolation;
- sensitive filesystem/database boundaries;
- mutation fingerprints and stale-write guards;
- Bit Flows explicit execution opt-in;
- Breakglass hard-denies;
- four custom MCP surfaces;
- the ban on arbitrary PHP/shell execution primitives.
