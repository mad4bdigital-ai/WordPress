# Profile-driven Staging WordPress Environment Synchronization

The Site Profile is the source of desired environment, but WordPress's `WP_ENVIRONMENT_TYPE` is a host-bootstrap setting. Regular plugins cannot change it reliably inside the current request.

Newly enrolled Staging Site Profiles default to `environment_sync_mode=host_managed`; existing saved modes remain unchanged. Legacy `profile_only` stays an explicit opt-out. No profile edit by itself grants authority or executes a Host write.

For governed MCP synchronization:
1. Inspect `mad4b/site-profile-status` and confirm profile Staging, Host Runner enrollment, exact origin, deployment binding and the implicit WordPress Production default.
2. Call `mad4b/site-profile-environment-reconcile-plan` with a bound `runner_profile_id` and a reason. Its `host_plan` is derived exclusively from the saved Site Profile, never caller-supplied environment or filesystem instructions.
3. Obtain independent owner/policy approval and submit that exact plan through `mad4b/host-operation-apply`.
4. The existing, separately enrolled Host Runner processes the queued job and creates a private backup, bounded `wp-config.php` edit and signed Host receipt.
5. On a NEW WordPress request, verify the exact job via `mad4b/host-environment-sync-verification`; require WordPress reports `staging` and the Host receipt binds to the same site/profile.

Explicit Production, host config drift, missing Host Runner, absent deployment binding, or mismatched/foreign site identity block the operation. A queued job is not a verified environment change, and Production promotion is never authorized.

This feature removes manual environment-value composition during MCP planning. Automated *execution* still requires a separately certified host coordinator with prior explicit authority; a database profile value alone must not rewrite host configuration.
