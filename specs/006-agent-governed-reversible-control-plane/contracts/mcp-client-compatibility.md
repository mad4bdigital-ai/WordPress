# MAD4B Remote MCP Client Compatibility Contract

Contract: `mad4b.mcp-client-compatibility.v2`

## Purpose

MAD4B is a vendor-neutral Remote MCP resource server. The same governed `mad4b-read` endpoint may be consumed by ChatGPT/OpenAI, Claude/Anthropic, Gemini/Google, Manus, or another standards-compliant MCP client without adding vendor-specific authorization code.

The protected resource remains:

`https://<site>/wp-json/mcp/mad4b-read`

Initial transport is Streamable HTTP and initial OAuth scope is `mad4b:read`.

## Authorization invariant

Client/vendor detection is never authorization authority.

Authorization remains an intersection of:

1. configured and environment-approved OAuth resource bridge;
2. trusted external authorization server;
3. valid signed access token;
4. exact resource/audience;
5. non-expired token timing claims;
6. exact `mad4b:read` scope;
7. mapped WordPress subject/capability;
8. request-local MAD4B transport context;
9. existing MAD4B governance.

A User-Agent, product name, `X-MCP-Client-Name`, compatibility profile, or registration hint can never grant authority.

## RFC 9728 discovery

For a protected resource with path `/wp-json/mcp/mad4b-read`, the authoritative metadata location is path-derived:

`/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-read`

The origin-level `/.well-known/oauth-protected-resource` is a compatibility alias only and redirects to the authoritative path. It does not emit metadata claiming a mismatched resource identifier.

The internal WordPress REST metadata route remains available as secondary diagnostic evidence; standards-compliant clients should use the RFC 9728 well-known path.

## Dynamic client profile registry

Catalog: `config/mcp-client-profiles.json`

Registry contract: `mad4b.mcp-client-profile-registry.v1`

Profiles are evidence/configuration hints only. The packaged catalog includes:

- `openai-chatgpt`
- `anthropic-claude`
- `google-gemini`
- `manus`
- `generic-mcp`

Unknown clients fall back to `generic-mcp` if they can satisfy the protocol and OAuth requirements.

Future clients can be added either by adding a bounded catalog row or through the WordPress filter:

`mad4b_scp_mcp_client_profiles`

No PHP authorization patch is required to add a client profile.

Registry bounds:

- maximum 50 profiles;
- maximum 12 bounded non-regex match tokens per profile;
- only allowlisted transport/authentication enum values;
- duplicate IDs collapse fail-closed;
- `authority_effect` is normalized to `none`;
- dynamic detection is explicitly `authoritative=false`.

## Public compatibility manifest

Read-only endpoint:

`/wp-json/mad4b/v1/mcp-client-compatibility`

It exposes protocol/auth/discovery/profile hints only. It creates no credential, session, NHI, grant, approval, mutation, or Breakglass authority.

## Per-client OAuth registration

The preferred deployment model is one central authorization server with a separate OAuth client registration for each consuming product when the authorization server/client supports it. This preserves distinct client lifecycle/revocation while all clients target the same MAD4B protected resource and scope.

Dynamic Client Registration, when used by a client, is an authorization-server responsibility. MAD4B does not implement an ungoverned client-registration authority inside WordPress.

## Write boundary

Multi-client compatibility applies initially to `mad4b-read` only. `mad4b-write`, `mad4b-content`, `mad4b-admin`, and `mad4b-breakglass` are not automatically inherited by a compatible client. Write/admin authority remains separately governed by NHI, exact transport/ability/provider grants, scopes, budgets, approvals, side-channel governance, target certification, and audit.

## Production boundary

Staging is the first certification target. Production OAuth remains separately gated and is not authorized by client compatibility metadata, profile registration, or successful Staging client connections.
