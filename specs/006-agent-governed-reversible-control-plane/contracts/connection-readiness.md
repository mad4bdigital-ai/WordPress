# MAD4B Connection Readiness Contract

Contract: `mad4b.connection-readiness.v4`

Supersedes: `mad4b.connection-readiness.v3`.

This contract defines what the MAD4B Site Control Plane may truthfully claim about WordPress MCP connectivity. Connection status is an evidence surface, not a second credential, mutation, or transport authority.

## 1. Readiness levels are distinct

MAD4B MUST keep three states separate:

1. **Local transport ready** — the exact certified MCP Adapter is available; all six governed MAD4B servers are registered; their REST routes exist; each transport permission callback exactly matches its server-bound MAD4B callback; and MCP peer governance is inspectable with no write-side-channel blocker.
2. **Remote endpoint preflight ready** — local transport is ready, HTTPS is active, and the dedicated OAuth protected-resource bridge for `mad4b-chatgpt` is configured and effective with an allowed environment and capable WordPress subject. This remains local configuration truth and does not by itself prove a real Internet client session.
3. **Connection certified** — remote preflight is ready **and** durable, non-secret evidence proves that a real external ChatGPT OAuth bearer session reached `mad4b-chatgpt`, completed MCP `initialize`, established an MCP session, then completed non-empty `tools/list` on the same session and resolved through the WordPress subject bridge.

A local WordPress inspection, WP-CLI command, cron task, repository CI job, local browser canary, or self-probe MUST NOT infer level 3 from levels 1 or 2. Before external evidence exists, `external_handshake_unverified` remains a certification blocker. Stale build/time evidence MUST fail closed instead of silently remaining certified.

Remote preflight MUST also fail closed with explicit bounded blockers when OAuth configuration is missing or invalid.

## 2. Exact MAD4B server surfaces

The control plane owns six isolated MCP server IDs:

- `mad4b-read`
- `mad4b-chatgpt`
- `mad4b-content`
- `mad4b-write`
- `mad4b-admin`
- `mad4b-breakglass`

The dedicated ChatGPT protected resource is:

`https://<wordpress-origin>/wp-json/mcp/mad4b-chatgpt`

`mad4b-read` remains the privileged broad diagnostic surface and is not the ChatGPT OAuth protected resource.

The Connection surface MUST derive each endpoint from the runtime MCP Adapter server object via its actual route namespace and route, then prove the REST route exists and the transport permission callback is exact.

Expected permission bindings include:

- `mad4b-read` → `MAD4B_SCP_Servers::can_read_transport`
- `mad4b-chatgpt` → `MAD4B_SCP_Servers::can_chatgpt_transport`
- `mad4b-content` → `MAD4B_SCP_Servers::can_content_transport`
- `mad4b-write` → `MAD4B_SCP_Servers::can_write_transport`
- `mad4b-admin` → `MAD4B_SCP_Servers::can_admin_transport`
- `mad4b-breakglass` → `MAD4B_SCP_Servers::can_breakglass_transport`

Each wrapper MUST bind the exact request route through `MAD4B_SCP_Transport_Context` before evaluating policy. Route/server mismatch MUST fail closed and MUST NOT leave stale request-local authority.

The read-only `mad4b/connection-status` ability is a bounded governance/readiness surface and MUST NOT expose or create credentials.

## 3. ChatGPT read gateway

`mad4b-chatgpt` is a bounded remote read projection. It MUST NOT expose generic filesystem/database inspection, content mutation, write/admin abilities, or Breakglass.

A successful ChatGPT connection therefore proves only the governed read path. It does not authorize mutation and does not imply that `mad4b-write`, `mad4b-admin`, or `mad4b-breakglass` are usable.

The exact ChatGPT CIMD identity is:

`https://chatgpt.com/oauth/client.json`

Local OAuth uses Authorization Code + PKCE S256 and RS256 access tokens bound to the exact `mad4b-chatgpt` resource. `mad4b:read` is the required resource scope; lifecycle/refresh support may additionally use `offline_access` without expanding resource authority.

## 4. `mad4b-write` is a governed ingress, not an authority alias

`mad4b-write` is a write-only projection of explicitly non-readonly governed Abilities. It is not a generic execute-any primitive.

The projection MUST fail closed:

- missing Ability → not mounted;
- missing `annotations.readonly` → not writable;
- `readonly=true` → not mounted;
- no ability name supplied by an MCP request may be dynamically dispatched;
- Breakglass raw SQL is never projected into `mad4b-write`;
- provider ownership comes from the same governed registry used for exact grants.

An Ability mounted on a specialist server and on `mad4b-write` has **different authority coordinates**. An exact grant for `mad4b-content` MUST NOT authorize the same Ability through `mad4b-write`.

