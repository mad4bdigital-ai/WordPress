# MAD4B Managed Google OAuth Broker Contract

Status: WordPress client contract implemented. Broker deployment is separately governed.

## Purpose

Managed Google Sign-In lets a WordPress site connect Google Drive without storing a Google OAuth Client ID or Client Secret on that site.

The WordPress plugin supports two explicit authentication modes:

- `managed_google` — recommended; Google OAuth application credentials remain on the MAD4B broker.
- `custom_credentials` — advanced; the site operator supplies a Google OAuth Web client ID/secret.

Changing modes requires the current Google grant to be disconnected and remotely revoked first.

## Configuration

The WordPress host must define:

```php
define( 'MAD4B_GOOGLE_MANAGED_OAUTH_BROKER_URL', 'https://auth.example.com' );
```

The value must be HTTPS and must not contain user-info, query or fragment components.

The plugin derives:

```text
POST {base}/v1/google/oauth/session
POST {base}/v1/google/oauth/redeem
POST {base}/v1/google/oauth/refresh
```

No Google Client Secret is sent to or stored by WordPress in managed mode.

## Security model

Managed OAuth is a server-to-server one-time handoff.

The browser never receives a Google refresh token.

The site creates:

- cryptographically random `state`;
- cryptographically random `verifier`;
- `SHA256(verifier)` challenge;
- exact Site Profile UUID;
- canonical origin;
- dedicated WordPress callback URI;
- requested access mode and exact Drive scope.

The broker binds all session state to those values.

After Google consent, the broker redirects the browser to the WordPress managed callback with only:

- `handoff_code`;
- `state`.

WordPress then redeems the one-time handoff server-to-server by presenting the original verifier and all site bindings.

The handoff must be:

- single use;
- short lived;
- bound to exact broker session;
- bound to exact Site Profile UUID;
- bound to exact canonical origin;
- bound to exact callback URI;
- bound to exact requested scope/access mode;
- invalid after successful redemption;
- invalid after expiry;
- invalid on verifier mismatch.

## Session endpoint

Request:

```json
{
  "contract": "mad4b.google-managed-oauth-session.v1",
  "site_uuid": "<uuid>",
  "origin": "https://site.example",
  "callback_uri": "https://site.example/wp-admin/admin-post.php?action=mad4b_context_google_managed_callback",
  "access_mode": "read_only",
  "requested_scope": "https://www.googleapis.com/auth/drive.readonly",
  "state": "<random-state>",
  "verifier_challenge": "<base64url-sha256>",
  "verifier_method": "S256"
}
```

Successful response:

```json
{
  "contract": "mad4b.google-managed-oauth-session.v1",
  "session_id": "<opaque-id>",
  "authorization_url": "https://accounts.google.com/..."
}
```

The broker must not return Google tokens from this endpoint.

## Redemption endpoint

Request:

```json
{
  "contract": "mad4b.google-managed-oauth-redeem-request.v1",
  "handoff_code": "<single-use-code>",
  "session_id": "<opaque-id>",
  "verifier": "<original-verifier>",
  "site_uuid": "<uuid>",
  "origin": "https://site.example",
  "callback_uri": "https://site.example/wp-admin/admin-post.php?action=mad4b_context_google_managed_callback"
}
```

Successful response:

```json
{
  "contract": "mad4b.google-managed-oauth-redemption.v1",
  "access_token": "<google-access-token>",
  "refresh_token": "<google-refresh-token>",
  "expires_in": 3600,
  "scope": "https://www.googleapis.com/auth/drive.readonly"
}
```

The broker must mark the handoff consumed before returning success. Replay must fail closed.

WordPress encrypts both tokens at rest using its existing site-bound AES-256-GCM token envelope.

## Refresh endpoint

Managed mode cannot refresh directly against Google because the Google Client Secret is never present on the site.

Request:

```json
{
  "contract": "mad4b.google-managed-oauth-refresh-request.v1",
  "site_uuid": "<uuid>",
  "origin": "https://site.example",
  "refresh_token": "<google-refresh-token>",
  "requested_scope": "https://www.googleapis.com/auth/drive.readonly",
  "access_mode": "read_only"
}
```

Successful response:

```json
{
  "contract": "mad4b.google-managed-oauth-refresh.v1",
  "access_token": "<google-access-token>",
  "expires_in": 3600,
  "scope": "https://www.googleapis.com/auth/drive.readonly"
}
```

The broker must reject scope escalation. A read-only refresh cannot become read-write.

## Exact scopes

Managed mode preserves the existing Context Authority scope model:

```text
read_only  -> https://www.googleapis.com/auth/drive.readonly
read_write -> https://www.googleapis.com/auth/drive
```

No additional Google scopes may be silently added.

Google authentication never implies MAD4B write authority.

Drive writes remain separately gated by:

- selected governed source folder;
- source write policy;
- provider capability certification;
- exact NHI grant;
- one-time MAD4B approval;
- mutation budget;
- audit and rollback requirements.

## Disconnect

The WordPress plugin continues to revoke the Google refresh/access token against Google's revoke endpoint.

Authentication mode cannot be changed while a local token record exists, including revocation-pending or unreadable-token states.

## Required broker controls

The broker implementation must provide:

- HTTPS only;
- exact redirect/callback allowlisting;
- one-time session and handoff records;
- TTL not greater than ten minutes for browser authorization state;
- verifier challenge comparison;
- atomic redemption / replay denial;
- exact Site UUID and origin binding;
- no token values in redirect URLs;
- no token values in logs;
- no Google Client Secret in responses;
- exact scope validation;
- rate limiting;
- audit event for session creation, redemption, refresh denial/success and replay denial;
- no automatic Production write enablement.

## WordPress contracts

Authentication mode:

`mad4b.google-drive-auth-mode.v1`

Managed session:

`mad4b.google-managed-oauth-session.v1`

Managed redemption:

`mad4b.google-managed-oauth-redemption.v1`

Managed refresh:

`mad4b.google-managed-oauth-refresh.v1`

The public connection status exposes only authentication mode/custody and capability truth. Client secrets and Google tokens never appear in public status.
