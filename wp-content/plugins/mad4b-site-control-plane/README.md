# MAD4B Site Control Plane

Companion plugin for the official `WordPress/mcp-adapter`. The upstream adapter owns MCP protocol/session/transport; MAD4B registers explicit WordPress Abilities and mounts them only on isolated custom MCP servers.

Current plugin version: **0.3.0**.

> Repository CI certification is not live-site certification. The PR remains Draft until the exact target WordPress deployment passes the target acceptance contract.

## MCP surfaces

- `/wp-json/mcp/mad4b-read` — privileged discovery and diagnostics.
- `/wp-json/mcp/mad4b-content` — governed content/provider mutations.
- `/wp-json/mcp/mad4b-admin` — governed administrative repair operations.
- `/wp-json/mcp/mad4b-breakglass` — exceptional raw SQL recovery; disabled by default.

All MAD4B abilities set `meta.public=false`, `meta.show_in_rest=false`, and `meta.mcp.public=false`. They are explicitly mounted into MAD4B custom servers and are not intended for the official default MCP server. `mad4b/runtime-self-test` treats exposure leaks, missing abilities, custom-server registration failure, required-provider certification failures, and detected MCP write side-channels as degraded runtime state.

## Mutation master switch

Every MAD4B mutation surface is fail-closed unless the site deliberately enables the global mutation gate:

```php
define( 'MAD4B_MCP_MUTATION_ENABLED', true );
```

Read-only discovery remains available according to its capability policy. Enabling the master switch does **not** bypass provider certification, NHI identity/grants, token scopes, resource constraints, budgets, exact approval tickets, optimistic state guards, plugin lifecycle policy, Breakglass gates, per-Flow policy, filesystem scope, or adapter-specific safety checks.

## Agent / NHI governance

Mutation authorization is centrally intersected rather than inferred from possession of an MCP endpoint:

```text
WordPress capability
→ global mutation gate
→ authenticated subject binding
→ enabled NHI
→ exact server + ability + provider grant
→ exact token scope when required
→ resource constraints
→ MCP peer write-side-channel guard
→ transactional blast-radius budget
→ exact short-lived approval when impact requires it
→ provider execution
```

Wildcard grants/scopes are rejected. Grant creation validates that the ability is actually mounted on the requested MAD4B server and that the provider matches the server membership model.

Administrative governance abilities include:

- `mad4b/agent-list` — bounded non-secret NHI summaries;
- `mad4b/agent-effective-access` — read-only effective-access simulation without consuming budget or approval;
- `mad4b/approval-plan` — creates a pending exact-operation ticket only; it never auto-approves or executes.

High-impact operations consume exact, short-lived, single-use approval tickets bound to NHI, server, ability, provider, target, ticket class, and canonical payload hash. Replay, expiry, class mismatch, NHI mismatch, and payload mismatch fail closed.

## Blast-radius budgets

NHI budgets are stored in transactional database tables rather than WordPress options/cache. Supported dimensions are `requests`, `mutations`, `affected_objects`, and `external_actions`.

Budget reservation uses row locking and happens before approval consumption. If approval is missing or rejected, the reservation rolls back. If the budget is exhausted, approval remains unused. CI proves the ordering, rollover, exhaustion, and real two-process contention behavior.

## MCP peer side-channel governance

Before central mutation authorization reserves budget or consumes approval, MAD4B inventories registered MCP servers and their actual tool metadata through the MCP Adapter runtime registry.

MAD4B-owned servers are governed directly. External/peer tools are classified from runtime semantics. Explicit read-only tools are non-blocking; unknown or reachable write paths fail closed. The default MCP Adapter `execute-ability` path is evaluated against the set of actually reachable public write abilities, so a valid MAD4B grant cannot bypass governance through another MCP server.

A detected peer write path produces `mcp_write_side_channel_detected` and appears in runtime authority/self-test evidence.

## Certified packaged-provider baseline

`config/certified-providers.json` is the runtime certification authority for package-backed providers. CI safe-extracts exact ZIPs without executing vendor PHP, validates expected contracts, records archive SHA-256 values, and generates critical-file manifests used by runtime mutation guards.

Current certified packages:

| Provider | Version | Control mode |
| --- | --- | --- |
| WordPress MCP Adapter | `0.6.1` | Official protocol/transport layer |
| Elementor | `4.1.4` | WordPress Abilities + explicit legacy fallback gate |
| JetEngine | `3.8.11.2` | Native JetEngine MCP + governed MAD4B adapter |
| JetSmartFilters | `3.8.3.1` | Governed MAD4B adapter; no native MCP server detected in package |
| Bit Pi / Bit Flows | `1.24.0` | FlowExecutor contract; packaged MCP role is client-only |

The exact MCP Adapter 0.6.1 release asset is pinned to:

```text
sha256: 1c3cd47c32e99b4e7d8690a44a7890256e92a8b96f61776cbe1894e5483cf676
bytes:  455463
```

Its runtime integrity manifest includes the zero-argument compatibility path `includes/Domain/Utils/AbilityArgumentNormalizer.php`, so the `{}` → `null` behavior used for no-input WordPress Abilities is verified by deployed file hash as well as package version.

Known upstream 0.6.1 constraints are recorded in the baseline. MAD4B does not register the affected `mcp_adapter_validation_enabled` callback while issue #305 remains unresolved and explicitly sets known MCP type values rather than depending on the fallback behavior tracked in issue #297.

## Provider mutation circuit breaker

Package-backed adapter mutations pass through `MAD4B_SCP_Provider_Contracts::mutation_guard()`.

Mutation is denied when the provider is unavailable, version-drifted, missing expected native abilities, unexpectedly exposes an ability certified as absent, lacks a required critical-file manifest, has a missing critical file, or has a critical-file SHA mismatch. Read/status surfaces may report degraded state without granting mutation.

Providers without a certified mutation baseline remain read/status only by default. WordPress Media is the intentional core exception because it does not depend on a third-party package contract.

## Reversible mutation and undo

`mad4b/content-update-post` is the first runtime-certified reversible mutation path. It records a durable mutation envelope **before** `wp_update_post()`, captures bounded rollback state and before-state SHA-256, performs read-after-write verification, and records the verified after-state SHA-256.

`mad4b/mutation-get` exposes bounded mutation metadata but not rollback payloads. `mad4b/mutation-undo` is high-impact: it reruns current authorization and requires an exact approval ticket. Undo refuses to overwrite newer work when the current state no longer matches the recorded after-state. Successful restore is readback-verified and creates a child recovery mutation record linked to the original mutation.

## Filesystem policy

Read discovery remains root-contained by `realpath()` and denies sensitive credential/configuration paths by default.

Normal filesystem **mutation is not a source-code deployment channel**. By default it is restricted to the `uploads` data root and an explicit non-executable extension allowlist. Executable/browser-executable/server-configuration targets are denied, including PHP/PHTML/PHAR, shell/script families, JavaScript/HTML/SVG, `.htaccess`, `.user.ini`, `php.ini`, and `web.config`.

Source-code changes belong to the governed repository → PR → CI → deployment path, not WordPress MCP filesystem mutation.

Existing data-file writes require the current SHA-256. Backups are created only in a protected temporary root outside WordPress/web roots, with restrictive permissions, and atomic replacement preserves the target mode.

## Plugin lifecycle

Plugin activation/deactivation has its own master opt-in in addition to the global mutation gate:

```php
define( 'MAD4B_MCP_PLUGIN_LIFECYCLE_ENABLED', true );
```

The default lifecycle allowlist is empty. A site must explicitly allow each plugin/operation through `mad4b_scp_plugin_lifecycle_allowlist`. Requests also require the expected current active state and a reason.

The control plane, MCP Adapter, and configured protected plugins cannot be deactivated through the normal surface. Network-wide deactivation on multisite additionally requires `manage_network_plugins`.

## Structured database policy

Structured mutation is fail-closed and is not a substitute for unrestricted SQL.

- Sensitive WordPress tables such as users, usermeta, options and sitemeta are denied by default.
- Secret/authentication-looking columns are denied.
- Data and WHERE columns must exist in the real `DESCRIBE` result.
- The target table must use a certified transactional engine such as InnoDB/XtraDB.
- `START TRANSACTION` must succeed.
- A bounded `SELECT ... FOR UPDATE` preflight must succeed before update.
- A non-empty WHERE and bounded `max_affected` are mandatory.
- Commit failure is treated as uncertified mutation failure.

## Breakglass

Breakglass remains inaccessible unless all relevant gates are satisfied. At minimum it requires the global mutation switch plus:

```php
define( 'MAD4B_MCP_BREAKGLASS_ENABLED', true );
```

