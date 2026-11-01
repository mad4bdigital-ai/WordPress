# Company Management: governed Cross-SSH WP-CLI transport (candidate contract)

Status: **SOURCE DESIGN ONLY / NO LIVE REMOTE COMMAND EXECUTION**.

## Boundary

SSH is an authenticated transport, not an authorization source. Cross-SSH and WP-CLI must inherit the **original MAD4B Core Operation Registry, policy/role decision, Dispatcher, Native Executor, signed journal and readback**. No parallel authorization, hidden root shell, arbitrary command compiler or special bypass lane.

The previously known SSH alias `hosting-3:staging` did not resolve as a currently enrolled `target_id` in a 2026-11-01 connector check; another probe was rejected by the tool safety channel. Neither a historical successful probe nor a public site URL is sufficient to infer current enrollment.

## Proposed exact execution order

1. **Transport enrollment** (trusted infrastructure registry): immutable target ID, company ID, WordPress site UUID, exact HTTPS origin, environment, WP root, SSH identity, transport ID, pinned host key, enrollment revision. Probe against a fresh original site-profile identity receipt; reject clone or stale binding.
2. **Typed intent**: operation ID from the original Core registry, target, company, site UUID, origin and unique request ID only. No client-supplied `command`, `argv`, `ssh_command`, `authorized`, `--ssh`, `--allow-root`, `wp eval`, `wp eval-file`, raw SQL, DB query or arbitrary shell.
3. **Original Core decision**: identity/role, company and site ownership, exact operation permission, risk, scope, deadline and nonce/idempotency claim validated cryptographically by the real Core verifier. Read permission is not write permission. Production retains the same capabilities subject to higher risk/approval evidence.
4. **Allowlisted WP-CLI compiler**: fixed typed argument vectors only, derived from reviewed operation definitions and the trusted endpoint registry. Initial read-only examples: plugin inventory, theme inventory, Core version and site home. No user-defined flags or untrusted SSH command text.
5. **Native remote Broker**: pins the host public key and registered SSH account, checks actual remote site identity, enforces timeout/output limits, validates exact argv and Core decision before any remote process. Use least privilege and never infer authorization from root access.
6. **Verified readback**: check post-execution target identity, complete output/schema, digest, original Core journal/idempotency state, and signed terminal receipt bound to operation, company, site, transport, actor and exact source. Missing or conflicting evidence fails closed.
7. **Writes**: separately registered native operation with fresh company/site authorization, snapshot/restore checkpoint, provider-specific adapters, Apply → Readback → Rollback coverage. Do not promote a read-only plan into a write grant.

## Source-only test harness

A standalone, non-executing reference compiler and 11 passing negative/positive tests were prepared separately for review. The harness is **not wired** to the live Broker; fixture Core decisions are not cryptographic verification and are never acceptable in Production.

Before enabling cross-SSH, require a real Core adapter and independent enrolled target readback, signed Core authority, replay reservation, pinned SSH host verification and true native readback. A tool safety refusal must remain a refusal and never be retried through an alternate shell or connector.

## Acceptance conditions

- Read-only Core-authorized Staging operation PASS with actual enrolled target and signed readback.
- Wrong company, site UUID, origin, environment, host key, transport ID, operation ID, expired/replayed receipt, unsigned Core decision and unexpected command flags all DENIED.
- Every mutation requires independently authorized write operation and certified rollback; Production risk policy is stricter, not universally disabled.
- No secrets, private keys, SSH command strings or site-specific credentials in the repository.
