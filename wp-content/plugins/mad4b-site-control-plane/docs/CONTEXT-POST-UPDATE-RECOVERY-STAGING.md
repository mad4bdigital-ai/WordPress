# Context Authority after plugin update — ETG Staging

**Purpose:** distinguish a stale **admin action notice** from real persisted provider
and Context Authority blockers. Installing a plugin package is not evidence of
revocation, credential validity, brand ownership or provider activation.

## Exact affected site

Site: `https://staging.egypttourgates.com` (enrolled Staging). No Production,
Developer, Breakglass, generic option deletion or automatic Google account action.

## Implemented source behaviour

- All Context tabs show **live, read-only** reconciliation of pending Google
  revocation, unreadable local token and quarantined legacy Context ownership.
  Warnings disappear only when fresh provider/registry state no longer has
  those conditions; no persistent alert flags are blindly cleared.
- Overview no longer treats `connected=true` as healthy when
  `revocation_pending=true` (the token is retained solely for revoke retry).
- Sign in, scope expansion and Google "connected" messaging are suppressed
  while revocation is pending or the token is unreadable.
- `mad4b_error` query-string action notices now require the same short-lived,
  user/session/site-and-state-bound signature policy as success notices.
  Historic unsigned or expired query errors cease rendering; live blocker
  warnings continue to render independently.
- Nothing silently retries remote revocation during page rendering or upgrade.

## Incident readback at diagnosis

`context/google-drive-status`: `access_mode=revocation_pending`;
`read_available=false`, `write_available=false`,
`revocation_error=google_revocation_http_400`.
`mad4b/context-authority-status`: `ready=false`, zero governed sources,
one quarantined legacy source, four quarantined legacy assets.
These are **not** fixable by deleting an outdated browser notice.

## Governed recovery order after an exact-head Staging update

1. Verify signed ZIP/provenance, exact release/update approval and current
   site identity; install Staging only with rollback, then new-request readback.
2. Open Context Authority. Dismiss/expire only action notices; preserve live
   Google pending-revoke and ownership blockers. Verify read and write remain
   denied while revocation is unresolved.
3. Google: use the explicit nonce-protected **Retry revoke** action, or the
   separately approved `mad4b/google-revocation-retry` only if its original
   execution lane, exact plan, owner OAuth Step-up and one-time ticket are
   available. HTTP 400/network errors are **not** remote revocation proof.
   Never unseal/erase a pending encrypted token just to unlock reconnect.
   For persistent failure, revoke the app from Google Account connections
   using the owner's Google account, then follow a separately reviewed
   local-credential disposal and re-consent process. Owner attestation is
   not automatic provider proof.
4. Context: review legacy source and asset ownership for this exact Site
   Profile; import/rebind only with individually reviewed governed plan.
   Do not auto-promote quarantined brand material or grant unrestricted
   Drive rights. Check Context Authority ready status from a fresh request.
5. Only after OAuth, source, capability, exact grant, rollback certification
   and human approval are satisfied, certify write readiness independently.

## Third-party WordPress notices are separate

WPML "Update taxonomy hierarchy" for Programs, Transportation Types,
Location and Durations indicates translation taxonomy relationships need
a reviewed sync. Never invoke every update blindly during an MAD4B upgrade.
Rank Math PRO account not connected is a genuine separate account state;
a plugin update cannot authenticate it, and suppressing its warning would
not repair SEO integration. Confirm both using native provider controls.

## Acceptance

- PHP lint and `tests/context-live-post-update-reconciliation-runtime.php`
  on the exact candidate head.
- With pending Google revoke: no "Google connected"/Sign-in prompt,
  read/write denied, a visible recovery action, no token exposure.
- Historic `mad4b_error` without valid short-lived signed receipt is hidden;
  a new failed admin action still produces a signed error receipt.
- Once externally and locally verified cleared, stale recovery notice
  disappears automatically on new request.
- No WordPress/Google mutations during a read. No Production mutation.

**Status:** source patch and test are in PR #258; runtime acceptance, exact
artifact certification and deployment are separate. No CI PASS implied.