The independent `mad4b_mcp_breakglass_permission` approval filter defaults to `false`; enabling constants alone is intentionally insufficient.

Raw writes require `MAD4B_MCP_BREAKGLASS_WRITE_SQL_ENABLED === true`; DDL also requires `MAD4B_MCP_BREAKGLASS_DDL_ENABLED === true`.

Multi-statements and privilege/user/role/password operations are hard-denied. `LOAD DATA`, `LOAD_FILE()`, `INTO OUTFILE`, and `INTO DUMPFILE` remain hard-denied. Raw SELECT execution must include an SQL-side LIMIT that does not exceed `max_rows`, preventing a response-only cap from loading an unbounded result into PHP memory.

## Elementor

Read/validation abilities include document, widgets, dynamic tags, and validation surfaces. Mutations require the observed document SHA-256.

The certified Elementor 4.1.4 package does not expose `elementor/manage-elements`. Direct `_elementor_data` mutation is therefore an exceptional legacy path and is disabled unless both the explicit runtime gate and site policy allow it:

```php
define( 'MAD4B_MCP_ELEMENTOR_LEGACY_WRITE_ENABLED', true );
```

`mad4b_scp_allow_elementor_legacy_write` still defaults to `false`. A future native mutation Ability must be re-certified before MAD4B consumes it.

## JetEngine

JetEngine reads and writes preserve exact canonical meta-key names; invalid keys are rejected rather than silently rewritten with `sanitize_key()`.

Unknown-field mutation is denied by default through `mad4b_scp_jetengine_field_write_allowed`. Existing writes require SHA-256. New meta requires administrator permission, `allow_create=true`, an explicit create policy, and the exact field policy.

Protected and sensitive meta are denied by default. Sensitive-looking keys such as credential/token/secret material are not returned by generic meta listing and require an explicit policy for direct access. This prevents a non-underscore secret key from bypassing protected-meta handling.

JetEngine's provider-owned native MCP routes are independently certified for their route set and permission/execution chain rather than reimplemented blindly.

## JetSmartFilters

The certified 3.8.3.1 package exposes provider/query/indexer contracts but no native MCP server was detected. Existing configuration mutation remains conservative and SHA-locked. Deeper query/provider mutation stays out of scope until an exact provider contract is proven.

## Bit Flows / Bit Pi

Read surfaces expose Flow inventory/history and a redacted definition with `flow_sha256`.

Flow execution requires the global mutation gate plus:

```php
define( 'MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED', true );
```

`bitflows/run-flow` requires the exact current Flow fingerprint. The per-Flow policy `mad4b_scp_bitflows_flow_allowed` defaults to `false`, so globally enabling Flow execution does not make every Flow runnable.

Execution uses Bit Pi's reviewed `BitApps\Pi\src\Flow\FlowExecutor::execute()` contract; the control plane does not expose arbitrary PHP or shell execution.

## Other adapters

- Media metadata writes require SHA-256; featured-image changes require expected current thumbnail ID.
- Rank Math writes use an explicit field allowlist, SHA-256, HTTP(S)-only canonical validation, allowlisted robots directives, and Unicode-aware length diagnostics.
- WooCommerce uses public `WC_Product` setters, SHA-256, constrained status/stock values, publish capability checks, and normalized prices.
- Polylang language changes require expected current language and a valid configured target language.
- LiteSpeed purge remains same-site only; external purge targets are rejected.
- Yoast and SEOPress are detected/readable where supported but governed writes remain an explicit gap.

## Append-only audit

Schema v4 stores audit evidence in transactional `mad4b_scp_audit_events` and `mad4b_scp_audit_heads` tables. Events are append-only, sequence-numbered, request-correlated, bounded/redacted, and chained by `previous_hash` / `entry_hash`.

The singleton chain head is initialized before operational transactions and serialized with `SELECT ... FOR UPDATE`. Audit events can join the Budget-owned transaction; post-commit hooks are dispatched only after an explicit commit, while rollback discards pending sink dispatch. This keeps approval/budget/audit evidence atomic in the governed authorization path.

The prior bounded WordPress option is retained read-only and cryptographically anchored during migration. Later drift of that legacy history fails integrity checks. Runtime CI proves concurrent two-process append without lost updates and proves that out-of-band event tampering makes `verify_chain()` fail.

`mad4b_scp_audit_committed` is the external sink integration point. A separate SIEM/WORM implementation is still optional/deferred; the local append-only database chain is the current durable source of truth.

