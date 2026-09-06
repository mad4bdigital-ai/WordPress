# MAD4B Connection Readiness Contract

Contract: `mad4b.connection-readiness.v1`

This contract defines what MAD4B may truthfully claim before a real external MCP client is connected. It is a read-only evidence surface and must not become a second mutation or credential authority.

## 1. Readiness levels are distinct

MAD4B MUST keep these states separate:

1. **Local transport ready** — the exact certified MCP Adapter is available; all four MAD4B custom servers are registered; their REST routes exist; each transport permission callback exactly matches the intended MAD4B policy surface; and MCP peer governance is inspectable with no write-side-channel blocker.
2. **Remote endpoint preflight ready** — local transport is ready and the site is using HTTPS. This is only configuration readiness.
3. **Connection certified** — a real external MCP session has established transport/authentication and the authenticated transport subject has been resolved through the MAD4B subject bridge on the target environment.

A local WordPress process MUST NOT infer level 3 from level 1 or 2. `connection_certified` therefore remains false in the local status surface until target-specific external evidence is produced by the staging certification process.

## 2. Exact MAD4B server surfaces

The control plane owns four isolated MCP server IDs:

- `mad4b-read`
- `mad4b-content`
- `mad4b-admin`
- `mad4b-breakglass`

The Connection surface MUST derive each endpoint from the runtime MCP Adapter server object via its actual route namespace and route. It MUST verify that the resulting REST route is registered and that the transport permission callback is exactly the expected MAD4B policy callback.

Expected transport permission bindings:

- `mad4b-read` → `MAD4B_SCP_Policy::can_read`
- `mad4b-content` → `MAD4B_SCP_Policy::can_content`
- `mad4b-admin` → `MAD4B_SCP_Policy::can_admin`
- `mad4b-breakglass` → `MAD4B_SCP_Policy::can_breakglass`

The read-only `mad4b/connection-status` ability is mounted only on `mad4b-read` and MUST NOT expose a credential or create one.

## 3. Authentication truth

The official MCP Adapter HTTP transport authorizes the WordPress request and then applies the custom server transport permission callback. MAD4B adds its own NHI subject binding and exact-grant authority for governed mutations.

The Connection surface MAY report non-sensitive facts such as:

- authentication method identifier;
- whether a normalized subject exists;
- whether a subject fingerprint is present, as a boolean only;
- number of exact token scopes;
- WordPress user ID for the current authenticated request.

It MUST NOT render or return raw credential material, including passwords, authorization headers, bearer tokens, application passwords, client secrets, access tokens, refresh tokens or raw subject fingerprints.

The page MUST NOT create OAuth clients, Application Passwords, NHI records, grants, approvals or tokens.

## 4. No self-probe / SSRF boundary

The WordPress admin Connection page and `mad4b/connection-status` MUST NOT perform outbound requests to their own endpoint or to a user-supplied URL.

Local readiness is derived from in-process server registration, REST route registration, provider certification and peer inventory. Internet reachability and a real MCP handshake are proved externally in T103.

This prevents a diagnostic UI from becoming an SSRF primitive or from producing misleading self-reachability evidence.

## 5. Foreign MCP transport governance

The official Adapter registry is not sufficient evidence that no other MCP transport exists on the WordPress site. Independent plugins can register their own REST MCP endpoints without appearing in `McpAdapter::get_servers()`.

MAD4B therefore MUST inspect both:

1. the official MCP Adapter server/tool registry; and
2. MCP-looking REST routes plus active MCP/model-context plugin basenames outside the known MAD4B/official Adapter pair.

A foreign independent MCP transport whose semantics and authority cannot be proven under MAD4B MUST be treated as **unreviewed privileged side-channel risk**. It MUST NOT be auto-disabled, but governed mutation MUST fail closed with:

- `mcp_foreign_transport_unreviewed`; and
- `mcp_write_side_channel_detected`.

There is no runtime filter that silently suppresses this verdict.

An independent product such as miniOrange Secure MCP Server may be useful for other workflows, but an active independent write-capable MCP plane is not accepted as the T103 MAD4B transport. On a certification Staging target it must either be disabled or be covered by a separately reviewed authority/federation design; merely having a working MCP URL is not sufficient.

## 6. Admin UX

`MAD4B Control Plane → Connection` is read-only and requires `manage_options`.

It displays:

- environment and HTTPS status;
- MCP Adapter version/certification;
- local/remote/certification readiness as separate states;
- all four runtime-derived MAD4B endpoints;
- route-registration and permission-binding evidence;
- non-sensitive current request subject facts;
- explicit external-handshake-unverified state;
- official Adapter peer status;
- foreign MCP route/plugin evidence;
- Breakglass configured/effective status.

It contains no POST handler, nonce mutation path, remote probe, configuration writer or credential material.

## 7. T103 target evidence

Repository CI can prove the implementation contract and disposable WordPress runtime behavior, but it cannot certify a real remote site.

T103 remains incomplete until a separate WordPress Staging target proves at minimum:

- environment is Staging;
- exact certified package is deployed;
- remote HTTPS endpoint is reachable from the actual MCP client;
- MCP initialization and tool discovery occur on the intended MAD4B server;
- authenticated transport subject resolves through the MAD4B subject bridge;
- no unreviewed foreign MCP write transport blocks authority;
- the existing T103 governed mutation/undo/drift/budget/audit scenarios pass.

Production write remains NO-GO until T103 and all other Production gates pass.
