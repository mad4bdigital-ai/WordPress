# MAD4B Staging Control Plane Remote MCP

This package is the repo-local client package for the isolated MAD4B WordPress Staging control plane.

The current externally certified target is the dedicated **ChatGPT read gateway**. Client/vendor identity never grants authority, and compatibility profiles for other clients are evidence only until their exact remote transport/resource behavior is separately certified.

## ChatGPT remote MCP endpoint

Use this bounded read-only protected resource:

`https://staging.egypttourgates.com/wp-json/mcp/mad4b-chatgpt`

Transport: Streamable HTTP.

OAuth protected-resource metadata is published using RFC 9728 at the exact path-derived location:

`https://staging.egypttourgates.com/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-chatgpt`

The broader `mad4b-read` transport remains a privileged local/diagnostic surface and is not the ChatGPT OAuth protected resource.

The resource server does not authorize a request because it came from ChatGPT. Authorization is based only on the trusted OAuth issuer, exact resource/audience, token validity, `mad4b:read` scope, issuer-bound subject mapping, WordPress capability, actual `mad4b-chatgpt` tool membership, and the existing MAD4B governance layers.

## ChatGPT / OpenAI

Create the custom MCP/Plugin connection with:

- Name: `MAD4B Staging Control Plane`
- Server URL: `https://staging.egypttourgates.com/wp-json/mcp/mad4b-chatgpt`
- Authentication: OAuth
- Tunnel: off

The WordPress-local OAuth authority supports Authorization Code + PKCE S256, refresh-token rotation, RS256 access tokens, `offline_access` as a non-authoritative refresh capability, and Client ID Metadata Documents for the exact ChatGPT identity:

`https://chatgpt.com/oauth/client.json`

CIMD resolution is fail-closed: arbitrary client URLs are not fetched, the returned `client_id` must exactly match the requested URL, redirect URIs are bounded/validated, and metadata must be compatible with public-client Authorization Code + PKCE. No client secret is created or stored by MAD4B.

After ChatGPT creates the connection, copy the real technical ID beginning with `plugin_asdk_app` if a downstream package needs that identifier. No placeholder ID is committed.

## Other MCP clients

The packaged compatibility catalog also contains Claude, Gemini, Manus, and generic MCP profiles. These profiles are non-authoritative protocol hints only.

They do **not** automatically receive use of `mad4b-chatgpt`, `mad4b-read`, or any write/admin surface. A future remote client must pass its own exact client-registration, OAuth, transport, resource-binding, and Staging certification before becoming an approved remote target.

## Staging OAuth configuration

The external/federated resource-server mode can use these `wp-config.php` constants on Staging:

```php
define( 'MAD4B_MCP_OAUTH_ENABLED', true );
define( 'MAD4B_MCP_OAUTH_ISSUER', 'https://dev.mad4b.com/auth/mcp/wordpress-staging' );
define( 'MAD4B_MCP_OAUTH_WP_USER_ID', 123 ); // dedicated Staging admin subject
```

`MAD4B_MCP_OAUTH_WP_USER_ID` must be replaced by the existing dedicated Staging administrator selected for the remote subject bridge. The package does not create or auto-select that user.

The WordPress-local standalone authority is separately enabled through `MAD4B_MCP_LOCAL_OAUTH_ENABLED` and can operate in local or explicitly configured hybrid mode. Local OAuth Production enablement remains separately gated.

Do not set the following on Staging or Production during initial certification:

```php
define( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED', true );
define( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED', true );
```

The external Staging issuer profile is intentionally separate from the primary `mcp-dev.mad4b.com` OAuth resource profile:

- Issuer: `https://dev.mad4b.com/auth/mcp/wordpress-staging`
- RFC 8414 metadata: `https://dev.mad4b.com/.well-known/oauth-authorization-server/auth/mcp/wordpress-staging`
- JWKS: `https://dev.mad4b.com/auth/mcp/wordpress-staging/oauth/jwks`
- Protected resource: `https://staging.egypttourgates.com/wp-json/mcp/mad4b-chatgpt`
- Resource authority scope: `mad4b:read`
- Optional refresh capability scope: `offline_access`
- Access-token signature: `RS256`

The resource bridge verifies issuer, exact audience/resource, expiry/not-before/issued-at, required `mad4b:read` scope, `kid`, RS256 signature, same-origin HTTPS authorization-server discovery/JWKS, and S256 PKCE metadata. Additional non-authoritative OAuth scopes such as `offline_access` do not create MAD4B write authority. It maps a verified OAuth subject to the configured dedicated WordPress user and stores only a SHA-256 subject fingerprint plus normalized scopes in the MAD4B identity context. Raw bearer tokens are never persisted.

For WordPress-local mode, the RS256 private key remains outside the WordPress web root and is never exposed through the connection surface. For external/federated mode, WordPress consumes public discovery/JWKS and cannot mint external-authority access tokens.

## Safety boundary

`mad4b-chatgpt` is read-only by construction and excludes generic filesystem/database inspection. Do not register `mad4b-read`, `mad4b-write`, `mad4b-content`, `mad4b-admin`, or `mad4b-breakglass` as the generic ChatGPT connection. Those surfaces remain governed separately through WordPress capability, NHI, exact grants, approvals, budgets, side-channel policy, and target certification.