The central authorization engine MUST resolve the actual bound MCP transport before exact-grant lookup, budget reservation, approval consumption, and effect execution.

## 5. OAuth and subject-bridge truth

The OAuth Resource Bridge MAY report bounded non-sensitive configuration/readback facts such as:

- configured/effective state;
- issuer/resource URLs;
- authoritative RFC 9728 metadata URL;
- bounded authorization-server metadata candidates;
- supported scope/algorithm identifiers;
- WordPress subject ID/capability truth;
- current request authentication method;
- booleans for subject/session fingerprint presence;
- bounded OAuth blockers.

It MUST NOT render or return passwords, authorization headers, bearer values, client secrets, access tokens, refresh tokens, private signing keys, raw subject fingerprints, or raw MCP session IDs.

The local authority and external/federated bridge remain separately gated. Production OAuth remains separately denied unless its explicit approval gate is present.

## 6. External handshake evidence

The canonical evidence contract is `mad4b.external-handshake-evidence.v1`.

Durable external evidence MAY be written by the plugin only after the following **real Staging REST** sequence succeeds:

1. request route is exactly `/mcp/mad4b-chatgpt`;
2. the request already has a cryptographically verified OAuth bearer accepted by `MAD4B_SCP_OAuth_Resource_Bridge`;
3. the bearer identifies the exact ChatGPT CIMD client and exact protected resource;
4. `initialize` succeeds and returns the MAD4B ChatGPT server identity plus tools capability;
5. an MCP session is established;
6. `tools/list` succeeds on the same session and returns a non-empty safe-read inventory.

The durable evidence MAY contain only bounded non-secret facts:

- environment;
- `server_id`;
- resource;
- issuer;
- exact client ID;
- authentication method;
- WordPress user ID;
- SHA-256 subject fingerprint;
- scope set;
- SHA-256 MCP session fingerprint;
- tool count;
- initialize/verification timestamps;
- build fingerprint.

It MUST NOT persist a bearer token, access token, refresh token, Authorization header, or raw MCP session ID.

Evidence MUST be rejected when the environment/resource/client/server/subject/session does not match, when `mad4b:read` is absent, when the tool inventory is empty/privileged, when the build fingerprint no longer matches, or when evidence exceeds its bounded age.

Repository CI MAY prove this evidence mechanism and its denial rules, but MUST NOT manufacture a successful external attestation. WP-CLI and cron are explicitly ineligible capture contexts.

## 7. No self-probe / SSRF boundary

The WordPress admin Connection page and `mad4b/connection-status` MUST NOT make outbound requests to their own endpoint, the configured issuer, or user-supplied URLs merely to claim readiness.

Local readiness derives from in-process registration, route/permission truth, provider certification, peer inventory, and bounded OAuth configuration. Internet reachability and the real external handshake are proven by the actual external client request path, not an admin-page self-probe.

This preserves the **No self-probe / SSRF boundary** while still allowing the protected-resource request path to persist non-secret evidence after a real external session has already arrived.

## 8. Foreign MCP transport governance

The official Adapter registry alone cannot prove absence of parallel MCP transports. MAD4B MUST inspect both:

1. the official MCP Adapter server/tool registry; and
2. MCP-looking REST routes plus active MCP/model-context plugin basenames outside the governed pair.

An unreviewed independent MCP transport remains privileged side-channel risk and MUST keep mutation fail-closed with:

- `mcp_foreign_transport_unreviewed`;
- `mcp_write_side_channel_detected`.

A WordPress namespace index is ignored only when its callbacks prove it is the current REST namespace-index callback for a registered Adapter namespace.

### 8.1 Reviewed non-transport controls

A route containing the text `mcp` is not automatically an MCP transport. The exact Hostinger Easy Onboarding route:

`/hostinger-easy-onboarding/v1/update-mcp-connector-banner-status`

is a reviewed UI/control endpoint, not tool discovery, MCP execution, credential issuance, or Adapter transport. It MAY remain registered and appear in a bounded `reviewed_non_transport_routes` inventory with zero transport risk.

This exception is exact. Any other unknown Hostinger or third-party MCP-looking route remains visible and fail-closed until separately reviewed.

## 9. Explicit provider MCP isolation

Provider-native MCP/AI surfaces can coexist with WordPress plugins that are otherwise required. MAD4B therefore uses bounded **Explicit provider MCP isolation** rather than disabling whole plugins.

Contract: `mad4b.mcp-provider-isolation.v2`.

Isolation rules:

- OFF by default;
- configured only when `MAD4B_MCP_PROVIDER_ISOLATION_ENABLED === true`;
- Production remains ineffective without `MAD4B_MCP_PROVIDER_ISOLATION_PRODUCTION_APPROVED === true`;
- suppress the generic official Adapter default server while effective;
- remove only exact reviewed provider MCP/control routes;
- remove only exact reviewed provider server-registration callbacks;
- never mutate the private Adapter server registry or use reflection to hide an already-created peer;
- never change provider settings, credentials, grants, approvals, mutation switches, plugin activation, or installation;
- no outbound requests;
- `unknown_routes_fail_closed=true` and unknown server callbacks remain blocking.

### 9.1 WP Media `mcp-oauth-server`

Live Staging identified the shared `wp-media/mcp-oauth` transport server `mcp-oauth-server`. Its generic Adapter execute tool can reach public write Abilities and is therefore a parallel write plane when active.

MAD4B MUST NOT allowlist those writes. While provider isolation is effective, it uses the provider-owned filter:

`wpmedia_mcp_oauth_server_enabled`

to return `false` before provider `plugins_loaded` bootstraps. This suppresses the independent WP Media OAuth transport at its source while leaving MAD4B Local OAuth unchanged.

If that provider server nevertheless appears in the Adapter registry, peer governance continues to treat it as external and fail closed.

### 9.2 Other reviewed providers

Exact bounded isolation continues for reviewed Hostinger AI Assistant, Fluent Forms, JetEngine, UAE/HFE, and ElementsKit MCP/control surfaces. In particular Hostinger AI Assistant's independent MCP transport and JWT token/revoke controls are removed while isolation is effective.

The Hostinger Easy Onboarding banner-control route described above is **not** removed by provider isolation; it is classified by peer governance as reviewed non-transport.

## 10. Admin UX

`MAD4B Control Plane → Connection` is read-only and requires `manage_options`.

It displays bounded truth for:

- environment/HTTPS;
- MCP Adapter version/certification;
- Local transport ready / Remote endpoint preflight ready / Connection certified;
- all six runtime-derived MAD4B endpoints;
- route/permission binding;
- OAuth resource-server readiness;
- `mad4b-write` projection/readiness;
- current non-secret request subject facts;
- provider isolation and peer governance;
- reviewed non-transport and unreviewed foreign routes;
- external handshake evidence state;
- Breakglass configured/effective state.

It contains no POST mutation handler, remote self-probe, credential writer, or raw credential material.

## 11. Repository certification

Repository CI MUST prove on WordPress 6.9 and current latest at minimum:

- all six MAD4B servers register with exact route/permission evidence;
- local transport readiness remains independent from OAuth readiness;
- missing OAuth configuration blocks remote preflight explicitly;
- `mad4b-chatgpt` remains the exact protected resource and safe-read projection;
- `mad4b-write` contains only explicitly non-readonly projected Abilities;
- transport mismatch cannot reuse a specialist grant through `mad4b-write`;
- foreign MCP and namespace-index-hijack detection remain fail-closed;
- the exact Hostinger banner-control route is reviewed as non-transport while an unknown MCP-looking route still blocks;
- provider isolation is ineffective by default;
- explicit Staging isolation suppresses the default Adapter server, exact reviewed provider callbacks/routes, and the WP Media `mcp-oauth-server` through its official kill switch;
- unknown provider routes/callbacks remain visible/blocking;
- external handshake evidence cannot be captured through WP-CLI/cron/self-probe;
- external evidence contains no credential/session material and is bound to the exact ChatGPT client, resource, Staging environment, subject, session hash, tool scan, and build fingerprint;
- stale evidence fails closed;
- Production isolation remains ineffective without its second explicit approval gate.

Repository success certifies implementation/denial behavior only. It does not itself produce a successful external handshake.

## 12. T103 target evidence

T103 remains incomplete until the actual WordPress Staging target proves at minimum:

- environment is Staging;
- exact certified package is deployed;
- the protected resource is exactly `https://staging.egypttourgates.com/wp-json/mcp/mad4b-chatgpt`;
- RFC 9728/RFC 8414 discovery is externally reachable and PKCE S256 compatible;
- the exact ChatGPT CIMD client completes OAuth authorization;
- issued bearer `aud`/resource is exactly `mad4b-chatgpt` and includes `mad4b:read`;
- remote HTTPS endpoint is reached by ChatGPT;
- authenticated subject resolves through the MAD4B subject bridge;
- ChatGPT completes MCP `initialize` and non-empty `tools/list` on the same session;
- resulting durable external handshake evidence is verified, current-build-bound, and secret-free;
- `mcp-oauth-server` is absent while provider isolation is effective;
- the Hostinger banner-control endpoint is retained but classified non-transport;
- no unreviewed foreign MCP write transport remains;
- `connection_certified=true` only after all remote preflight blockers and external-handshake blockers are empty.

Write enablement, agents, grants, approvals, mutation/undo, budgets, and audit scenarios remain a separately approved phase.

**Production write remains NO-GO** until T103 and all other Production gates pass.
