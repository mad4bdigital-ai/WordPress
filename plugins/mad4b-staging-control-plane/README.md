# MAD4B Staging Control Plane Remote MCP

This package is the repo-local client package for the isolated MAD4B WordPress Staging control plane. The MCP resource server is intentionally **client-agnostic**: ChatGPT/OpenAI, Claude, Gemini, Manus, and other standards-compliant MCP clients may use the same read endpoint and the same OAuth authorization server.

## Shared remote MCP endpoint

Use the same initial read-only resource for every client:

`https://staging.egypttourgates.com/wp-json/mcp/mad4b-read`

Transport: Streamable HTTP.

OAuth protected-resource metadata is published using RFC 9728, including the path-derived location:

`https://staging.egypttourgates.com/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-read`

The resource server does not authorize a request because it came from a named AI vendor. Authorization is based only on the trusted OAuth issuer, exact resource/audience, token validity, scope, subject mapping, WordPress capability, and the existing MAD4B governance layers.

## Client profiles

### ChatGPT / OpenAI

Register a custom MCP/Plugin connection with:

- Name: `MAD4B Staging Control Plane`
- Server URL: `https://staging.egypttourgates.com/wp-json/mcp/mad4b-read`
- Authentication: OAuth
- Tunnel: off

The dedicated Staging authorization server supports Dynamic Client Registration for allowlisted ChatGPT callback origins, PKCE S256, refresh-token rotation, and `offline_access` as a non-authoritative refresh capability. The protected WordPress resource itself remains `mad4b:read` only.

After ChatGPT creates the connection, copy the real technical ID beginning with `plugin_asdk_app`. Only then add `.app.json`; no placeholder ID is committed.

### Claude

Add the same URL as a Remote MCP custom connector. Claude may use OAuth discovery/Dynamic Client Registration when supported by the configured authorization server, or an explicitly registered OAuth client when DCR is not enabled.

### Gemini

Use the same Streamable HTTP MCP URL. Gemini Remote MCP callers can send a bearer access token in the configured request headers. The token still must satisfy the exact MAD4B resource/audience and `mad4b:read` scope.

### Manus

Use the same remote MCP resource through Manus connector/MCP integration when available for the account. Authentication remains OAuth/Bearer at the MAD4B resource boundary; Manus receives no vendor-specific bypass.

### Other MCP clients

Any client capable of Streamable HTTP and presenting an OAuth bearer token accepted by the configured authorization server can use the same read resource. Unknown clients do not receive broader authority.

## Staging OAuth configuration

The WordPress resource server requires these `wp-config.php` constants on Staging:

```php
define( 'MAD4B_MCP_OAUTH_ENABLED', true );
define( 'MAD4B_MCP_OAUTH_ISSUER', 'https://dev.mad4b.com/auth/mcp/wordpress-staging' );
define( 'MAD4B_MCP_OAUTH_WP_USER_ID', 123 ); // dedicated Staging admin subject
```

`MAD4B_MCP_OAUTH_WP_USER_ID` must be replaced by the existing dedicated Staging administrator user selected for the remote subject bridge. The package does not create or auto-select that user.

Do not set the following on Staging or Production during initial certification:

```php
define( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED', true );
```

The dedicated issuer is intentionally separate from the primary `mcp-dev.mad4b.com` OAuth resource profile:

- Issuer: `https://dev.mad4b.com/auth/mcp/wordpress-staging`
- RFC 8414 metadata: `https://dev.mad4b.com/.well-known/oauth-authorization-server/auth/mcp/wordpress-staging`
- JWKS: `https://dev.mad4b.com/auth/mcp/wordpress-staging/oauth/jwks`
- Protected resource: `https://staging.egypttourgates.com/wp-json/mcp/mad4b-read`
- Resource authority scope: `mad4b:read`
- Optional refresh capability scope: `offline_access`
- Access-token signature: `RS256`

The authorization server may create a separate OAuth client registration for each consuming product. Its DCR authority is confined to the dedicated WordPress Staging issuer and an explicit callback-origin allowlist; it does not enable DCR on the primary Remote MCP issuer.

The resource bridge verifies issuer, exact audience/resource, expiry/not-before/issued-at, required `mad4b:read` scope, `kid`, RS256 signature, same-origin HTTPS authorization-server discovery/JWKS, and S256 PKCE metadata. Additional non-authoritative OAuth scopes such as `offline_access` do not create MAD4B write authority. It maps a verified OAuth subject to the configured dedicated WordPress user and stores only a SHA-256 subject fingerprint plus normalized scopes in the MAD4B identity context. Raw bearer tokens are never persisted.

The RS256 private key remains only on the Staging authorization-server runtime. WordPress fetches public JWKS material and cannot mint access tokens.

## Safety boundary

The initial multi-client profile remains read-only. Do not register `mad4b-write`, `mad4b-admin`, or `mad4b-breakglass` as a generic client connection. Those surfaces remain governed separately through NHI, exact grants, approvals, budgets, and target certification.
