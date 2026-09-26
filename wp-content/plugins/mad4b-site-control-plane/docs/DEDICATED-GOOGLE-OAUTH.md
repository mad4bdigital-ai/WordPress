# MAD4B Dedicated Google OAuth — Site Domain

Status: implemented in the WordPress Site Control Plane.

## Purpose

`dedicated_google` provides a Google Drive authentication mode that is fully independent of the MAD4B central OAuth broker.

It is intended for sites that want:

- a Google OAuth Web client dedicated to that site;
- the OAuth callback on the site's own primary domain;
- no dependency on `auth.mad4b.com`;
- site-local encrypted Google Client Secret custody;
- the same governed Drive read/read-write capability boundaries used by other modes.

## Dynamic site-domain binding

The callback is not configured as a separate broker host or subdomain.

The plugin derives the callback from the enrolled Site Profile:

`MAD4B_SCP_Site_Profile::site_origin()`

and requires:

`MAD4B_SCP_Site_Profile::origin_enrolled() == true`

`MAD4B_SCP_Site_Profile::site_urls_match_enrollment() == true`

The effective callback is:

```text
{canonical_origin}/wp-admin/admin-post.php?action=mad4b_context_google_dedicated_callback
```

Example:

```text
https://example.com/wp-admin/admin-post.php?action=mad4b_context_google_dedicated_callback
```

If the canonical origin changes or no longer matches WordPress Home URL and Site URL, Dedicated OAuth fails closed.

## Site settings

The operator selects:

`dedicated_google`

then provides a Google OAuth Web Client dedicated to this Site Profile:

- Dedicated Client ID
- Dedicated Client Secret

The Client Secret is encrypted at rest using the existing site-bound AES-256-GCM secret envelope.

Optional wp-config constants are also supported:

```php
define( 'MAD4B_GOOGLE_DEDICATED_CLIENT_ID', '...' );
define( 'MAD4B_GOOGLE_DEDICATED_CLIENT_SECRET', '...' );
```

When both constants are present, the credentials are not editable in the admin UI.

## Google Cloud configuration

Create a Google OAuth Web application dedicated to this site.

Enable the Google Drive API.

Register exactly the callback URI shown by the Site Control Plane.

The plugin requests only one of the governed Drive scope contracts:

```text
read_only  -> https://www.googleapis.com/auth/drive.readonly
read_write -> https://www.googleapis.com/auth/drive
```

No extra Google scope is silently added.

## Authorization flow

Dedicated mode runs locally:

```text
Site Profile primary domain
    ↓
Google authorization URL
    ↓
Google consent
    ↓
Site-domain callback
    ↓
Local server-to-server Google token exchange
    ↓
Encrypted site token store
    ↓
Google Drive Context provider
```

The flow uses:

- cryptographically random OAuth state;
- PKCE S256;
- exact Site Profile UUID binding;
- exact authentication-mode binding;
- exact callback binding;
- exact requested access-mode/scope binding.

The Google Client Secret is never sent to the browser.

## Refresh

Dedicated mode refreshes directly against Google's token endpoint using the encrypted dedicated site Client Secret.

It does not call:

- `auth.mad4b.com`;
- the MAD4B managed OAuth broker;
- Growth OS OAuth routes.

The token record retains:

`auth_mode = dedicated_google`

so refresh cannot silently move to another authentication mode.

## Mode switching

Changing between:

- `managed_google`
- `dedicated_google`
- `custom_credentials`

requires the current Google grant to be disconnected and remotely revoked first.

No silent mode migration is allowed while a token record exists.

## Authority separation

Google authentication does not grant MAD4B write authority.

Drive writes remain separately bounded by:

- selected governed Context source folder;
- source write policy;
- provider capability certification;
- exact NHI grant;
- one-time approval;
- mutation budget;
- audit/receipt/rollback requirements.

## Security properties

Dedicated mode explicitly reports:

```text
credential_custody = dedicated_site_managed
google_client_secret_on_site = true
central_mad4b_dependency = false
primary_domain_derived_from_site_profile = true
```

Public connection status never exposes:

- Google Client Secret;
- access token;
- refresh token;
- account identity details excluded by the public status contract.

## Relation to Managed Google OAuth

`managed_google` and `dedicated_google` share the same Google Drive Context Authority after authentication, but their credential custody is intentionally different.

```text
managed_google
  Google OAuth client secret: MAD4B central broker
  central dependency: yes

dedicated_google
  Google OAuth client secret: encrypted on the site
  callback host: Site Profile primary domain
  central dependency: no

custom_credentials
  Google OAuth client secret: encrypted on the site / wp-config
  existing custom OAuth path
```

The Growth OS Managed Google OAuth Broker is therefore optional for a site using `dedicated_google`.
