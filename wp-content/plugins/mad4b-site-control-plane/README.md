# MAD4B Site Control Plane

Companion plugin for the official `WordPress/mcp-adapter`. The upstream adapter owns MCP protocol/session/transport; MAD4B registers explicit WordPress Abilities and mounts them only on isolated custom MCP servers.

Read diagnostics use snapshot-aware `mad4b.read-consistency.v1`: one runtime generation, fixed bounded bundles, a compact metadata envelope, safe same-generation resume after reconnect, and fail-closed invalidation when build/provider/profile identity changes. Repeated session termination opens only a request-local read breaker; mutations are never replayed automatically after transport loss.

Current plugin version: **0.4.0-rc.89**.

### Explicit endpoint diagnostics

Connection > MCP Endpoints always renders the bounded snapshot. Deferred registration, route, permission and mounted write-tool measurements display **Not checked**, with the surface and endpoint URL still present. The diagnostic defaults to `mad4b-chatgpt`; selecting All endpoints runs signed administrator AJAX jobs serially, with only one MAD4B tool catalog materialized per request. On governed sites each job uses the same request-local REST isolation as the real MCP transport.

The browser stops after 15 seconds, including response-body reads, and stops on gateway, authentication, malformed-result or build-change errors. Completed results remain visible; subsequent jobs and automatic retries are blocked. A browser deadline cannot terminate a PHP callback blocked inside a provider or MU plugin. Each completed job exposes bounded required-tool preflight failures and observed tool counts without executing a tool, creating credentials or granting authority. Endpoint inspection does not certify the external connection or clear foreign-transport governance blockers; those checks remain separate.

### rc.88 protocol bootstrap, recovery and live-acceptance hardening

rc.88 moves unrelated REST and admin-AJAX traffic onto an entry-point zero-touch kernel before the full Control Plane class graph is loaded. Exact MAD4B MCP/OAuth requests keep WordPress REST defaults, MAD4B and the official MCP Adapter, but prune proven third-party plugin REST registrars request-locally with bounded callback/evidence limits and fail-open handling for unknown, Core or MU-plugin provenance. Compact ChatGPT/enrollment/Developer transports also avoid instantiating the full provider Adapter Registry.

Protocol and passive ChatGPT/Connection admin requests return through a reduced Control Plane boot after the minimum identity/OAuth/catalog hooks are bound. Normal provider, mutation, schema, filesystem and browser-canary lifecycles remain available on their owning surfaces.

Exact enrolled Staging sites with Write + managed-runtime enabled gain a governed WP-Cron recovery update lane that resolves only the signed immutable release channel, verifies the package, uses the shared maintenance lease, exact readback and rollback, and performs no persistence at all when the recovery lane is ineligible (including Production). The external ETG diagnostic now runs on master pushes and requires exact deployed runtime identity plus the existing 8-second hotpath ceilings; pull-request deployment drift remains diagnostic-only.

The recovery cron exists only after rc.88 is loaded. A runtime that already contains the candidate-drift bootstrap can use the governed WordPress admin updater even when MCP is unhealthy and prior governed-write authority is fail-closed solely because its exact candidate binding is stale. That bounded bootstrap is Staging-only, requires an exact enrolled profile plus a clean persisted grant/subject snapshot, rejects active continuation permits, Breakglass, Production, missing/stale/broad/duplicate/wildcard grants and any other authority blocker, and still verifies the immutable signed release, archive, maintenance lease, backup, exact readback and rollback. It performs no grant, subject, authority or candidate-binding mutation and creates no carry-forward continuation permit; after replacement, Runtime Convergence leaves authority binding owner-gated until the exact new candidate is explicitly rebound. A site already running a pre-fix build that is blocked before it can load this logic still requires one external/manual package deployment of a build containing the fix; the bootstrap prevents recurrence on subsequent governed Staging releases.

### rc.87 shared REST / MCP / passive-admin hotpath hardening

rc.87 closes three request-serving costs proven by the live rc.86 evidence and the cross-site 504 reproduction:

- reviewed JetEngine MCP REST registration callbacks are suppressed before ordinary REST dispatch when provider isolation is effective, retained request-locally, and materialized only by the governed internal JetEngine handoff;
- exact MAD4B MCP/OAuth protocol REST requests keep REST default filters, MAD4B transport registration, the official MCP Adapter and provider/plugin callbacks, but skip only WordPress Core `register_initial_settings` and `create_initial_rest_routes` materialization for that addressed protocol request;
- passive ChatGPT and Connection readiness/oauth/isolation/certification admin pages no longer retain the full MCP runtime or Query Monitor shutdown evidence work; `Connection > Endpoints` remains the explicit deep diagnostic surface.

The optimization is request-local and deny-only. It does not deactivate providers, replay generic `rest_api_init`, hide unknown provider callbacks/routes, change provider settings, widen authority, mutate Production, or use raw SQL.

### rc.86 Connection / ChatGPT admin 504 hotpath hardening

rc.86 keeps the normal Connection and Connect-to-ChatGPT wp-admin screens inside the read-hotpath budget. Ordinary rendering now uses identity-only provider/OAuth projections, persisted handshake evidence and request-local memoization instead of provider integrity hashing, REST route inventory, physical OAuth-store probes or repeated deep reconnect status. The explicit MCP Endpoints diagnostic tab retains deep verification.

Connection and ChatGPT status pages are also excluded from Runtime Convergence trigger detection: opening a read-only status screen can no longer revive or schedule post-update convergence work. MCP/OAuth protocol requests keep their existing fail-fast restart/maintenance guards. No Write, Production, Developer, Breakglass, raw-SQL or credential authority is widened.

### rc.85 post-update reconnect bottleneck hardening

rc.85 serializes post-update maintenance behind one shared lease, suppresses duplicate Schema Lifecycle work during governed self-update, adds a bounded restart grace barrier before Runtime Convergence, classifies MCP/OAuth hotpaths earlier, and returns deterministic retry guidance instead of allowing first reconnect attempts to compete with lifecycle work until a gateway timeout.

### rc.83 universal governed WordPress operations

