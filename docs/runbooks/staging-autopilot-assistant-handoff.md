# MAD4B Staging Autopilot & assistant handoff (PR #258)

## Operational problem
A correctly enrolled Staging Site Profile may still report `WP_ENVIRONMENT_TYPE=production` because WordPress defaults to Production until an explicit host bootstrap value is set. The old `profile_only` setting is a user opt-out: installing a new plugin must not silently rewrite it or mutate `wp-config.php` on an MCP read.

## Delivered
- `mad4b/site-autopilot-status`: bounded, read-only MCP Ability with an exact source-neutral state machine. Returns responsible actor, next action ID, deployment-binding readiness, and separate non-authorizing Write/Developer diagnostics for assistants. It does not expose `wp-config.php`, private binding values, filesystem paths, credential material or caller-controlled file operations.
- **Staging Autopilot** section in Site Profile admin: detects a previously saved `profile_only` profile with the confirmed implicit-Production default and shows a one-click **Enable / Retry Staging Autopilot** action. This is a local nonce-protected admin POST, not an MCP write tool. It preserves existing profile identity, OAuth user IDs, feature switches, origins, optimistic revision/digest checks, and the existing audited Site Profile CAS flow.
- After exact profile readback, the existing guarded Staging writer attempts `wp-config.php` insertion. A separate next WordPress request must confirm `staging`.
- On-demand read-only `preflight_readonly` on the existing config writer provides a safe state code for unwritable/symlinked/unsupported config files without returning secrets, paths or file contents.

## Responsibilities
1. **Site administrator** opts into Host-Managed Staging on the existing site (or initial enrolled Staging uses its existing default). No silent migration of `profile_only`.
2. **Local config writer** can update *only* implicitly Production WordPress with exact attested Staging Site Profile on an authorized save. Explicit Production or an unknown Host environment blocks.
3. **Assistant** runs `mad4b/site-autopilot-status` to see next action and actor. It can diagnose Write convergence with `mad4b/staging-write-authority-convergence-handshake`; do not conflate stale grants with the environmental bootstrap.
4. **Host operator** is separately responsible for private deployment binding, missing process limiter (`prlimit`), Linux network isolation (`bwrap` or `unshare`), and filesystem host authority where required.
5. **Owner** must explicitly authorize exact Staging Write convergence and any separately scoped update. Developer and Breakglass are never auto-granted.

## Acceptance
- Verify PHP lint of all changed files.
- Run `php wp-content/plugins/mad4b-site-control-plane/tests/staging-autopilot-runtime.php`.
- Run existing `tests/site-profile-wp-config-auto-sync-runtime.php` and critical kernel tests on the exact HEAD.
- Package using the canonical deterministic builder and MCP Adapter certification; check SHA-256 and provenance.
- After deployment, call `mad4b/site-autopilot-status`, use the admin one-click action if eligible, and verify `wp_get_environment_type() === staging` in a new request. No release or Production authorization is implied by a green source fixture.

## Ordered assistant workflow
The `assistant_workflow` contains exactly five bounded lanes: (1) Site Profile and WordPress environment, (2) host-private deployment binding and clone protection, (3) explicit Staging Write-only convergence, (4) host-dependent Developer execution prerequisites, and (5) fresh exact-HEAD acceptance. Every lane has actor and next-action ID. Write/Developer lanes are marked **not evaluated**, not falsely healthy; agents must fetch their exact independent handshake. None authorizes unattended host mutations, automatic grants or Production promotion.

## Ordered automation plan (2026-10-10, PR #258 source only)

`mad4b/site-autopilot-status` now includes `automation_plan`, which composes
live, **read-only**, separately observed evidence for the five existing lanes.
The Site Profile admin shows those lanes without silently creating grants,
secrets or host packages. Its precedence is deterministic and fail closed:

1. **Exact Site Profile and WordPress bootstrap:** require explicit WordPress
   `staging` and exact origin/profile identity. A Staging WordPress bootstrap
   is independently green even when Host Sync reports a missing binding.
