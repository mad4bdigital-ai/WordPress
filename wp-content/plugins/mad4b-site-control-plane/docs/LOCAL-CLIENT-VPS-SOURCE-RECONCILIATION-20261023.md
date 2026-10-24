# VPS Local Client source reconciliation (2026-10-23)

Source: isolated ETG etg-coolify-prep/site-copy on VPS, built from MAD4B 0.4.0-rc.102 (f958565c8bb58f63f176fd49eb74bec0d0b09bd7). This imports local-only code onto the newer GitHub integration branch; no live deployment.

## Source differences

- Two new modules: includes/class-mad4b-scp-local-client.php and includes/class-mad4b-scp-local-client-admin.php.
- Three changed modules: plugin bootstrap, schema lifecycle, runtime convergence.
- The newer GitHub Runtime already enforces stronger superseded-checkpoint and identity/owner checks. Do not overwrite it with the older VPS exception.
- Backups (*.bak and *.before-*) are excluded.

## Safety and limitations

Local Client loads only when WordPress environment type is local AND MAD4B_LOCAL_CLIENT_SITE_UUID explicitly matches the enrolled UUID. That is site identity, never OAuth, operator grant, mutation authorization, developer or breakglass permission. Other environments do not register its admin hooks.

MAD4B_LOCAL_CLIENT_PROVISION_ENABLED must separately be set to the boolean true to allow admin-only application-password credential provisioning. Default is disabled. The external vault MAD4B_LOCAL_CLIENT_VAULT_PATH must be absolute and outside WordPress document root, in a writable directory with no group/world-write bits. Requests are nonce, user identity, expiry and existing permission bound.

The original VPS code lacked a producer for the pending request mad4b_local_client_pending_request. This GitHub branch adds a **local administrator-initiated request** and a separate, nonce-protected confirmation step, with a five-minute request TTL, exact user/site-UUID binding, a bounded credential-free event history, and a manual revocation action using the recorded application-password UUID. The isolated regression creates *only fake credentials* in a temporary private fixture.

This does **not** implement an external MCP agent-initiated request, a working Local Client host transport, token rotation, credential theft detection or browser/client proof. The feature remains disabled without an explicit local-only MAD4B_LOCAL_CLIENT_PROVISION_ENABLED=true setting. Do not enable or publish credentials until local owner approval, WP application-password policy review and real host-client acceptance. A dedicated least-privilege application account is preferable to an administrator credential for future production-grade local use.

For a bound local site, unauthenticated frontend GET/HEAD no longer schedules schema reconciliation implicitly. Admin, cron, AJAX, REST, WP-CLI and explicit reconciler calls keep their original paths. Existing passive WP-CLI optimization is independent.

## Required verification

- PHP syntax and isolated permission/denial contract
- No Local Client class/hook on Staging or Production
- Exact site URL/UUID, nonce, admin and mutation gate preserved
- No vault under web root, no credential creation without opt-in and pending request
- Existing MCP OAuth catalog/grants unaffected
- Exact-head CI and separate host-client acceptance before any local enablement

No database, credentials, host service, DNS, site or Production change is included.