rc.83 introduces a provider-neutral operation registry, unified plugin transaction planning, dependency/certification impact projection, declarative provider transport descriptors, durable reconnect guidance, and explicit site-vs-network plugin lifecycle scope. Existing exact planners/executors remain the mutation authority; the orchestration layer does not add a generic shell, arbitrary operation IDs, arbitrary package locations, approval bypass, or Production mutation. Unknown provider transports remain visible to fail-closed Peer Governance.

### rc.83 dynamic governed content orchestration

rc.83 adds a provider-neutral content orchestration layer that discovers the live WordPress content model at runtime instead of hard-coding site-specific CPT, taxonomy or term names. The new model discovery exposes registered post types, supports, attached taxonomies, existing terms on demand and registered post meta. A single governed content bundle can create or update any eligible post type, assign arbitrary attached taxonomies, set bounded non-protected meta, set featured media, perform exact readback and run a bounded convergence loop. The orchestration mutation surface is intentionally draft/pending-only: publishing or mutating an already-live post is a separate governed action after acceptance, preventing validators from rolling back content that has already emitted publication hooks or become visible.

The convergence loop is registry-driven rather than hard-coded. Trusted code may register validate/repair/accept stages; persisted settings can enable, disable, order and parameterize those stages and attach bounded conditions such as environment, post type, status, required meta, taxonomy or finding code. Settings never contain executable callbacks. The default structural validation, site-policy hook, safe repair and final acceptance stages can therefore be extended without changing the core orchestrator. Pipeline settings are available in wp-admin and through governed reversible abilities.

rc.83 also fixes the nested ChatGPT write-dispatch scope boundary. Once `mad4b/write-execute` has bound an exact target and schema request-locally, that nested target may cross only the OAuth transport-scope check; NHI grant, exact approval, budget, policy, provider, commit-guard, audit and rollback enforcement remain independent. Missing approval now reaches the approval layer instead of being misreported as a generic OAuth scope failure. No generic write OAuth scope, Production auto-write, Breakglass, raw SQL or wildcard grant is introduced.

### rc.82 session-safe Full Staging Authority handshake

rc.82 replaces the direct ChatGPT status+plan fan-out for Full Staging Authority
with one compact generation-fenced handshake. The handshake computes the exact
internal plan once, verifies that runtime generation did not change while the
plan was prepared, and returns only bounded readiness/blocker data plus the
exact apply identity fields required by the composite mutation. The response is
capped at 8 KiB.

Deep Full Staging Authority status and plan remain available through governed
read dispatch for deliberate diagnosis, but they are no longer projected as
direct ChatGPT read tools. This reduces MCP session pressure without weakening
exact-plan matching, OAuth step-up, Site Profile binding, candidate binding,
audit, Developer isolation, or the no-raw-SQL Production-safe boundary.

### rc.81 exact WP All Import read runtime bootstrap

rc.81 ports the previously reviewed WP All Import REST/MCP read-runtime repair onto the consolidated rc.80 runtime-integrity line. The adapter may bootstrap only the fixed read-model allowlist when the exact composite provider certification and import-package integrity manifest are valid. Loading is delegated only to provider-owned autoloaders; no caller-controlled path, vendor require, filesystem scan, provider execution, cron/network call or mutation is introduced.

The write operations `run-import` and `run-export` remain unmounted and fail closed. The release preserves rc.80 portable OAuth continuity, provider-isolation bootstrap and updater observability unchanged.

### rc.80 portable read-only provider isolation bootstrap

Fresh non-Production installations can complete the tenant-neutral portable read-only ChatGPT/OAuth bootstrap without requiring a pre-existing Site Profile merely to suppress already-reviewed provider-native MCP side channels. When portable read-only is effective on local, development or staging, provider isolation may auto-enable its deny-only gates and suppress only the bounded reviewed provider MCP registrations/routes already covered by the isolation contract. Unknown MCP routes and callbacks remain visible and fail closed.

This bootstrap does not create a Site Profile, Agent, grant, approval, write authority, Developer authority or Breakglass authority, and it does not enable mutation. Explicit operator disables still win. Production continues to require its separate approval boundary.

### rc.80 runtime identity + updater observability hardening

rc.80 fixes three fail-open/hidden-state classes found during post-rc.79 review. Portable read-only OAuth now boots only after Upgrade Continuity has finished and the final Site Profile has been re-read. A stored invalid Site Profile or blocked continuity recovery can no longer be silently masked by falling back to portable OAuth. The portable path remains zero-write and non-authorizing.

The WordPress Plugins update UI also stops failing silently. Administrators now receive an explicit retry action when the governed release manifest cannot be verified, an explicit policy-blocked state when the environment disallows native self-update, and a normal update action only when the fixed release manifest is valid and newer/different. The update coordinator still does not inject WordPress core update transients or enable automatic updates; package provenance, SHA-256 verification, backup, readback and rollback remain mandatory.

### rc.79 resilient native update UI

The Plugins screen now registers both the exact plugin-file hooks and a realpath-bound generic fallback for the manual governed update action. This keeps the visible `Update MAD4B…` / `Update now` affordance available when deployment paths, symlinks, or renamed release directories cause WordPress's plugin basename to differ from the loaded Control Plane file. The fallback is exact-file-bound, deduplicated, and still routes through the fixed Release-Verdict manifest, archive/provenance verification, backup, exact readback and rollback path. WordPress core update transients and automatic plugin updates remain untouched.


### rc.78 approval planner preflight truth preservation

rc.78 validates and canonicalizes remote `mad4b/approval-plan` input before WordPress Ability execution. This preserves exact server/provider/agent/context blockers that WordPress would otherwise collapse into `ability_invalid_permissions`, and classifies permission-stage failures as `mutation_state=not_started` with no reconciliation requirement. The execution-time guard remains authoritative and runs again before creating a pending ticket; this change does not widen write, Production, Breakglass, provider, or approval authority.

Provider version drift remains fail-closed. In particular, a newer JetEngine build is not promoted by changing a version string: bounded reversible writes must use the existing artifact-bound behavioral recertification path with verified mutation, exact rollback and terminal approval evidence before they become write-eligible.

### rc.78 Portable zero-authority ChatGPT read connection