2. **Host deployment binding:** require a host-sourced CSPRNG secret, registered
   in `MAD4B_SCP_DEPLOYMENT_BINDING`, persisted as a digest by an exact local
   administrator Site Profile save, and subsequently verified for same-origin
   clone protection. Missing host secret, unbound secret and mismatched secret
   have distinct blockers/actions. The secret is never generated or exposed by
   the MCP diagnostic or the WordPress read ability.
3. **Current governed Write and Managed Skills:** observe the exact current
   Write-only convergence status and live Skill Runtime state. Saving Site
   Profile increments its revision/digest; old exact-plan envelopes are
   invalid. After the final save, the owner must explicitly review and
   authorize a fresh exact Write-only plan; don't infer Developer/Breakglass
   authority from Write readiness. Skill reconciliation retains a separate
   bounded step-up and checkpointed identity.
4. **Developer Host:** surface sandbox and resource prerequisites separately.
   Executable discovery never certifies OS isolation. Use the host-only
   `tools/mad4b_staging_host_preflight.py` to inspect availability; its
   opt-in `--canary` runs just bounded `/bin/true` through the configured
   `prlimit` and `bwrap`/network namespace. It **cannot** provision Linux
   packages, return WordPress secrets, enable Developer, or sign an independent
   Host acceptance receipt.
5. **Exact-head Staging acceptance:** remains **not evaluated**, even if all
   other lanes appear green. Require separate GitHub CI, real MariaDB/WordPress
   fault and concurrency tests, independent Host trust, browser checks,
   certified release artifact and rollback.

### Host-only prerequisite preflight

After verifying the correct Staging host identity and a non-root worker at
Host enrollment, run this from the trusted host shell (not via WordPress):

```bash
export MAD4B_HOST_ENVIRONMENT=staging
python3 tools/mad4b_staging_host_preflight.py
python3 tools/mad4b_staging_host_preflight.py --canary
```

The environment variable is **operator-declared context**, not cryptographic
proof of site or deployment identity; the resulting
`PRECHECK_PASSED_HOST_REVIEW_REQUIRED` is not a Host acceptance certificate.
A missing binary is a blocker, not approval to install packages. An authorized
VPS/Host administrator can separately install `util-linux` and `bubblewrap`,
then run and independently review real sandbox acceptance under the PHP/Host
Runner worker identity. Shared Web/Cloud hosting may require an isolated worker
instead. No unsandboxed fallback is permitted.

### Exact post-save revalidation

Read the Site Profile revision/digest and environment after the save. Then
fetch a fresh read-only Write-only handshake and insist that exact plan binding
matches current site UUID/revision/digest/source SHA/package provenance. Do
not replay a prior plan and do not auto-apply a write grant from a read-only
MCP response. If permission or host identity cannot be proven, mark the stage
blocked and stop.

### Scope of CI

`staging-autopilot-gates-native` runs the pure policy/negative Staging tests
on PHP 7.4 and 8.3 with an isolated Python host-canary mock. Passing these
fixtures **cannot** replace live Staging, host privilege, external evidence,
or GitHub release certification.

## Single-source dynamic Host proof (new)

The preferred non-duplicating design is documented in
`docs/runbooks/single-source-live-host-identity.md`. It captures a fresh,
single-use Host identity attestation using the **existing enrolled Host
Runner's Ed25519 signer** over an OS-permissioned Unix Socket. It does not
generate a new WordPress secret or copy the Host's credential into the
Site Profile.

`mad4b/site-autopilot-status` now exposes a bounded
`host_identity` observation, including `fresh_host_identity_verified`
only for a cryptographically valid response. The legacy HMAC-backed Host
operations remain independently blocked until migrated and accepted; both
sources being active yields `blocked_multiple_host_identity_roots`.
The local signer service has not been deployed or accepted on the live
All Royal Egypt Staging host by this source change.
