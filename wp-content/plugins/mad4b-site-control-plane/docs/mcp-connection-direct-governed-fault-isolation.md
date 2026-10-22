# MCP Direct vs Governed — fault isolation and adversarial acceptance

Status: **branch implementation, not deployed or live-certified**.
Site probes available before this change: ETG and ARE ChatGPT connector
`site_info` and `session-safe-diagnostics` returned the generic client
message `The tool failed internally`. That is **not** proof that the
MAD4B write governance engine caused the failure.

## Experimental principle

Retain the **same connector URL**, `/wp-json/mcp/mad4b-chatgpt`,
the same OAuth client, token and WordPress user, and the same stable
five optional Direct Ability definitions. This branch changes *no MCP
server IDs*: nine remain registered. The Site Profile setting selects
Direct Authenticated or Governed at **request execution**, not by
replacing the transport, discovering a second connector, or inventing a
new OAuth audience.

Direct may bypass **only** the MAD4B per-operation NHI ticket/approval
chain for explicitly reviewed post-level Abilities. OAuth must have
already verified the bearer, `mad4b:authority:step-up` must authorize
writes, and WordPress user/capability/ownership checks apply on each
call. Admin/developer/breakglass/SQL/file/plugin/user/credential tools
continue to use their original governed routes and cannot be made
direct by this profile.

New un-enrolled installs may default to `direct_authenticated` once
the OAuth transport itself is enrolled. **Already enrolled installations**
(including ETG and ARE) stay Governed unless an authorized administrator
explicitly changes the saved option. Invalid persisted options fail
closed. The no-op `GET` of the settings page does not change options.

## Actual request pipeline: source-grounded checkpoints

| Stage | Existing source seam | Failure evidence to capture |
| --- | --- | --- |
| 0. Network | Reverse proxy -> `/wp-json/mcp/mad4b-chatgpt` | DNS/TLS, WAF, HTTP 404/429/502/503/504, request/response timestamps and correlation ID, never token |
| 1. WordPress plugin and managed MU bootstrap | `class-mad4b-scp-mcp-mu-bootstrap-refresh.php`, runtime transaction gate | Plugin package SHA/version, installed plugin path, MU early-loader transaction pending or drift |
| 2. OAuth resource discovery | `class-mad4b-scp-oauth-resource-bridge.php` metadata and issuer registry | HTTPS resource, issuer metadata, supported scope, authorization/token route |
| 3. OAuth admission | `authenticate_rest_request_impl()` | `mad4b_oauth_bearer_required`, issuer/JWKS/audience/subject/client/expiration mismatch, actual status 401/403/503 |
| 4. Server registration | `MAD4B_SCP_Servers::register_servers()` | All 9 expected server IDs registered; requested target materialized; any failed adapter call |
| 5. Capability binding | `MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence()` | `capability_descriptor_binding_unavailable`, missing Ability, schema/root drift; prevent unauthorized side effects |
| 6. Required tool preflight | `MAD4B_SCP_MCP_Catalog_Diagnostics::preflight()` | Core tool DTO/schema ready, 36-tools and 98,304-byte JSON limits; optional tools may be excluded without dropping required tools |
| 7. Transport access | `MAD4B_SCP_Servers::can_chatgpt_transport()` and `MAD4B_SCP_Transport_Context::permission()` | Exact route match, WordPress reader permission, any `mad4b_mcp_catalog_not_ready` 503 |
| 8. MCP negotiation | Same route, `initialize`, `tools/list` | Protocol validation, responses, tool names and serialized schema, 401 vs 403 vs 503 vs 504 |
| 9. Tool invocation | First-party `MAD4B_SCP_Direct_Profile` | Verified bearer, WP subject + capabilities, post ownership, write-scope, profile mode and schema |
| 10. Returned result | Execution fence + WordPress persistence | Post ID, `modified_gmt`, content readback; do not blindly retry on ambiguous timeout |

### A real blocker that profile toggles cannot fix

In the reviewed master code, `can_chatgpt_transport()` returns
`mad4b_mcp_catalog_not_ready` (503) when the registered
`catalog_evidence.ready` is false, **even if the WordPress read
capability check passed**. This is earlier than a Direct post ability
execution. Blindly ignoring this condition would make the connector
appear healthy while the declared tool contract is invalid. Correct
the underlying Ability/DTO/descriptor mismatch and preserve diagnostic
evidence, not a fake `ready=true`.

OAuth resource admission can also return 503 earlier than catalog
evaluation if the managed MU bootstrap transaction is pending or the
resource bridge is not effective. An MCP policy preference must **never**
override these conditions.

## Controlled ETG/ARE Staging A/B test

Execute separately for each site and capture the exact installed
plugin package source SHA, current enrollment, origin and expected
HTTPS endpoint. Preserve the *same* token and subject where its scope
permits both tests.

1. Inspect current WordPress settings **without changing them**:
   current `MCP Access Profile`, revision, configured/derived source.
   Existing enrolled sites should report Governed.
2. Test unauthenticated request: require 401 challenge, never a usable
   `tools/call` or anonymous mutation.
3. Verify OAuth metadata, token issuer/resource audience/subject,
   `initialize` and `tools/list` on the original ChatGPT URL.
   Record first failing layer and returned code.
4. In Governed, call the already-registered read-only
   `mad4b/site-info` and `mad4b/session-safe-diagnostics`.
   Capture the full structured error (sanitized). No write test yet.