rc.78 makes a fresh installation connection-ready for the dedicated `mad4b-chatgpt` read projection without creating a governed Site Profile. The portable bootstrap derives the current HTTPS origin and existing WordPress Administrators, enables only local OAuth/CIMD/PKCE read identity, and publishes RFC 9728 discovery for the site-local MCP resource. Production uses the same portable read-only boundary; governed write, Skills authoring, Developer, Breakglass, raw SQL and generic filesystem/database surfaces remain disabled until separately enrolled and authorized.

Existing Site Profiles retain precedence and behavior. Explicit operator OAuth disables/mode/issuer settings remain fail-closed, and `MAD4B_SCP_PORTABLE_READONLY_AUTO_CONNECT=false` disables the portable bootstrap for hosts that require manual enrollment.

### rc.77 Session-safe composite diagnostics

rc.77 removes the need for large parallel MCP status fan-out. The new `mad4b/session-safe-diagnostics` read ability runs the fixed identity/runtime/certification/providers diagnostic sequence inside one generation-fenced WordPress request, returns only bounded allowlisted summaries, and enforces a hard 16 KiB response cap with deterministic summary/digest reduction. Runtime generation drift invalidates the whole report. The ability is read-only, non-authorizing, and cannot mutate Production.

Detailed diagnostic abilities remain in the governed logical/read catalog for deliberate single-scope follow-up, but broad status tools are no longer projected directly onto `mad4b-chatgpt`. Composite health checks use the session-safe report; deeper inspection uses `mad4b-read` or one `read-execute` target at a time.

### rc.76 Brand materialization provider preflight and bounded failure evidence

rc.76 hardens generated Brand Core materialization without widening provider authority. Before a Brand draft can create a Drive file, the governed source folder must now return an explicit `capabilities.canAddChildren=true` decision. Missing or denied folder-create capability fails closed before the provider create request.

Provider-create failures also emit bounded audit evidence containing only the internal provider error code, HTTP status, sanitized provider code and provider-effect state. The existing durable uncertainty model remains unchanged: uncertain provider outcomes still reconcile by exact provider identity, never auto-retry a create, and require verified no-effect before a fresh governed materialization request.

### rc.75 WordPress Ability permission contract + release-identity closure

rc.75 follows the merged #143 governance fix and gives that live-defect repair a distinct immutable release identity instead of publishing different source under the already-issued rc.74 version. It also executes the real central permission wrapper in CI against the WordPress 7.1 `bool|WP_Error` contract: only the exact `allowed=true` + `reason_code=preflight_allowed` structured decision becomes `true`; booleans and `WP_Error` retain their semantics and all other non-boolean values fail closed.

The #143 reconciliation schema change remains runtime-bounded by the reviewed Ability allowlist, exact provider mapping, exact live inventory fingerprints and exact missing-set equality. No Production, Breakglass, raw-SQL, wildcard-grant, approval or retry authority is widened.

### rc.74 Developer host hardening + maintainability closure

rc.74 centralizes Developer host capability discovery in the non-authorizing `mad4b.developer-host-capabilities.v1` component. Runtime status keeps every rc.73 compatibility field while adding a deterministic capability fingerprint plus explicit readiness/blocker sets for the bounded process backend, default-deny no-network execution and protected-workspace PHP lint. Absolute executable paths are never exposed by the snapshot, and execution behavior remains fail-closed when required host controls are unavailable.

The refactor does not widen Developer, Breakglass, governed-write, OAuth or Production authority. Existing subprocess execution still uses the same exact approval, grant, runtime-binding, resource-limit, network and audit boundaries. The deployment handoff is aligned with the live multi-channel implementation and certifies WordPress-native manual update, governed bounded file upload and governed manifest-derived native release pull.

### rc.73 same-app normal Developer dispatcher

The compact `mad4b-chatgpt` resource can now discover, inspect and execute the **normal** Developer Plane through `mad4b/developer-discover`, `mad4b/developer-info` and `mad4b/developer-execute`. The dispatcher does not mount raw Developer tools on the ChatGPT resource and does not mint `server:mad4b-developer` scope. Instead, an already-provisioned Staging Developer Agent is derived request-locally from the enrolled normal OAuth subject plus exact client fingerprint while one exact schema-bound target executes. The overlay is cleared in `finally` and never persists credentials or authority.

Normal Developer mutations remain human-only by default. rc.73 adds two tightly bounded Staging exceptions for the protected Developer Workspace: source batches and exact-plan plugin promotion may use the existing `ai_autonomous` approval lane. Promotion stays `risk_tier=high` and requires a deterministic promotion-plan SHA, exact workspace manifest, exact installed-target manifest or explicit absence, PHP parse validation, protected backup, exact file-set readback and rollback. The AI Approver NHI must be distinct from the Developer Executor Agent; the exact Developer Agent grant, one-time ticket, budget, commit guard, source/site/environment binding and Developer runtime guard still apply. Production, Developer Breakglass, raw SQL and generic shell execution remain excluded from the compact dispatcher.

Provider-gap closure is zero-touch and non-authorizing. The package embeds exact-head repository evidence plus `functional-gap-policy.json`; `mad4b/functional-gap-runtime-evidence` performs bounded local runtime collection, fixed-point drift checks, and deterministic evaluation without shell, WP-CLI, raw SQL, remote requests, credential reads, or mutation. Evidence readiness never grants provider write authority or Production activation. Provider capability diagnostics also distinguish mounted from latent capabilities and read readiness from blocked write certification.

> Repository CI certification is not live-site certification. The PR remains Draft until the exact target WordPress deployment passes the target acceptance contract.

Operator deployment, authority reconciliation, recovery, rollback and lifecycle guidance: [`docs/RELEASE-AND-OPERATOR-RUNBOOK.md`](docs/RELEASE-AND-OPERATOR-RUNBOOK.md).

Governed WP All Import / Export planning, exact identity, dry-run, classification, receipt and rollback boundary: [`docs/BULK-CONTENT-IO-CONTRACT.md`](docs/BULK-CONTENT-IO-CONTRACT.md).

### rc.72 governed nested-write authorization and reconciliation closure

rc.72 closes two live Staging defects found during ETG acceptance without widening authority. First, the central authorization execution-boundary filter is booted before WordPress Abilities are materialized, so nested `mad4b/write-execute` calls cannot bypass the selected target's governed authorization, approval, budget and commit-guard boundary. Write-runtime certification also fails closed when any projected write Ability lacks the exact execution-boundary or governed-authority metadata.

