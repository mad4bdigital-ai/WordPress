# OAuth Consent and Developer Host Readiness — governed handoff

State: SOURCE FIX / RELEASE ACCEPTANCE PENDING. Feature 007 Integration Hub: PR #258.

## Observed Staging evidence, 2026-10-08

- Runtime source `6584ea1d396a21865a0f39015701872541b254f5` with
  `0.4.0-rc.96`; stored candidate `69fa5c2291c9bbe093c0db10a3bb1105a94ce836`.
- Site Profile ready and current grant snapshot ready, but governed Write
  fail-closed due to `runtime_authority_candidate_not_reconciled`.
- Developer/Developer Breakglass **authority flags** were present, but host
  execution failed `resource_limiter_unavailable` and
  `network_isolation_unavailable`.
- This evidence is not release acceptance and is not an approval to apply.

## Implemented presentational protections

- Display separate Developer authority and executable host readiness.
- Report bounded blockers with no absolute binary paths, credentials or
  command execution.
- Only present nonblank validated ability/provider labels; display omissions
  as integrity warnings rather than anonymous grant bullets.
- Mark Full Staging Authority as operationally ready only if Write authority,
  Developer authority, Developer Breakglass authority AND the host isolation
  checks are all ready. This does not produce an execution grant.
- Refresh fingerprint covers the host capability fingerprint; dynamic updates
  do not silently leave outdated "Allowed" results on screen.
- Do not change OAuth decisions, client, scopes, PKCE, nonces, tokens, refresh
  behavior, redirects or approval mechanics.

## Host remediation: outside WordPress responsibility

The hosting/platform operator, not an MCP WordPress agent, must verify:

1. PHP worker executes under a non-root host account, and `proc_open` is
   available under the hosting policy.
2. Install or configure an OS-managed `prlimit` binary. Only point
   `MAD4B_MCP_DEVELOPER_PRLIMIT_BIN` at the verified, executable path if
   default /usr/bin/prlimit or /bin/prlimit does not resolve.
3. Configure an OS network isolation backend: `bwrap` or `unshare`.
   If outside standard paths, configure
   `MAD4B_MCP_DEVELOPER_NETWORK_SANDBOX_BIN` after provider/host review.
4. Independently test whether the network namespace can be *entered* under
   the PHP worker UID and host restrictions; binary presence alone is not
   sufficient. Test that arbitrary outbound connectivity is absent and
   bounded resource limits are effectively enforced in a disposable fixture.
5. Read back `MAD4B_SCP_Developer_Host_Capabilities::snapshot()` and
   `MAD4B_SCP_Developer_Runtime::runtime_status()`; require
   `normal_no_network_execution_ready=true` plus actual disposable
   host-level no-network test acceptance.
6. Never weaken to unsandboxed fallback, enable generic SQL Breakglass,
   or infer Production grants from Staging host readiness.

These are **operator-run checks**. No package install, shell command, or
host-level change was executed by this PR.

## Governing Write convergence

Perform the existing **Write-only**, read-only
`mad4b/staging-write-authority-convergence-handshake` and confirm exact
plan SHA, profile revision/digest, runtime generation and artifact identity.
Require separately authorized exact Write-only apply + same-cycle readback.
Do not use Full Staging Authority merely to repair candidate drift, and never
infer consent from OAuth scope. Stale plans must be discarded.

## Release gates

- Native PHP 7.4/8.3 CI plus source-contract testing on exact head
- Real WordPress OAuth authorize GET response and AJAX refresh
- Confirm no blank list items, accessible status messages, and consistency
  of `grants`, `exact_grants_existing`, `candidate_binding_match`
- Confirm classification "Authority Ready / Execution Blocked" while
  Developer host binaries are unavailable
- Confirm no OAuth token/scopes/POST changes and no mutation in this PR
- Verify host operational acceptance separately; source-only changes cannot
  certify actual host isolation
