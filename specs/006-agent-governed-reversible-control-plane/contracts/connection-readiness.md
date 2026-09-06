# MAD4B Connection Readiness Contract

Contract: `mad4b.connection-readiness.v2`

This contract defines what MAD4B may truthfully claim before a real external MCP client is connected. It is a read-only evidence surface and must not become a second mutation or credential authority.

## 1. Readiness levels are distinct

MAD4B MUST keep these states separate:

1. **Local transport ready** — the exact certified MCP Adapter is available; all governed MAD4B custom servers are registered; their REST routes exist; each transport permission callback exactly matches the intended MAD4B server-bound callback; and MCP peer governance is inspectable with no write-side-channel blocker.
2. **Remote endpoint preflight ready** — local transport is ready and the site is using HTTPS. This is only configuration readiness.
3. **Connection certified** — a real external MCP session has established transport/authentication and the authenticated transport subject has been resolved through the MAD4B subject bridge on the target environment.

A local WordPress process MUST NOT infer level 3 from level 1 or 2. `connection_certified` therefore remains false in the local status surface until target-specific external evidence is produced by the staging certification process.

## 2. Exact MAD4B server surfaces

The control plane owns five isolated MCP server IDs:

- `mad4b-read`
- `mad4b-content`
- `mad4b-write`
- `mad4b-admin`
- `mad4b-breakglass`

The Connection surface MUST derive each endpoint from the runtime MCP Adapter server object via its actual route namespace and route. It MUST verify that the resulting REST route is registered and that the transport permission callback is exactly the expected MAD4B server callback.

Expected transport permission bindings:

- `mad4b-read` → `MAD4B_SCP_Servers::can_read_transport`
- `mad4b-content` → `MAD4B_SCP_Servers::can_content_transport`
- `mad4b-write` → `MAD4B_SCP_Servers::can_write_transport`
- `mad4b-admin` → `MAD4B_SCP_Servers::can_admin_transport`
- `mad4b-breakglass` → `MAD4B_SCP_Servers::can_breakglass_transport`

Each wrapper MUST first bind the exact REST request route to its server ID through `MAD4B_SCP_Transport_Context`, then evaluate its underlying WordPress capability/policy. Route/server mismatch MUST fail closed and MUST NOT leave stale request-local authority.

The read-only `mad4b/connection-status` ability is mounted only on `mad4b-read` and MUST NOT expose a credential or create one.

## 3. `mad4b-write` is a governed ingress, not an authority alias

`mad4b-write` exists to give an external control client one explicit write-only MCP ingress without creating a generic execute-any primitive.

Its tool list MUST be a projection of already registered `mad4b-content` and `mad4b-admin` abilities whose actual WordPress Ability metadata contains explicit `annotations.readonly === false`.

The projection MUST fail closed:

- a missing Ability is not mounted;
- missing `annotations.readonly` is not treated as writable;
- `readonly=true` is not mounted;
- no ability name supplied by an MCP request is dynamically dispatched;
- Breakglass raw SQL is never projected into `mad4b-write`;
- provider ownership is resolved from the same source-of-truth server/adapter registry used for exact grants.

An Ability can therefore be mounted on both its specialist server and `mad4b-write`, but those are **different authority coordinates**. A grant for:

```text
mad4b-content + mad4b/content-update-post + core
```

MUST NOT authorize the same Ability when invoked through:

```text
mad4b-write + mad4b/content-update-post + core
```

The central authorization engine MUST resolve the actual bound MCP transport server **before** exact-grant lookup, budget/approval execution, and exact approval-ticket consumption. Approval tickets and audit decisions MUST bind to the effective transport server used by the request.

Direct internal/runtime calls that have no MCP transport context may continue to use their explicitly declared server ID; once an MCP transport is bound, the active transport server is authoritative and the Ability MUST be mounted there.

## 4. Authentication truth

The official MCP Adapter HTTP transport authorizes the WordPress request and then applies the custom server transport permission callback. MAD4B adds request-local transport binding plus its NHI subject binding and exact-grant authority for governed mutations.

The Connection surface MAY report non-sensitive facts such as:

- authentication method identifier;
- whether a normalized subject exists;
- whether a subject fingerprint is present, as a boolean only;
- number of exact token scopes;
- WordPress user ID for the current authenticated request;
- whether a transport server is bound and its non-secret server ID.

It MUST NOT render or return raw credential material, including passwords, authorization headers, bearer tokens, application passwords, client secrets, access tokens, refresh tokens or raw subject fingerprints.