Second, durable Brand Context reconciliation treats a late observer arriving after `released_verified_no_effect` as a terminal idempotent readback instead of a reconciliation-state error. This removes the scheduled/manual observation race without replaying provider creation. Mutation resilience also distinguishes a proven pre-target dispatch rejection (`mutation_state=not_started`) from a target outcome that is genuinely unknown; blind retry remains denied and a fresh exact plan is required after dispatch repair.

### rc.72 approval expiry reconciliation + processed DB attribution

rc.72 closes two live Staging recovery/diagnostic gaps without widening authority. Approval-plan reconciliation now treats pending and approved tickets as time/build sensitive: an approved ticket past its TTL becomes effectively expired, an approved ticket bound to an old candidate becomes stale, and expired/revoked/stale history no longer creates a false active-duplicate blocker when one fresh exact ticket exists. Multiple live exact tickets still fail closed. Claim-time TTL enforcement remains unchanged.

The release also preserves the existing front-end performance budgets while backfilling Query Monitor database attribution after collector processing. The exact matching request sample can receive bounded query counts/timings, sanitized caller/component aggregates, slow/duplicate statistics and SHA-256 query fingerprints from the processed `db_queries` collector. Raw SQL is never persisted or returned.

### rc.71 governed remote updates for any installed plugin

rc.71 adds a separate `mad4b/plugin-remote-update-plan` → `mad4b/plugin-remote-update-apply` path for installed plugins that expose a live WordPress update offer. The caller supplies only the installed `plugin_file` and reason; target URL, target version, package bytes and hashes remain server-resolved. Planning downloads the offer through WordPress safe HTTP handling, requires HTTPS, rejects unsafe ZIP paths/symlinks/root drift, verifies the exact plugin main file + Version header, computes the package SHA-256 and full archive-file manifest digest, and binds those values into the plan. Apply revalidates the same exact artifact, requires the normal one-time approval ticket, creates a protected rollback backup, preserves activation state and performs file-level readback. Control Plane, MCP Adapter and policy-protected dependencies stay on their dedicated update paths. Production, arbitrary URLs/paths and blind retry remain denied.

### rc.70 session-resilient metadata reads

rc.70 hardens connector reads against MCP session termination without weakening mutation safety. Repeated `session terminated` failures consume a bounded retry budget, open a request-local read circuit breaker, stop additional fan-out, and direct the client to reconnect, re-read the snapshot header, and resume only when the exact `runtime_generation` still matches. Mutation and enrollment execution remain non-replayable.

The release also adds `mad4b/read-metadata-envelope`, a compact generation-bound micro-read for one Ability or governed operation. It is mounted on `mad4b-read` and intentionally kept out of the compact direct `mad4b-chatgpt` tool list; ChatGPT reaches it through the existing governed `mad4b/read-execute` dispatcher. For governed enrollment operations it returns the canonical registration, dispatch-policy and input-schema digests accepted by execution, plus snapshot/runtime identity and an execution-binding digest in one response. This replaces fragile multi-call `discover → info → schema` metadata chains while avoiding a larger `tools/list`. No persistent session breaker, persistent authority cache, or Production authority is introduced.

### rc.70 repeated-preflight governance envelope retention

rc.70 closes the remaining live hidden-target gap found after rc.69: WordPress/MCP can evaluate the write-dispatch permission callback more than once in the same request, and a later sanitized preflight previously cleared the request-local governance envelope before target execution. The dispatcher capture is now idempotent within one request: metadata-free repeated preflights preserve the already captured exact Approval Ticket/Context Receipt, identical governed preflights are accepted, and any attempted rebind to different governance metadata fails closed. The envelope is still consumed once when forwarded to the hidden target. No grant, OAuth scope, Production, Breakglass, raw-SQL, or generic write authority is widened.

### rc.69 governed write-dispatch envelope continuity

rc.69 closes the hidden-target handoff gap discovered during the first live AI-approved certified package execution. `mad4b/write-execute` captures only the bounded request governance envelope during permission preflight, binds an exact approval ticket through the existing request-local Identity Context, and forwards the approval ticket plus governed Context Receipt to the selected hidden target after the outer provider envelope is stripped. Conflicting outer/inner governance metadata fails closed before target execution. The selected target still performs its own exact permission preflight, NHI/grant/scope/provider/budget/approval checks, execution-boundary claim, readback and one-time ticket finalization. Production, Breakglass, raw SQL, wildcard grants and generic write authority remain unchanged and denied outside their existing policy.

### rc.68 live-truth AI approval projection closure

rc.68 preserves the bounded AI approval policy fields when effective write authority is re-projected through Live Truth. This closes a certification-only false blocker after candidate reconciliation: `mad4b/approval-ai-decide` remains a bounded Staging standing exception, while Production, Breakglass, raw SQL and wildcard authority remain denied. No grant widening or automatic mutation is added.

### rc.67 deterministic approval planning + AI approval lanes

rc.67 keeps the rc.66 bounded ChatGPT write-dispatch authority unchanged and makes approval planning deterministic: planner validation failures return a safe blocker code plus an explicit reconciliation requirement instead of being collapsed into an ambiguous generic mutation error. No automatic retry is added.

The same release adds `mad4b.operation-classification.v1` and the read-only `mad4b/operation-classify` ability. Operations are classified independently by operation type, mutation kind, side-effect scope, risk tier and approval lane. Read-only abilities are `observe` / `read` with `approval_lane=none`; governed mutations are then separated into content, provider configuration, certified package, system administration, recovery, governance decision and exceptional classes. Staging mutations that remain inside the exact Site Profile/build/provider boundary may use the `ai_autonomous` lane through `mad4b/approval-ai-decide`; the AI decision is a separate step after planning and before execution, bound to the exact ticket payload and classification SHA-256. Human approval remains available as fallback. Production, Breakglass, raw SQL, Developer Breakglass and exceptional-class operations remain human-only and cannot be auto-approved.

### rc.66 bounded ChatGPT write-dispatch scope

