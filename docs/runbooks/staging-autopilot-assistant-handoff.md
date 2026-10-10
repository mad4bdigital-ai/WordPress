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
