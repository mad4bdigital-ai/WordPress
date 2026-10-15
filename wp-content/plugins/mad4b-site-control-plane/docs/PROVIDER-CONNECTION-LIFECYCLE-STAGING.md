# MAD4B Provider Connection Lifecycle — governed remote recovery

Scope: reusable provider connection lifecycle for any enrolled MAD4B site,
with **mutating remote execution restricted to exact Staging owner-approved
operations**. First certified implementation: Google Workspace / Drive.
This is a source specification, not a certification of browser execution.

## All Royal Egypt incident (2026-10-13)

The screenshot at
`staging.allroyalegypt.com/wp-admin/admin.php?page=mad4b-control-plane-context&tab=google-drive&folder=root`
shows a saved Google grant, connected Read + Write, and
`refresh_required`. Direct `context/google-drive-status` readback:
`health_state=refresh_required`, `connected=true`,
`refresh_required=true`, `refresh_failed=false`,
`reconnect_required=false`, `revocation_pending=false`.
**Do NOT revoke or ask for new OAuth consent just because an access token
expired.** The correct action is a bounded Refresh Connection through the
saved refresh grant, followed by a new-request health readback.

## Native wp-admin UX

- **Refresh Connection** appears once in Google Connection Status when a
  connected grant needs refresh; it uses `admin-post.php` with action
  `mad4b_context_google_refresh`, administrator capability, nonce,
  explicit confirmation, and a hash of the exact encrypted grant.
- The refresh endpoint calls the existing Google refresh-token protocol,
  honors its backoff, never exposes tokens, preserves scopes, and requires
  a verified persisted `credential_fresh=true` readback.
- A transient `refresh_failed` requires a 60-second cooldown. Repeated
  requests inside the cooldown are rejected server-side, not just hidden
  by JavaScript.
- `invalid_grant`, `invalid_client` or `unauthorized_client` means
  **Reconnect with Google**, with an exact saved-grant fingerprint,
  nonce and explicit owner confirmation. OAuth consent and PKCE callback
  occur in the *same authenticated owner's browser session*, not by
  impersonating the owner with a remote MCP bearer.
- `revocation_pending` blocks refresh/reconnect; use independently
  reviewed revoke recovery. A Google HTTP 400 is not successful revocation.
- An unconfigured or disconnected provider shows its native Connect path
  only when required credentials or trusted broker enrollment exist.

## General provider architecture

`mad4b/provider-connection-discover` and
`mad4b/provider-connection-plan` are bounded read-only WP Abilities.
`mad4b/provider-connection-refresh` is a governed Staging-only write
Ability. A read-only **Provider Connections** admin page displays the
same live states and points to human handoff surfaces.

Register additional providers in
`mad4b_provider_connection_adapters` with:
- a stable provider key (lowercase slug), display label and auth kind;
- a **read-only** `status_callback` returning only typed connection
  state flags (`connected`, `health_state`, `refresh_required`,
  `refresh_failed`, `refresh_failure_at`, `reconnect_required`,
  `revocation_pending`), never raw credentials;
- an exact same-origin owner handoff URL for provider consent.

Adding metadata makes providers discoverable **only**. It does not
silently promote third-party refresh callbacks to executable remote
mutations. To certify any new refresh executor, review its credential
custody, token-rotation rollback semantics, concurrency, independent
postconditions, scope invariants and a provider-specific admission test;
then explicitly include its adapter and ability/provider grant in a
source-reviewed Staging allowlist. Deny wildcard and Production grants.

## Remote lifecycle

1. Discover provider and capabilities. Unknown provider must fail closed.
2. Read `plan({provider})`: `no_action`, `refresh`,
   `oauth_handoff` or `resolve_pending_revocation`. The plan is
   **non-authorizing**, linked to Site Profile and encrypted grant identity.
3. Refresh only: require exact plan digest, Site UUID, profile revision
   and digest, verified enrolled ChatGPT OAuth bearer, **one-time exact
   owner approval ticket**, and provider-specific reviewed executor.
4. Consume the approval and replan before any external token exchange.
   The server returns **redacted status only**, with a new-request
   independent readback still required.
5. OAuth authorization: return same-origin wp-admin owner handoff; the
   owner explicitly signs in and consents to requested scopes in their
   browser. No remote agent retrieves raw refresh tokens, authorization
   codes or client secrets.
6. Keep provider write capability independent from source-policy,
   Content Authority and NHI grant approval. Refresh never approves
   creating/modifying Drive assets.

## Acceptance matrix

Test the exact build on PHP 8.3, including
`tests/provider-connection-center-runtime.php`,
`tests/context-oauth-lifecycle-runtime.php`, provider lifecycle,
`tests/staging-write-grant-reviewed-inventory-runtime.php`, and
`tests/provider-connection-source-contract.py`.
Then certify the exact ZIP and install on each site **separately**.
Browser-check saved-grant refresh, invalid-grant owner reconnect,
pending revoke isolation, stale forms, nonce/permission denial and
independent new-request readback. Inspect OAuth scope set before/after.

No test pass, CI certification, live installation, remote refresh or
production mutation is implied by GitHub commits.