rc.66 fixes the ChatGPT `mad4b/write-execute` transport envelope so its exact `mad4b-chatgpt` transport NHI grant can satisfy the OAuth scope boundary without requiring a second mutation ticket for the dispatcher itself. Delegation is Staging-only, requires the live governed-write authority and exact ChatGPT transport context, validates the target schema digest, and accepts only an Ability already certified and mounted on `mad4b-write`. The selected target still performs its own exact provider/grant/budget/Context/one-time approval authorization; Production, Breakglass, raw SQL, wildcard grants, generic write scope and dispatcher recursion remain denied.

### rc.59 lineage consolidation

rc.59 consolidates the previously divergent provider/zero-touch, Developer + Full Staging Authority, and rc.58 ChatGPT hotpath lineages. It retains route-targeted MCP materialization and request-local non-persistent catalog caching, restores plan-bound transactional Staging grant reconciliation v2 with persistence rollback, restores zero-touch runtime-census binding, and keeps Developer/Developer Breakglass on isolated non-Production MCP resources. Generic Raw SQL Breakglass remains a separate disabled-by-default surface and is not included in Full Staging Authority.

### ChatGPT MCP refresh hot path

rc.58 keeps the compact rc.57 transport catalog and additionally removes two request-time costs that were still paid before `tools/list` could return. On an HTTP request to one MAD4B MCP route, every MAD4B route is still registered, but only the addressed server materializes its Ability-to-MCP Tool DTOs; sibling servers are route stubs for that request and are fully materialized when addressed by their own request. Request-local catalog/provider memoization is active during MCP server construction as soon as the WordPress Abilities registry is complete. Skill seed/provider reconciliation is kept off generic MCP transport requests and remains on activation, explicit Control Plane admin lifecycle, and WP-CLI. No cross-request catalog cache or authority shortcut is introduced.


## MCP surfaces

MAD4B now owns nine governed custom MCP server IDs:

- `mad4b-read` — privileged discovery and diagnostics.
- `mad4b-chatgpt` — compact ChatGPT-safe discovery/dispatch gateway.
- `mad4b-enrollment` — bounded Site Profile, candidate-binding, and authority bootstrap surface.
- `mad4b-content` — specialist content/provider mutation surface.
- `mad4b-write` — unified governed write ingress containing only already-registered Abilities with explicit runtime `annotations.readonly === false`.
- `mad4b-admin` — specialist administrative repair/governance surface.
- `mad4b-developer` — isolated non-Production Developer Agent plane.
- `mad4b-developer-breakglass` — separately gated non-Production Developer recovery plane; disabled by default.
- `mad4b-breakglass` — exceptional generic raw SQL recovery; disabled by default and excluded from Full Staging Authority.

Their effective REST URLs are derived from the MCP Adapter runtime. With standard rewrites they normally appear as `/wp-json/mcp/<server-id>`; WordPress may also represent the same REST route through `index.php?rest_route=/mcp/<server-id>` when pretty REST rewrites are unavailable. MAD4B validates the registered logical route rather than assuming one URL-rewrite form.

All MAD4B abilities set `meta.public=false`, `meta.show_in_rest=false`, and `meta.mcp.public=false`. They are explicitly mounted into MAD4B custom servers and are not intended for the official default MCP server. `mad4b/runtime-self-test` treats exposure leaks, missing abilities, custom-server registration failure, required-provider certification failures, and detected MCP write side-channels as degraded runtime state.

## `mad4b-write` governed ingress

`mad4b-write` is **not** a generic execute-any server and it is not an alias for `mad4b-content` or `mad4b-admin` authority.

Its tool inventory is projected from the existing content/admin candidates only after reading the actual WordPress Ability metadata. An Ability is mounted on `mad4b-write` only when `annotations.readonly` exists and is exactly `false`. Missing Ability metadata, missing readonly annotation, or `readonly=true` is excluded fail-closed. Breakglass raw SQL is never projected into this surface.

The same Ability may legitimately exist on a specialist server and `mad4b-write`, but those are separate grant coordinates. For example:

```text
mad4b-content + mad4b/content-update-post + core
```

is not equivalent to:

```text
mad4b-write + mad4b/content-update-post + core
```

The transport permission callback binds the exact incoming `/mcp/<server-id>` route into request-local `MAD4B_SCP_Transport_Context`. Central authorization resolves that actual server **before exact-grant lookup and before exact approval-ticket consumption**. A specialist-server grant therefore cannot authorize the same Ability through `mad4b-write`.

Transport context stores only bounded request-local routing evidence; it stores no password, bearer token, Application Password, OAuth secret or MCP session secret. A route/server mismatch fails closed and does not leave stale transport authority.

## Mutation master switch

Every MAD4B mutation surface is fail-closed unless the site deliberately enables the global mutation gate:

```php
define( 'MAD4B_MCP_MUTATION_ENABLED', true );
```

Read-only discovery remains available according to its capability policy. Enabling the master switch does **not** bypass provider certification, NHI identity/grants, token scopes, resource constraints, budgets, exact approval tickets, optimistic state guards, plugin lifecycle policy, Breakglass gates, per-Flow policy, filesystem scope, or adapter-specific safety checks.

`mad4b-write` transport availability likewise does not make mutation globally effective. The transport itself is administrator-gated, while every mounted Ability still runs its own WordPress capability plus central NHI/grant/provider/budget/approval policy.

## Agent / NHI governance

Mutation authorization is centrally intersected rather than inferred from possession of an MCP endpoint:

```text
WordPress transport authentication
→ exact governed MCP transport route/server binding
→ Ability-specific WordPress capability
→ global mutation gate
→ authenticated subject binding
→ enabled NHI
→ exact effective server + ability + provider grant
→ exact token scope when required
→ resource constraints
→ MCP peer write-side-channel guard
→ transactional blast-radius budget
→ exact short-lived approval when impact requires it
→ provider execution
```

Wildcard grants/scopes are rejected. Grant creation validates that the ability is actually mounted on the requested MAD4B server and that the provider matches the server membership model.

Administrative governance abilities include:

- `mad4b/agent-list` — bounded non-secret NHI summaries;
- `mad4b/agent-effective-access` — read-only effective-access simulation without consuming budget or approval;
- `mad4b/approval-plan` — creates a pending exact-operation ticket only; it never auto-approves or executes.

High-impact operations consume exact, short-lived, single-use approval tickets bound to NHI, effective server, ability, provider, target, ticket class, and canonical payload hash. Replay, expiry, class mismatch, NHI mismatch, server mismatch and payload mismatch fail closed.

