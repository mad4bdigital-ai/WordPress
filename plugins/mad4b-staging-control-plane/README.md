# MAD4B Staging Control Plane ChatGPT Plugin

This package is the repo-local ChatGPT/Codex plugin scaffold for the isolated WordPress Staging control plane.

## Connection registration

Register the MCP connection in ChatGPT Developer mode only after the Staging OAuth resource bridge is effective.

Use:

- Name: `MAD4B Staging Control Plane`
- Server URL: `https://staging.egypttourgates.com/wp-json/mcp/mad4b-read`
- Authentication: `OAuth`
- Tunnel: off

The initial connection is intentionally read-only. Do not point this package at Production and do not register `mad4b-write`, `mad4b-admin`, or `mad4b-breakglass` as the first app connection.

After ChatGPT creates the connection, copy the technical ID beginning with `plugin_asdk_app`. Then add `.app.json` using the current OpenAI plugin-creator output/schema and add `"apps": "./.app.json"` to `.codex-plugin/plugin.json`.

No placeholder `.app.json` is committed because a fake technical ID would create an invalid package mapping.

## Staging OAuth configuration

The WordPress resource server requires these `wp-config.php` constants on Staging:

```php
define( 'MAD4B_MCP_OAUTH_ENABLED', true );
define( 'MAD4B_MCP_OAUTH_ISSUER', 'https://YOUR-AUTHORIZATION-SERVER' );
define( 'MAD4B_MCP_OAUTH_WP_USER_ID', 123 ); // dedicated Staging admin subject
```

Do not set the following on Staging or Production during initial certification:

```php
define( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED', true );
```

The OAuth authorization server must issue RS256 JWT access tokens for the exact resource:

`https://staging.egypttourgates.com/wp-json/mcp/mad4b-read`

and include scope:

`mad4b:read`

The bridge verifies issuer, exact audience/resource, expiry/not-before/issued-at, scope, `kid`, RS256 signature, same-origin HTTPS discovery/JWKS, and S256 PKCE metadata. It maps a verified OAuth subject to the configured dedicated WordPress user and stores only a SHA-256 subject fingerprint plus normalized scopes in the MAD4B identity context. Raw bearer tokens are never persisted.