The transport context MUST NOT retain credential/session material. It may retain only bounded request-local routing evidence needed to bind authorization to the actual governed MCP server.

The page MUST NOT create OAuth clients, Application Passwords, NHI records, grants, approvals or tokens.

## 5. No self-probe / SSRF boundary

The WordPress admin Connection page and `mad4b/connection-status` MUST NOT perform outbound requests to their own endpoint or to a user-supplied URL.

Local readiness is derived from in-process server registration, REST route registration, provider certification and peer inventory. Internet reachability and a real MCP handshake are proved externally in T103.

This prevents a diagnostic UI from becoming an SSRF primitive or from producing misleading self-reachability evidence.

## 6. Foreign MCP transport governance

The official Adapter registry is not sufficient evidence that no other MCP transport exists on the WordPress site. Independent plugins can register their own REST MCP endpoints without appearing in `McpAdapter::get_servers()`.

MAD4B therefore MUST inspect both:

1. the official MCP Adapter server/tool registry; and
2. MCP-looking REST routes plus active MCP/model-context plugin basenames outside the known MAD4B/official Adapter pair.

A foreign independent MCP transport whose semantics and authority cannot be proven under MAD4B MUST be treated as **unreviewed privileged side-channel risk**. It MUST NOT be auto-disabled, but governed mutation MUST fail closed with:

- `mcp_foreign_transport_unreviewed`; and
- `mcp_write_side_channel_detected`.

There is no runtime filter that silently suppresses this verdict.

A WordPress REST namespace index such as `/mcp` MUST NOT be allowlisted by string alone. It is ignored only when runtime proves that every callback on that route is the WordPress REST server namespace-index callback and the namespace is owned by a registered Adapter server. Any additional callback makes the route foreign again.

An independent product such as miniOrange Secure MCP Server may be useful for other workflows, but an active independent write-capable MCP plane is not accepted as the T103 MAD4B transport. On a certification Staging target it must either be disabled or be covered by a separately reviewed authority/federation design; merely having a working MCP URL is not sufficient.

## 7. Admin UX

`MAD4B Control Plane → Connection` is read-only and requires `manage_options`.

It displays:

- environment and HTTPS status;
- MCP Adapter version/certification;
- local/remote/certification readiness as separate states;
- all five runtime-derived MAD4B endpoints;
- route-registration and permission-binding evidence;
- a dedicated `mad4b-write` summary including mounted-write count and exact-transport-grant requirement;
- non-sensitive current request subject facts;
- explicit external-handshake-unverified state;
- official Adapter peer status;
- foreign MCP route/plugin evidence;
- Breakglass configured/effective status.

It contains no POST handler, nonce mutation path, remote probe, configuration writer or credential material.

## 8. Repository certification for `mad4b-write`

Repository CI MUST prove on WordPress 6.9 and current latest at minimum:

- all five MAD4B servers register with exact route/permission evidence;
- `mad4b-write` contains at least one explicitly non-readonly Ability;
- representative governed writers such as reversible content update, structured DB update and mutation undo are projected;
- representative read-only abilities are absent from `mad4b-write`;
- every projected tool has explicit `annotations.readonly === false` at runtime;
- a mismatched route cannot bind the `mad4b-write` transport;
- transport mismatch leaves no stale request-local server binding;
- an exact grant for a specialist server cannot authorize the same Ability through `mad4b-write`;
- an independent exact grant for `mad4b-write` can be stored only when the Ability is genuinely mounted there;
- the foreign-MCP and namespace-index-hijack blockers continue to pass after adding the fifth governed server.

Repository success certifies the implementation contract only. It does not prove a remote write connection on the target site.

## 9. T103 target evidence

Repository CI can prove the implementation contract and disposable WordPress runtime behavior, but it cannot certify a real remote site.

T103 remains incomplete until a separate WordPress Staging target proves at minimum:

- environment is Staging;
- exact certified package is deployed;
- remote HTTPS endpoint is reachable from the actual MCP client;
- MCP initialization and tool discovery occur on the intended MAD4B server;
- `mad4b-write` tool discovery exposes only the certified write projection;
- authenticated transport subject resolves through the MAD4B subject bridge;
- a write request proves the effective exact grant is bound to `mad4b-write`, not to an alternate specialist server;
- no unreviewed foreign MCP write transport blocks authority;
- the existing T103 governed mutation/undo/drift/budget/audit scenarios pass.

Production write remains NO-GO until T103 and all other Production gates pass.