## Blast-radius budgets

NHI budgets are stored in transactional database tables rather than WordPress options/cache. Supported dimensions are `requests`, `mutations`, `affected_objects`, and `external_actions`.

Budget reservation uses row locking and happens before approval consumption. If approval is missing or rejected, the reservation rolls back. If the budget is exhausted, approval remains unused. CI proves the ordering, rollover, exhaustion, and real two-process contention behavior.

## MCP peer side-channel governance

Before central mutation authorization reserves budget or consumes approval, MAD4B inventories registered MCP servers and their actual tool metadata through the MCP Adapter runtime registry.

MAD4B-owned servers are governed directly. External/peer tools are classified from runtime semantics. Explicit read-only tools are non-blocking; unknown or reachable write paths fail closed. The default MCP Adapter `execute-ability` path is evaluated against the set of actually reachable public write abilities, so a valid MAD4B grant cannot bypass governance through another MCP server.

The official Adapter registry is not treated as the entire MCP universe. MAD4B also inventories MCP-looking REST routes and active MCP/model-context plugin basenames outside the certified Adapter/Control Plane pair. An independent unreviewed MCP transport is a fail-closed write-side-channel blocker.

WordPress REST namespace-index routes such as `/mcp` are not blindly allowlisted. They are ignored only when runtime proves that every callback is the core REST namespace-index callback for a namespace owned by registered Adapter servers; adding another callback makes that route foreign again.

A detected peer/foreign write path produces `mcp_write_side_channel_detected` and appears in runtime authority/self-test/Connection evidence.

## Connection Console

`MAD4B Control Plane → Connection` is a read-only `manage_options` surface. It reports separately:

1. local transport readiness;
2. remote HTTPS endpoint preflight readiness;
3. external connection certification, which remains false until a real target MCP session is proven.

It derives all nine runtime server endpoints, validates REST route registration and exact transport permission callback identity, and displays a dedicated `mad4b-write` summary with mounted-write count, global mutation state and the exact-transport-grant requirement.

The page does not create credentials, configure a client, enable mutation, create grants/approvals, or make outbound self-probe HTTP requests. A local WordPress process cannot self-certify Internet reachability, authentication behavior or the external subject bridge.

## Certified packaged-provider baseline

`config/certified-providers.json` is the runtime certification authority for package-backed providers. CI safe-extracts exact ZIPs without executing vendor PHP, validates expected contracts, records archive SHA-256 values, and generates critical-file manifests used by runtime mutation guards.

Current certified packages:

| Provider | Version | Control mode |
| --- | --- | --- |
| WordPress MCP Adapter | `0.6.1` | Official protocol/transport layer |
| Elementor | `4.1.4` | WordPress Abilities + explicit legacy fallback gate |
| JetEngine | `3.8.11.2` | Native JetEngine MCP + governed MAD4B adapter |
| JetSmartFilters | `3.8.3.1` | Governed MAD4B adapter; no native MCP server detected in package |
| Bit Pi / Bit Flows | `1.24.0` | FlowExecutor contract; packaged MCP role is client-only |

The exact MCP Adapter 0.6.1 release asset is pinned to:

```text
sha256: 1c3cd47c32e99b4e7d8690a44a7890256e92a8b96f61776cbe1894e5483cf676
bytes:  455463
```

Its runtime integrity manifest includes the zero-argument compatibility path `includes/Domain/Utils/AbilityArgumentNormalizer.php`, so the `{}` → `null` behavior used for no-input WordPress Abilities is verified by deployed file hash as well as package version.

Known upstream 0.6.1 constraints are recorded in the baseline. MAD4B does not register the affected `mcp_adapter_validation_enabled` callback while issue #305 remains unresolved and explicitly sets known MCP type values rather than depending on the fallback behavior tracked in issue #297.

## Provider mutation circuit breaker

Package-backed adapter mutations pass through `MAD4B_SCP_Provider_Contracts::mutation_guard()`.

Mutation is denied when the provider is unavailable, version-drifted, missing expected native abilities, unexpectedly exposes an ability certified as absent, lacks a required critical-file manifest, has a missing critical file, or has a critical-file SHA mismatch. Read/status surfaces may report degraded state without granting mutation.

Providers without a certified mutation baseline remain read/status only by default. WordPress Media is the intentional core exception because it does not depend on a third-party package contract.

## Reversible mutation and undo

`mad4b/content-update-post` is the first runtime-certified reversible mutation path. It records a durable mutation envelope **before** `wp_update_post()`, captures bounded rollback state and before-state SHA-256, performs read-after-write verification, and records the verified after-state SHA-256.

`mad4b/mutation-get` exposes bounded mutation metadata but not rollback payloads. `mad4b/mutation-undo` is high-impact: it reruns current authorization and requires an exact approval ticket. Undo refuses to overwrite newer work when the current state no longer matches the recorded after-state. Successful restore is readback-verified and creates a child recovery mutation record linked to the original mutation.

When those writer Abilities are invoked through `mad4b-write`, their exact grants and approvals must reference `mad4b-write`; the specialist content/admin authority record is not reused implicitly.

## Filesystem policy

Read discovery remains root-contained by `realpath()` and denies sensitive credential/configuration paths by default.

Normal filesystem **mutation is not a source-code deployment channel**. By default it is restricted to the `uploads` data root and an explicit non-executable extension allowlist. Executable/browser-executable/server-configuration targets are denied, including PHP/PHTML/PHAR, shell/script families, JavaScript/HTML/SVG, `.htaccess`, `.user.ini`, `php.ini`, and `web.config`.

Source-code changes belong to the governed repository → PR → CI → deployment path, not WordPress MCP filesystem mutation.

Existing data-file writes require the current SHA-256. Backups are created only in a protected temporary root outside WordPress/web roots, with restrictive permissions, and atomic replacement preserves the target mode.

## Plugin lifecycle

Plugin activation/deactivation has its own master opt-in in addition to the global mutation gate:

```php
define( 'MAD4B_MCP_PLUGIN_LIFECYCLE_ENABLED', true );
```

The default lifecycle allowlist is empty. A site must explicitly allow each plugin/operation through `mad4b_scp_plugin_lifecycle_allowlist`. Requests also require the expected current active state and a reason.

