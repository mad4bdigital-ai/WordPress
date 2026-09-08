# MAD4B Remote MCP Client Compatibility Contract

Contract: `mad4b.mcp-client-compatibility.v3`

## Purpose

MAD4B keeps client/vendor identity non-authoritative while exposing a **dedicated bounded remote resource for the currently certified ChatGPT path**.

The privileged local/read server remains:

`https://<site>/wp-json/mcp/mad4b-read`

It can contain broad diagnostic capabilities and is **not** the OAuth-protected ChatGPT resource.

The current remote protected resource is:

`https://<site>/wp-json/mcp/mad4b-chatgpt`

Initial transport is Streamable HTTP and the initial OAuth authority scope is `mad4b:read`.

`mad4b-chatgpt` is a deliberately smaller read-only projection. Generic filesystem/database inspection and all content/write/admin/breakglass mutation surfaces are excluded from this gateway.

## Authorization invariant

Client/vendor detection is never authorization authority.

Authorization remains an intersection of:

1. configured and environment-approved OAuth resource bridge;
2. trusted authorization server selected by exact issuer;
3. valid signed access token;
4. exact `mad4b-chatgpt` resource/audience;
5. non-expired token timing claims;
6. exact `mad4b:read` scope;
7. issuer-bound subject mapping and WordPress capability;
8. request-local MAD4B transport context;
9. actual Ability membership on `mad4b-chatgpt`;
10. existing MAD4B governance.

A User-Agent, product name, `X-MCP-Client-Name`, compatibility profile, CIMD document, or registration hint can never grant authority.

## RFC 9728 discovery

For the protected resource path `/wp-json/mcp/mad4b-chatgpt`, the authoritative metadata location is path-derived:

`/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-chatgpt`

The origin-level `/.well-known/oauth-protected-resource` is a compatibility alias only and redirects to the authoritative path. It does not emit metadata claiming a mismatched resource identifier.

The internal WordPress REST metadata route remains available as secondary diagnostic evidence; standards-compliant clients should use the RFC 9728 well-known path.

## ChatGPT OAuth client identity

The WordPress-local authorization server supports Client ID Metadata Documents for the exact allowlisted ChatGPT client identity:

`https://chatgpt.com/oauth/client.json`

CIMD is fail-closed:

- arbitrary HTTPS client IDs do not trigger outbound metadata retrieval;
- the retrieved metadata `client_id` must exactly equal the requested client URL;
- redirect URIs are bounded and validated;
- Authorization Code and PKCE S256 compatibility is required;
- a public-client-compatible token method must be advertised;
- no client secret is created or stored by MAD4B;
- CIMD metadata does not create WordPress/NHI/grant/mutation authority.

Pre-registered clients remain supported for bounded Staging diagnostics/canaries. Dynamic Client Registration is not exposed by the WordPress-local authority.

## Dynamic client profile registry

Catalog: `config/mcp-client-profiles.json`

Registry contract: `mad4b.mcp-client-profile-registry.v1`

Profiles are evidence/configuration hints only. The packaged catalog includes:

- `openai-chatgpt`
- `anthropic-claude`
- `google-gemini`
- `manus`
- `generic-mcp`

These profiles describe protocol compatibility evidence only. **They do not imply that another client is certified or authorized to use the `mad4b-chatgpt` resource.** A future client can reuse the same security architecture only after its exact transport/resource/client-registration behavior is separately certified.

Future profiles can be added either by adding a bounded catalog row or through the WordPress filter:

`mad4b_scp_mcp_client_profiles`

No PHP authorization patch is required to add a profile because profiles create no authority.

Registry bounds:

- maximum 50 profiles;
- maximum 12 bounded non-regex match tokens per profile;
- only allowlisted transport/authentication enum values;
- duplicate IDs collapse fail-closed;
- `authority_effect` is normalized to `none`;
- dynamic detection is explicitly `authoritative=false`.

## Public compatibility manifest

Read-only endpoint:

`/wp-json/mad4b/v1/client-compatibility`

It exposes protocol/auth/discovery/profile hints only. It creates no credential, session, NHI, grant, approval, mutation, or Breakglass authority.

## Write boundary

Remote client compatibility applies only to the explicitly mounted read-only abilities on `mad4b-chatgpt`. `mad4b-read`, `mad4b-write`, `mad4b-content`, `mad4b-admin`, and `mad4b-breakglass` are not inherited by a compatible remote client.

Write/admin authority remains separately governed by NHI, exact transport/ability/provider grants, scopes, budgets, approvals, side-channel governance, target certification, and audit.

## Production boundary

Staging is the first certification target. Production OAuth remains separately gated and is not authorized by client compatibility metadata, CIMD resolution, profile registration, successful repository CI, or successful Staging client connections.