## Read-only Admin Governance Console

Administrators receive a `MAD4B Control Plane` screen backed by the same governance/runtime services. The first UX slice is deliberately **visibility only** and exposes no POST handler or grant/approve/revoke/undo action.

Tabs cover:

- runtime authority, blockers and MCP peer governance;
- Agents/NHI and effective-access preview;
- approval-ticket evidence;
- mutation/undo evidence without rollback payloads;
- append-only audit integrity and bounded event tail.

The screen requires `manage_options`, uses bounded queries, and does not render structured audit summaries that may contain identity/context evidence. Mutation controls will be added only as separate nonce-protected, explicitly reviewed actions.

## CI certification

Repository CI currently covers:

- PHP 7.4 / 8.1 / 8.3 syntax;
- adversarial pre-install hardening contracts;
- exact packaged-provider version/archive certification;
- runtime critical-file integrity manifests;
- native MCP security invariants for JetEngine, Elementor and Bit Pi;
- default-server isolation and four MAD4B custom servers;
- exact NHI grants, governance visibility and approval planning;
- transactional NHI budget ordering, exhaustion, rollover and real concurrent contention;
- MCP peer write-side-channel detection before budget/approval consumption;
- reversible post mutation, readback verification, exact approved undo, and drift-safe undo denial;
- global mutation master-switch enforcement;
- filesystem source-execution denial;
- plugin lifecycle default-deny/multisite capability contracts;
- transactional structured-DB preflight;
- bounded Breakglass raw reads;
- Bit Flows per-Flow default deny;
- Elementor legacy-write explicit opt-in;
- JetEngine exact-field and sensitive-meta policy;
- append-only audit migration, structured redaction, concurrent append and tamper detection;
- read-only Admin Governance Console contract/runtime behavior;
- disposable WordPress/MySQL runtime activation and smoke testing on WordPress 6.9 and the current `latest` release.

The isolated runtime CI has successfully activated MCP Adapter 0.6.1 and MAD4B Site Control Plane 0.3.0 and certified the core governance/audit runtime on WordPress 6.9 and the current latest release used by CI. This does **not** replace target-site certification.

The core mutation-gate workflow is read-only. The MCP Adapter refresh workflow is manual-only (`workflow_dispatch`) and may write certification evidence only when an operator explicitly runs it on a selected branch.

## Live target acceptance still required

Keep the PR Draft until the exact target site proves at least:

1. the deployed provider versions and critical files match the certified baseline;
2. MCP Adapter and the control plane activate without fatal/runtime warnings;
3. the dedicated control identity authenticates correctly through real MCP transport/session handling;
4. `mad4b/runtime-self-test` returns `passed`, with custom-server isolation and no required-provider/peer blockers;
5. the official default MCP server cannot discover MAD4B abilities;
6. the resolved backup root is outside WordPress/web roots;
7. the staging NHI uses exact minimal non-wildcard grants;
8. approved reversible content mutation succeeds with readback verification and undo succeeds;
9. deliberate post-mutation human drift makes undo fail closed without overwriting newer work;
10. budget exhaustion denies before approval consumption;
11. filesystem code/server-config mutation is rejected;
12. plugin lifecycle remains unavailable unless explicitly enabled and allowlisted;
13. one approved non-sensitive structured DB repair succeeds while sensitive tables remain denied;
14. Breakglass is inaccessible under default configuration;
15. peer MCP inventory has no write-side-channel blocker;
16. success and rejection paths both leave a valid append-only audit chain;
17. all mutation gates are returned to OFF after certification unless controlled staging is intentionally continuing.

## Remaining genuine gaps

- exact live MCP authentication/session behavior on the target site;
- target provider versions/files and real provider side effects until staging certification;
- reversible mutation contracts beyond the certified post-update pilot where provider-safe restore is required;
- exhaustive commercial JetEngine field schema/type governance for fields that require it;
- deeper JetSmartFilters mutation contracts;
- governed Yoast/SEOPress writes;
- nonce-protected Admin mutation controls for grants/approval actions/undo; the current console is intentionally read-only;
- optional external SIEM/WORM audit sink implementation.

## Host boundary

The WordPress control plane does not provide arbitrary PHP/shell execution or privilege escalation. SSH, Hostinger APIs, system services, host-level cron/logs, files outside PHP permissions, and unrelated database credentials remain a separate MAD4B Host Connector concern.