The control plane, MCP Adapter, and configured protected plugins cannot be deactivated through the normal surface. Network-wide deactivation on multisite additionally requires `manage_network_plugins`.

## Structured database policy

Structured mutation is fail-closed and is not a substitute for unrestricted SQL.

- Sensitive WordPress tables such as users, usermeta, options and sitemeta are denied by default.
- Secret/authentication-looking columns are denied.
- Data and WHERE columns must exist in the real `DESCRIBE` result.
- The target table must use a certified transactional engine such as InnoDB/XtraDB.
- `START TRANSACTION` must succeed.
- A bounded `SELECT ... FOR UPDATE` preflight must succeed before update.
- A non-empty WHERE and bounded `max_affected` are mandatory.
- Commit failure is treated as uncertified mutation failure.

## Breakglass

Breakglass remains inaccessible unless all relevant gates are satisfied. At minimum it requires the global mutation switch plus:

```php
define( 'MAD4B_MCP_BREAKGLASS_ENABLED', true );
```

The independent `mad4b_mcp_breakglass_permission` approval filter defaults to `false`; enabling constants alone is intentionally insufficient.

Raw writes require `MAD4B_MCP_BREAKGLASS_WRITE_SQL_ENABLED === true`; DDL also requires `MAD4B_MCP_BREAKGLASS_DDL_ENABLED === true`.

Multi-statements and privilege/user/role/password operations are hard-denied. `LOAD DATA`, `LOAD_FILE()`, `INTO OUTFILE`, and `INTO DUMPFILE` remain hard-denied. Raw SELECT execution must include an SQL-side LIMIT that does not exceed `max_rows`, preventing a response-only cap from loading an unbounded result into PHP memory.

`mad4b/database-raw-query` remains exclusive to `mad4b-breakglass` and is never mounted on `mad4b-write`.

## Elementor

Read/validation abilities include document, widgets, dynamic tags, and validation surfaces. Mutations require the observed document SHA-256.

The certified Elementor 4.1.4 package does not expose `elementor/manage-elements`. Direct `_elementor_data` mutation is therefore an exceptional legacy path and is disabled unless both the explicit runtime gate and site policy allow it:

```php
define( 'MAD4B_MCP_ELEMENTOR_LEGACY_WRITE_ENABLED', true );
```

`mad4b_scp_allow_elementor_legacy_write` still defaults to `false`. A future native mutation Ability must be re-certified before MAD4B consumes it.

## JetEngine

JetEngine reads and writes preserve exact canonical meta-key names; invalid keys are rejected rather than silently rewritten with `sanitize_key()`.

Unknown-field mutation is denied by default through `mad4b_scp_jetengine_field_write_allowed`. Existing writes require SHA-256. New meta requires administrator permission, `allow_create=true`, an explicit create policy, and the exact field policy.

Protected and sensitive meta are denied by default. Sensitive-looking keys such as credential/token/secret material are not returned by generic meta listing and require an explicit policy for direct access. This prevents a non-underscore secret key from bypassing protected-meta handling.

JetEngine's provider-owned native MCP routes are independently certified for their route set and permission/execution chain rather than reimplemented blindly.

## JetSmartFilters

The certified 3.8.3.1 package exposes provider/query/indexer contracts but no native MCP server was detected. Existing configuration mutation remains conservative and SHA-locked. Deeper query/provider mutation stays out of scope until an exact provider contract is proven.

## Bit Flows / Bit Pi

Read surfaces expose Flow inventory/history and a redacted definition with `flow_sha256`.

Flow execution requires the global mutation gate plus:

```php
define( 'MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED', true );
```

`bitflows/run-flow` requires the exact current Flow fingerprint. The per-Flow policy `mad4b_scp_bitflows_flow_allowed` defaults to `false`, so globally enabling Flow execution does not make every Flow runnable.

Execution uses Bit Pi's reviewed `BitApps\Pi\src\Flow\FlowExecutor::execute()` contract; the control plane does not expose arbitrary PHP or shell execution.

## Other adapters

- Media metadata writes require SHA-256; featured-image changes require expected current thumbnail ID.
- Rank Math writes use an explicit field allowlist, SHA-256, HTTP(S)-only canonical validation, allowlisted robots directives, and Unicode-aware length diagnostics.
- WooCommerce uses public `WC_Product` setters, SHA-256, constrained status/stock values, publish capability checks, and normalized prices.
- Polylang language changes require expected current language and a valid configured target language.
- LiteSpeed purge remains same-site only; external purge targets are rejected.
- Yoast and SEOPress are detected/readable where supported but governed writes remain an explicit gap.

Where these adapters expose mutation Abilities with explicit `readonly=false`, `mad4b-write` may project the same Ability; it does not create a second implementation of the provider mutation.

## Append-only audit

Schema v4 stores audit evidence in transactional `mad4b_scp_audit_events` and `mad4b_scp_audit_heads` tables. Events are append-only, sequence-numbered, request-correlated, bounded/redacted, and chained by `previous_hash` / `entry_hash`.

The singleton chain head is initialized before operational transactions and serialized with `SELECT ... FOR UPDATE`. Audit events can join the Budget-owned transaction; post-commit hooks are dispatched only after an explicit commit, while rollback discards pending sink dispatch. This keeps approval/budget/audit evidence atomic in the governed authorization path.

The prior bounded WordPress option is retained read-only and cryptographically anchored during migration. Later drift of that legacy history fails integrity checks. Runtime CI proves concurrent two-process append without lost updates and proves that out-of-band event tampering makes `verify_chain()` fail.

`mad4b_scp_audit_committed` is the external sink integration point. A separate SIEM/WORM implementation is still optional/deferred; the local append-only database chain is the current durable source of truth.

## Read-only Admin Governance Console

Administrators receive a `MAD4B Control Plane` screen backed by the same governance/runtime services. The first UX slice is deliberately **visibility only** and exposes no POST handler or grant/approve/revoke/undo action.

Tabs cover:

- runtime authority, blockers and MCP peer governance;
- Agents/NHI and effective-access preview;
- approval-ticket evidence;
- mutation/undo evidence without rollback payloads;
- append-only audit integrity and bounded event tail.

