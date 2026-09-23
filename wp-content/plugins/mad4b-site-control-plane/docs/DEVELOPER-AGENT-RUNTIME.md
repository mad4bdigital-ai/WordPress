# MAD4B Developer Agent Runtime Plane

Release: `0.4.0-rc.55`

Contracts:

- Runtime: `mad4b.developer-runtime.v1`
- Execution receipt: `mad4b.developer-execution-receipt.v1`
- Authority bootstrap: `mad4b.developer-authority.v1`

## Purpose

The Developer Plane is a separate, default-off MCP execution plane for governed non-Production maintenance and development operations. It is intentionally isolated from normal ChatGPT read/write authority.

It provides two protected resources:

- `mad4b-developer`
- `mad4b-developer-breakglass`

Neither resource is projected into the normal `mad4b-chatgpt` catalog or the operational `mad4b-write` authority.

Production execution is denied by code.

## Normal Developer tools

- `mad4b/developer-runtime-status`
- `mad4b/developer-wp-cli`
- `mad4b/developer-filesystem`
- `mad4b/developer-package-install`

Arbitrary shell and PHP evaluation are not mounted on the normal Developer resource. They are Developer Breakglass operations because static command filtering cannot provide a reliable secrecy or filesystem boundary for arbitrary code.

## Developer Breakglass tools

- `mad4b/developer-breakglass-shell`
- `mad4b/developer-breakglass-wp-eval`

The Breakglass WP-eval tool invokes WP-CLI `eval` in the bounded subprocess. The plugin does not call PHP `eval()`.

Breakglass is a separate MCP resource, scope, grant set, enablement gate and approval class. Normal Developer provisioning never grants Developer Breakglass implicitly.

## Zero-touch authority bootstrap

Authority management is mounted only on `mad4b-enrollment`:

- `mad4b/developer-authority-status`
- `mad4b/developer-authority-plan`
- `mad4b/developer-authority-apply`
- `mad4b/developer-authority-disable`
- `mad4b/developer-breakglass-authority-plan`
- `mad4b/developer-breakglass-authority-apply`

The normal flow is:

1. Authenticate through the enrolled normal OAuth subject.
2. Call `developer-authority-status`.
3. Call `developer-authority-plan`.
4. Review the exact Site Profile, source commit, plan SHA-256, tool inventory and blockers.
5. Call `developer-authority-apply` with the exact expected plan fields and confirmation `PROVISION MAD4B DEVELOPER AGENT`.
6. The apply path creates or re-enables the dedicated Developer NHI, derives and binds the isolated `oauth_developer` subject, creates exact environment-bound grants, enables the managed runtime options and performs immediate readback.

Authority convergence is strict. Existing Developer grant inventory blocks apply when it contains wildcard grants, unexpected allow grants, broad/non-current environment allows, duplicate exact allows, or effective deny grants. Missing desired exact grants are the only grant state the normal apply path is allowed to create.
7. Any partial failure triggers bounded rollback. An incomplete rollback or completion-audit failure activates the Developer Kill Switch.

No SQL, WP-CLI or manual database mutation is required for this bootstrap.

## Identity isolation

Developer OAuth identity is derived from the already verified normal OAuth subject fingerprint plus the attributable OAuth client fingerprint.

The derived subject type is `oauth_developer`.

This prevents the normal ChatGPT subject from automatically inheriting Developer grants.

The protected-resource metadata and challenges are distinct for:

- `/wp-json/mcp/mad4b-developer`
- `/wp-json/mcp/mad4b-developer-breakglass`

Each resource has its own RFC 9728 protected-resource metadata URL and exact scope.

## Per-operation authorization

All non-readonly Developer abilities are High Impact. Developer Breakglass abilities are Exceptional.

Execution follows the central governed mutation lifecycle:

1. Exact Developer NHI resolution.
2. Exact server/ability/provider grant.
3. Exact OAuth scope when scopes are supplied.
4. Exact target fingerprint.
5. One-time short-lived approval ticket.
6. Replay-safe ticket claim.
7. Mutation budget reservation and commit.
8. Execution.
9. Approval finalization to `used` or `failed`.
10. Append-only audit evidence.

Use `mad4b/approval-plan` from the governed admin surface to create the pending approval ticket for the exact Developer operation. `mad4b-developer` and `mad4b-developer-breakglass` are first-class server IDs in that planner.

## Exact runtime binding

Each Developer mutation requires:

- `expected_source_commit_sha`
- `expected_site_uuid`
- `expected_environment`

The runtime rejects stale or cross-site execution.

## Process isolation

Subprocess execution is denied unless the runtime can use `prlimit`.

The process wrapper applies bounds for:

- address-space memory
- CPU time
- open files
- process count
- wall-clock timeout
- stdout/stderr size

Unix root execution is denied.

The normal runtime never calls PHP `eval()`, `shell_exec()`, `system()`, `passthru()`, `exec()` or `popen()`. A single bounded `proc_open()` process primitive is isolated inside the Developer runtime implementation.

Normal `developer-wp-cli` also denies WP-CLI aliases, caller-supplied `--path`, and global escape/bootstrap flags such as `--exec`, `--require`, `--ssh` and `--http`. Command families `eval`, `eval-file`, `db`, `config`, `shell`, `cli`, `package` and `server` require Developer Breakglass.

Normal WP-CLI also runs from an isolated system temporary working directory outside the WordPress tree, disables global/system WP-CLI config discovery through controlled environment settings, and adds `--skip-packages`. This prevents project/global aliases, `require` directives, or installed WP-CLI packages from silently widening the reviewed command.

Persistent-authority and code-lifecycle shortcuts are also constrained on the normal plane. `option`, `user`, `role`, `super-admin`, `application-password`, `site`, `network` and `scaffold` require Breakglass. For `plugin`, `theme` and `core`, normal WP-CLI is limited to read/verification subcommands; activation, installation, update and deletion use dedicated governed lifecycle surfaces or Breakglass.

## Network policy

Outbound network is default-deny.

When `allow_network=false`, subprocess execution requires an OS network sandbox backend such as Bubblewrap or `unshare --net`. If a certified backend cannot be started, execution fails closed.

Network-enabled jobs require both:

- global `MAD4B_MCP_DEVELOPER_NETWORK_ENABLED=true`
- per-job `allow_network=true`

Package installation therefore requires explicit network authority.

Normal package installation is install-only and cannot activate the package. Activation must use the separate governed plugin activation surface, so package retrieval and code activation remain distinct approvals. Normal package installation accepts only a WordPress.org-style plugin slug **plus an exact non-development version**, and it does not use `--force`; custom URLs, archives, alternate repositories, latest-version resolution, overwrite/update, or other mutable package sources require a separate governed lifecycle or Developer Breakglass.

Runtime status reports backend presence only; it does not claim kernel-level enforcement until an execution actually starts successfully.

## Filesystem policy

Normal Developer filesystem operations are confined to WordPress roots resolved through the existing path policy.

Parent traversal and absolute working-directory escape are denied.

Sensitive credential/configuration paths remain denied outside Developer Breakglass.

Delete requires an exact `expected_sha256`. Existing-file writes may also be bound to `expected_sha256` to prevent stale overwrite.

Normal Developer writes and deletes also pass the existing mutable-data policy, which denies executable/source-code and server-configuration mutation and defaults to explicitly allowed non-code data roots. Normal directory creation is likewise limited to mutable data roots. Live source-code mutation therefore requires Developer Breakglass or the governed repository/deployment path.

## Secrets and evidence

Execution audit stores command digests rather than raw commands.

stdout/stderr are redacted before return. JWTs, common secret assignments and WordPress secret constants are filtered.

The audit receipt records:

- ability
- environment
- Breakglass state
- command digest
- resource-limit backend
- network authorization/sandbox state
- exit code
- timeout state
- output digests
- timestamps
- duration
- mutation flag

## Managed enablement and constants

Managed options allow zero-touch enrollment activation. Explicit constants retain precedence when present.

The persistent Developer Kill Switch has higher priority than normal enablement and can force the plane off.

Breakglass additionally requires the existing global `MAD4B_MCP_BREAKGLASS_ENABLED` gate.

## Disable flow

Call `mad4b/developer-authority-disable` from the enrollment resource with:

- exact Developer agent public ID
- exact current agent revision
- confirmation `DISABLE MAD4B DEVELOPER AGENT`

The disable path activates the Kill Switch, disables managed runtime options and disables the Developer agent.

A later normal authority apply can re-enable the same NHI and subject binding idempotently.

## Live acceptance gates

Before live Developer execution is certified on Staging, verify:

- exact rc.55 source SHA and package provenance
- Site Profile exact origin and environment
- Developer authority plan has no blockers
- exact normal Developer grants exist and no unexpected allow grants exist
- OAuth protected-resource metadata is correct for both Developer resources
- `prlimit` is present for subprocess tools
- an OS network sandbox backend is usable for no-network subprocess execution
- WP-CLI is available for WP-CLI/eval/package tools
- process runs as non-root
- one-time approval lifecycle passes
- replay is denied
- timeout/output/resource bounds fail closed
- Kill Switch blocks execution
- Production remains denied
- Developer tools remain absent from normal ChatGPT/write catalogs