5. An authorized administrator explicitly selects Direct Authenticated.
   Refresh MCP session if catalog/materialization fingerprints changed;
   do **not** alter endpoint, host, app/client registration or token.
6. Repeat `initialize`, `tools/list`, the same two read calls,
   and verify unchanged common tool schema/required inventory.
7. With an explicitly dedicated **draft-only sandbox post** and an OAuth
   bearer containing the required direct-write scope, test
   `mad4b/direct-create-post`, readback,
   `mad4b/direct-update-post` with `expected_modified_gmt`.
   Do not publish or alter a live content item.
8. Test a read-only scope/no edit capability/anonymous user and ensure
   all write calls are denied. Test stale revision and ensure 409, not
   double-write.
9. Restore Governed using wp-admin and verify Direct writes deny.
   Record option revision, persisted readback and redacted audit.
10. Repeat on second site; compare stage results, not only a ChatGPT UI
    generic error. Do not apply to Production without separate approval.

### Evidence interpretation

- Both modes fail before OAuth: host/proxy/URL/MU or runtime loading
  fault; MAD4B mutation governance is **not** sufficient explanation.
- Both modes get 401/403 from OAuth: token metadata or identity/scope
  fault; do not bypass authentication to obtain a green result.
- Both get 503 at registration or Catalog: Ability/descriptor/DTO or
  bootstrap fault; the new profile cannot cure this by itself.
- Both read successfully, only Governed writes fail: investigate
  Governed grant/ticket/policy and provider execution gates.
- Direct reads succeed and Governed reads fail under otherwise equal
  conditions: investigate hidden coupling between non-write
  readiness and read-only transport, including registration order.
- OAuth + `initialize` succeed but `tools/list` fails: inspect
  catalog cap/size, provider source identity and serialization, not
  Write ticket policy.
- HTTP 504 after a write: unknown outcome; **read back first**,
  never blind retry. The Direct create action is non-idempotent.
- UI says `The tool failed internally`: insufficient to locate fault;
  require server-side stage/correlation and HTTP response.

## Adversarial code-review objections (must pass before rollout)

1. **One extra server breaks everything.** The prior prototype added
   `mad4b-direct`, causing server registration failures and OAuth
   isolation regression. This branch creates **no extra server**.
2. **A registered WordPress user is not proof of valid MCP OAuth.**
   Direct ability permission requires the current verified bearer and
   registered enrolled WP user. Browser admin options save via
   capability + WordPress nonce, not an MCP write callback.
3. **A read-scoped bearer could otherwise be promoted to a write**
   by toggling a stored mode. Direct content writes require a
   separate write-authorizing OAuth step-up scope.
4. **The catalog is assembled before bearer admission.** No
   registration decisions based on a yet-unverified token; profile
   permission changes are evaluated per call.
5. **Comparing different tool catalogs confounds the experiment.**
   Direct/Governed share the same optional Direct schema inventory.
   If optional capacity excludes some tools, record this explicitly;
   compare required core tool list and actual advertised set.
6. **Metadata can accidentally invoke old governed wrappers.**
   No `mad4b_governed_write_authority` or
   `mad4b_execution_boundary` is attached to the bounded Direct
   Abilities. Governed Abilities remain unchanged and protected.
7. **WordPress roles do not imply post ownership.** Both
   `permission_callback` and runtime execute must check the
   effective user's `edit_post` capability for target posts.
8. **Direct is not unlimited arbitrary WordPress mutation.**
   This first slice is posts only. Media, taxonomy, custom post types,
   plugin/theme/files/SQL, roles and OAuth configuration are not
   silently authorized or even exposed by this code.
9. **Updating a post in the same second may evade timestamp CAS.**
   Before Production rollout strengthen the revision token to
   post-content/metadata fingerprints or conditional persistence,
   and test concurrent writers. Current staging experiment must
   use a disposable draft and careful readback.
10. **Create is non-idempotent.** Even if MCP retries, never claim
    automatic exactly-once semantics or safe rollback without durable
    provider evidence. Failed network response requires reconciliation.
11. **Installing the wrong ZIP invalidates every test.** Fingerprint
    source/installed package on each site separately, including
    OPcache and MU loader generation.
12. **A plugin update can change registration ordering.** Validate
    WP Abilities API hook timing, ability categories, registered tool
    source fingerprint and required descriptor bindings under both
    WordPress 6.9 and currently supported WordPress.
13. **A 503 may be intentional fail-closed behavior.** Preserve
    registration and catalog blockers even when an operator wants
    unrestricted operation; do not mark invalid DTOs ready.
14. **Reverse proxy and client caching can mimic plugin failure.**
    Compare direct HTTP stages from a fresh authorized client with
    the connected ChatGPT app and inspect exact server deployment.
15. **A read-only MCP connector cannot be made writable from
    ChatGPT Settings by a plugin option alone.** The OAuth client
    must actually have sufficient write scope and site user rights.

## Offline CI vs live certification

An isolated PHP fixture checks mode resolution, WP capabilities,
OAuth write scope, CRUD and stale edit denial. A structural runner
checks original server IDs and optional catalog classification.
Neither confirms an installed package, endpoint reachability, external
OAuth token, chat client's behavior, production readiness or hosting
configuration. This PR is Draft until gated CI + exact-head owner
attestation. No Staging/Production mutation is performed by CI.