A separate read-only **Connection** submenu exposes transport/endpoint/write-ingress readiness without becoming a connection wizard or mutation surface.

The screens require `manage_options`, use bounded queries, and do not render structured audit summaries that may contain identity/context evidence. Mutation controls will be added only as separate nonce-protected, explicitly reviewed actions.

## Connector resilience

Remote clients should start routine diagnostics with `mad4b/connector-preflight`
instead of fanning out across many deep status abilities in parallel.

The shared `MAD4B_SCP_Connector_Resilience` policy provides:

- a compact sequential preflight with an overall request budget;
- one automatic retry only for classifier-approved read-only transient failures;
- explicit backoff for rate limits rather than immediate replay;
- structured error categories and fingerprints without raw exception messages;
- partial diagnostic results when one independent check fails;
- no automatic replay of write or enrollment operations;
- mandatory postcondition reconciliation after uncertain mutation outcomes.

Deep Staging certification and operation discovery are opt-in from the compact
preflight. Operation discovery is bounded and relevance-ranked before limiting
results.

This policy is provider-neutral. New connectors and providers should reuse the
shared resilience service rather than define private retry rules. Persistent
authority/catalog caches and persistent circuit breakers are intentionally not
introduced because authority and provider eligibility must remain live.

A transport disconnect outside WordPress itself cannot be caught by PHP. After
such a disconnect, read callers reconnect through the compact preflight; write
callers reconcile observed postconditions before creating a new plan.

## CI certification

Repository CI currently covers:

- PHP 7.4 / 8.1 / 8.3 syntax;
- adversarial pre-install hardening contracts;
- exact packaged-provider version/archive certification;
- runtime critical-file integrity manifests;
- native MCP security invariants for JetEngine, Elementor and Bit Pi;
- default-server isolation and nine MAD4B custom servers;
- exact route/server transport binding before exact grant and approval consumption;
- `mad4b-write` projection from explicit `readonly=false` Ability metadata;
- specialist-server grant versus `mad4b-write` grant isolation;
- exact NHI grants, governance visibility and approval planning;
- transactional NHI budget ordering, exhaustion, rollover and real concurrent contention;
- MCP peer and independent foreign-MCP write-side-channel detection before budget/approval consumption;
- WordPress REST namespace-index anti-hijack semantics;
- reversible post mutation, readback verification, exact approved undo, and drift-safe undo denial;
- global mutation master-switch enforcement;
- filesystem source-execution denial;
- plugin lifecycle default-deny/multisite capability contracts;
- transactional structured-DB preflight;
- bounded Breakglass raw reads;
- Bit Flows per-Flow default deny;
- Elementor legacy-write explicit opt-in;
- JetEngine exact-field and sensitive-meta policy;
- append-only audit migration, structured redaction, concurrent append and tamper detection;
- read-only Admin Governance/Connection Console contract/runtime behavior;
- disposable WordPress/MySQL runtime activation and smoke testing on WordPress 6.9 and the current `latest` release.

The isolated runtime CI activates MCP Adapter 0.6.1 and MAD4B Site Control Plane 0.4.0-rc.69 in disposable WordPress/MySQL. Repository success does **not** replace target-site certification.

The core mutation-gate workflow is read-only. The MCP Adapter refresh workflow is manual-only (`workflow_dispatch`) and may write certification evidence only when an operator explicitly runs it on a selected branch.

## Live target acceptance still required

Keep the PR Draft until the exact target site proves at least:

1. the deployed provider versions and critical files match the certified baseline;
2. MCP Adapter and the control plane activate without fatal/runtime warnings;
3. all nine MAD4B servers are registered and the intended endpoint is remotely reachable over HTTPS;
4. the dedicated control identity authenticates correctly through real MCP transport/session handling;
5. `mad4b/runtime-self-test` returns `passed`, with custom-server isolation and no required-provider/peer blockers;
6. the official default MCP server cannot discover MAD4B abilities;
7. `mad4b-write` discovers only the certified write projection when that ingress is used;
8. the governed write NHI uses exact minimal non-wildcard grants, including exact `mad4b-write` coordinates rather than specialist grants for write-ingress calls;
9. approved reversible content mutation succeeds with readback verification and undo succeeds;
10. deliberate post-mutation human drift makes undo fail closed without overwriting newer work;
11. budget exhaustion denies before approval consumption;
12. filesystem code/server-config mutation is rejected;
13. plugin lifecycle remains unavailable unless explicitly enabled and allowlisted;
14. one approved non-sensitive structured DB repair succeeds while sensitive tables remain denied;
15. Breakglass is inaccessible under default configuration;
16. peer/foreign MCP inventory has no write-side-channel blocker;
17. success and rejection paths both leave a valid append-only audit chain;
18. all mutation gates are returned to OFF after certification unless a controlled governed acceptance cycle is intentionally continuing.

## Remaining genuine gaps

- exact live MCP authentication/session behavior on the target site;
- real target `mad4b-write` tool discovery and exact transport-subject/grant evidence until target live acceptance;
- target provider versions/files and real provider side effects until target-site certification;
- reversible mutation contracts beyond the certified post-update pilot where provider-safe restore is required;
- exhaustive commercial JetEngine field schema/type governance for fields that require it;
- deeper JetSmartFilters mutation contracts;
- governed Yoast/SEOPress writes;
- nonce-protected Admin mutation controls for grants/approval actions/undo; the current consoles are intentionally read-only;
- optional external SIEM/WORM audit sink implementation.

## Host boundary

The WordPress control plane does not provide arbitrary PHP/shell execution or privilege escalation. SSH, Hostinger APIs, system services, host-level cron/logs, files outside PHP permissions, and unrelated database credentials remain a separate MAD4B Host Connector concern.


### Capability-impact health semantics

Runtime provider health distinguishes artifact drift from unsafe capability drift. An active provider may remain platform-healthy when its mounted read capabilities are structurally compatible and every mutation capability remains fail-closed. Active providers with exposed read incompatibilities, adapter-runtime failure, or mutation eligibility under unresolved artifact drift remain blockers.

Functional-gap evaluation also distinguishes evaluator completion from provider closure. The legacy `ready` field is a backward-compatible alias for `evaluation_complete`; it is not provider certification. Operators should use `decision_handoff`, `followup_required`, and per-family decision states for the next governed action.
