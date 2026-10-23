# ARE Staging: governed Host Runner for Developer, Shell and Raw SQL

Status: DESIGN / NOT DEPLOYED. No Production authority. Do not mistake an enqueued job for an execution receipt.

## Established live facts

- Staging origin: https://staging.allroyalegypt.com
- Installed Control Plane at validation: 0.4.0-rc.103.
- PHP host has no proc_open, exec or process/network resource isolation.
- A pinned SSH diagnostic runner already supports health, wordpress_version, active_plugin_inventory, php_lint; it is a read-only standalone tool, **not** an authenticated MCP execution backend.
- Four existing runtime.status.read queue submissions passed exact plan/submission SHA256, target-fingerprint, and environment checks. They are not executed. Recorded pending job IDs: 588281e7-13de-40f5-8d91-a470005db74e, 5dd579c4-8557-4172-9eee-89a1ca29c87c, 7a6955a0-1994-4f3e-b709-e262a2354d69, caf7b7e8-bc83-49e8-931d-f64ee6aa153d.

## New governed operations (separate from read dispatch)

- `developer.wp-cli.read` for enumerated harmless diagnostics and `developer.php-lint.read` for private workspace file IDs; no caller-provided paths or arguments.
- `developer.shell.breakglass` for exact one-time approved commands executed **only** on an independently isolated, non-root worker; never from WordPress PHP. Disabled by default and not projected as a read tool.
- `developer.sql.read` for bounded read queries in a dedicated least-privilege staging-only database role; row limit, timeout, no multiple statements; do not return secret options, credential tables, user PII or arbitrary file contents.
- `developer.sql.write.breakglass` for one-time approved, rollback-backed SQL mutation on an explicitly enrolled staging schema, with separate authority from Shell, no elevated DB privileges and no DDL by default.

## Required controls before enabling Shell/SQL

1. Authenticate the exact ChatGPT OAuth client; verify dedicated Developer/Breakglass scope and enrolled site UUID, environment, deployment-binding fingerprint and agent ID.
2. Persist a plan containing canonical operation type, command/SQL SHA256, immutable exact target, workspace, risk, deadline, resource limits, and separately consumed approval ticket. Never accept user-supplied executable paths for the ordinary lane.
3. Enqueue only after the normal MAD4B Authorization claim and fresh plan comparison; a read-only queue job must not confer Shell/SQL authority.
4. Transport through an authenticated, pinned SSH channel. Verify job digest, permitted operation, target and signature again at the host worker. Do not trust a JSON file in a WordPress-writable directory by itself as an authorization proof.
5. Run under non-root identity in a network-restricted sandbox with CPU, memory, file, process and wall-clock limits; fail closed when confinement cannot be proven.
6. For SQL: constrain to an allowlisted staging DB identity; begin transaction, validate statement type, enforce timeout/row limit, log affected row counts and obtain exact backup/rollback evidence for writes.
7. Move queued -> running with exclusive claim; emit an authenticated and replay-resistant result to receipts only after host execution and readback. Retry only safe idempotent diagnostics; never blind-replay mutations.
8. Read receipt in a fresh WordPress request, verify detached host attestation, one-time job ID, plan/command digest, exit status, exact environment, and unchanged target fingerprint; audit and redact output.
9. Ensure connection tools do not expose Shell/SQL through the stable read catalog; publish only dedicated governed dispatch operations.
10. Acceptance must cover successful read-only queue/receipt cycle and denials for wrong client, expired ticket, changed plan, replay, Production, symlinks, path traversal, SQL multi-statement, privileged SQL, unavailable sandbox and stale receipts.

## Go/no-go

Go for harmless diagnostics once a durable authenticated queue consumer and receipts pass acceptance. Shell/Raw SQL remain **NO-GO** until independent host sandbox, privileges, signatures and scoped approvals are certified. No automatic merge or Production rollout.
