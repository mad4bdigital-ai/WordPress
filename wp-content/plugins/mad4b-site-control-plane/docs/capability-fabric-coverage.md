# Capability Fabric: PR #230 implementation and migration boundary

Initial review baseline: `9f376f1d5baee609a5434b68777015dc306c3d64`.
Reconciled implementation base: `56d3ffcb62c09c82c480adec2e7d93aa1ebd80b5`.

This document covers all 21 architectural recommendations supplied with the
review. **Coverage is not a claim that all 21 subsystems are implemented.** The
patch closes concrete preparation and catalog-admission gaps while retaining
existing authorization and execution boundaries. Several recommendations in the
review explicitly call for later migrations, including the storage backend,
protocol upgrade and native execution hooks. Those migrations are specified
below and remain unimplemented.

## Delivered behavior

`MAD4B_SCP_Ability_Contract_Inspector` is now the canonical structural classifier for a selected Ability. `MAD4B_SCP_Capability_Descriptor_Registry` builds contract/site generation roots from that classifier without depending on ChatGPT projection. Gateway preparation, projection, catalog execution descriptors and dispatcher receipt verification consume the same structural identity.
Metadata search remains schema-lazy. Projection's existing callback provenance
and materialization checks remain authoritative; descriptor digests do not
replace them or claim to hash a callback's complete semantics.

The generation has two explicit roots: `contract_root` pins the selected
ability's input schema, classification and original execution lane; `site_root`
pins blog and enrolled site binding. Its aggregate is `descriptor_sha256`.
There are no synthetic provider-certification, policy or grant roots: those
checks remain live and cannot be inferred from preparation evidence. Changes to
another ability do not invalidate this descriptor. Changes to the selected
ability's output schema or metadata are already included in classification.

Gateway `prepare` emits an HMAC-signed `mad4b.preparation-receipt.v1` with a
five-minute lifetime. Evidence binds the descriptor and existing authenticated
subject scope (user capabilities, OAuth subject/client/issuer fingerprints and
scopes, site binding and blog). It contains no raw input, output, bearer token or
secret. The signature uses a domain-separated key derived from the WordPress
`auth` salt; salt rotation invalidates previous evidence. Verification rejects
invalid shape, excessive size, tampering, wrong target, future issuance, expiry,
changed scope, changed contract and lost runtime eligibility.

Read/write/developer dispatchers require and verify a signed receipt plus the exact prepared authority-scope digest before execution.
The receipt never creates a grant, ticket or idempotency key. Existing permission
callbacks, current mounting, execution boundaries, NHI, grants, approval claims
and commit guards still execute. The current dispatch contract requires exact schema, lane, classification, authority-scope and receipt fields; missing, partial, null, expired or cross-subject evidence fails closed. The JS client performs fresh single-target preparation immediately before dispatch and forwards that fresh evidence.
Explicit enrollment and exceptional lanes retain their separate contracts.

`MAD4B_SCP_Distributed_Lock` centralizes catalog admission. Names include the
database, options table and existing site/subject scope, avoiding server-wide
collisions between databases with identical table names. GET_LOCK is
nonblocking; recursive request admission fails closed, release runs in finally,
and the builder checks actual connection ownership before starting publication.
This cooperative check detects connection loss during generation. It is **not**
a transactional fencing token for reconnects during an individual storage SQL
statement; the existing immutable object/CAS publication mechanism remains
necessary. A backend providing atomic publication fencing is a separate storage
migration requirement.

## All review recommendations

| # | Recommendation | Current coverage / remaining implementation |
|---|---|---|
| 1 | Canonical descriptor | Delivered a projection-independent canonical Ability inspector plus selected-capability descriptor facade with canonical classification hashing. Gateway preparation, catalog execution metadata and fixed dispatch now consume the descriptor directly; Projection is a consumer rather than the classifier owner. Operation Registry, Traits, Servers and Authorization still need gradual migration where they own adjacent facts. |
| 2 | Generation hierarchy | Delivered independent contract/site roots. Provider certification, policy and impact roots require their owning registries' stable contracts. Never label an incomplete root as complete. |
| 3 | Prepared receipt | Delivered signed, expiring, scope-bound preparation evidence. Receipt and prepared authority scope are mandatory for normal fixed read/write/developer dispatch, while live authorization still re-runs. It does not replace operation-specific approvals. |
| 4 | MCP compatibility profiles | Existing pinned Adapter/protocol remains certified. A 2026 profile needs separate shadow wire certification; no new advertised support is added. |
| 5 | Native WordPress lifecycle | Existing provenance wrappers remain active across 6.9/latest. Native-hook replacement requires parity proofs, including pre-execute short-circuit and permission/output filters. |
| 6 | Unified execution state machine | Durable primitives are already substantive: Operation Journal persists lifecycle/terminal evidence, Connector Resilience distinguishes `not_started` from post-boundary `unknown`, and Durable Execution persists idempotency claims, claim epochs, leases/fencing and reconciliation. The remaining gap is one normalized cross-component transition/read model; this patch does not create a second state store. |
| 7 | Cross-request idempotency | Durable Execution already persists exact scope/key/request-hash claims with claim epochs, `pending`/`completed` outcomes, verified reconciliation completion, bounded reconciliation observations and `released_verified_no_effect` reclaim. Connector Resilience marks uncertain post-boundary mutation failures `unknown` and forbids blind retry. The remaining work is provider-specific postcondition evidence for every external mutation family; preparation evidence itself remains reusable and is not an idempotency key. |
| 8 | Provider circuit breaker | Request-local resilience remains. Durable provider health, isolated HALF_OPEN probes and permission-error exclusion require an atomic provider-state backend. |
| 9 | Shadow/canary/active | Provider Compatibility Certification already computes capability-level certification, `QUARANTINED`, shadow/canary/active activation stages, artifact/runtime binding and canary eligibility. Behavioral Recertification performs bounded reversible probe/readback/rollback evidence, while Provider Canary Execution isolates high-risk canary writes and never auto-promotes them. The remaining gap is operational-health circuit state, not compatibility lifecycle. |
| 10 | Adaptive hot set | Fixed dispatch remains primary; projection remains explicit and site-scoped. Telemetry-based ranking is not implemented. Recommendations must never apply a projection implicitly. |
| 11 | Intent routing | Existing Operation Registry and Capability Trait Resolver remain available. Semantic user-intent routing is not equivalent to keyword search or the content ownership Intent Registry. Provider choice still needs explicit certified resolution. |
| 12 | Resource constraint DSL | Existing unhandled resource constraints fail closed. A compiler needs provider-specific object/path/table extraction; no permissive generic evaluator is introduced. |
| 13 | Impact digest | Existing Dependency Impact Graph, Impact Policy and commit guard remain. Input-specific preparation/approval impact pinning needs a separate bounded planner contract. A schema-only receipt cannot prove blast radius. |
| 14 | Catalog storage backend | Existing options/CAS/reader-grace/GC behavior and the newly added explicit offline decommission tool retained. Dedicated table migration remains later work with dual-read parity and atomic publication fencing. |
| 15 | DB lock / MariaDB | Delivered database-namespaced abstraction, ownership check, disconnect fault test and MariaDB 11.8 CI lane. MySQL 8.0 remains in the real independent-connection matrix. |
| 16 | Distributed tracing | Existing metrics and Operation Journal trace remain. W3C context propagation and per-stage spans are not implemented; payload logging stays excluded. |
| 17 | Unified execution receipt | Existing audit/claim/journal/commit evidence remains. The new preparation receipt is not an execution receipt and makes no commit/outcome claim. |
| 18 | Authorization decision graph | Existing deterministic authorization and policy resolution remain. A complete redacted evaluated/not-evaluated graph needs integration into each existing gate. |
| 19 | Signed OAuth metadata | Optional future layer; requires independent key lifecycle and JWT verification. WordPress preparation HMAC is not a public OAuth metadata signature. |
| 20 | Fault/property CI | Expanded behavioral mutation suite from 10 to 29 regressions. Covers lost build ownership, database isolation, preparation-receipt signature/expiry/nonce/subject/descriptor, signed Context Receipt integrity, dispatcher enforcement, preparation-before-governance-envelope ordering and the Context Receipt dispatcher and issuer transport byte budgets; mandatory partial/null pin tests are retained. External provider commit+timeout and durable journal failure injection still need dedicated fixtures. |
| 21 | SLO contracts | Existing hard catalog count/bytes/time budgets, 5,000 lazy-ability regression and bounded transport remain. Production P95 targets require measured tracing data; proposed numbers are not asserted as achieved. |

## Migration acceptance gates

1. **Descriptor consolidation:** each current classifier/operation/provider path
   must return the same lane, schema, provider and provenance decisions. Mutable
   authority must be refreshed rather than served from a descriptor cache.
2. **Durable execution:** normalize the already-persisted Operation Journal, execution-fence, Connector Resilience and Durable Execution states into one read contract rather than creating another state store. Preserve the existing rule that provider success followed by timeout is `UNKNOWN`; no automatic write retry may occur before independent provider/postcondition proof. Audit persistence failure after mutation also remains reconciliation-required.
3. **Provider control:** circuit state must isolate site/provider/generation;
   only transport/timeout/upstream failure classes affect it. Half-open admission
   needs a single atomic probe claim. Ring/quarantine cannot broaden any grant.
4. **Constraint/impact planning:** compile a strict allowlist, deny unsupported
   predicates and bind the exact input/resource set plus dependency generation
   to approval and revalidate immediately before commit.
5. **Storage migration:** read old/new, compare exact payload/directory parity,
   write new only after a governed migration, retain rollback/read compatibility,
   verify GC with active readers and enforce atomic connection-loss fencing.
6. **Protocol/hooks:** certify legacy and new profiles separately before enabling
   them. Client claims and version strings cannot prove support. Native hook
   telemetry must not short-circuit permission or provenance enforcement.
7. **Tracing/receipts:** report verified stage outcomes and NOT_EVALUATED gates;
   avoid input/output and sensitive identity metadata. Persist outcome evidence
   with existing operation/audit identity before asserting a commit receipt.
8. **Operations:** recommendations remain non-authorizing. The existing offline
   decommission tool retains exact reviewed-plan, site/inventory checks and a
   full reader-drain maintenance window. Fresh per-site network clients retain
   server authority-scope checks and partial-result reconciliation evidence.
   Deactivate/uninstall must not silently destroy governance or catalog state.

## Verification

- Standalone canonical-inspector regression proves associative-order stability, projection-independent descriptors and semantic drift detection. Standalone receipt regression exercises real classifier and dispatcher code,
  forgery, expiry/future time, malformed evidence, salt rotation, user/capability,
  blog/lane/boundary changes, mandatory authority-scope/receipt pins, partial pins and original permission denial.
- JS client regression proves fresh evidence forwarding across all five normal
  lanes while retaining contract drift and exceptional-lane denials.
- Catalog regression injects connection loss before publication and verifies
  unchanged directory state, in addition to reader/CAS/GC/capacity/build tests.
- Mutation suite first requires pristine copied harnesses to pass, then requires
  every representative defect to fail; missing fixtures cannot count as a kill.
- Real WordPress CI verifies receipt issuance and dispatcher denial of forgery, fresh-process inactive decommission planning,
  real independent DB connection contention, rejection release and cron retention
  on MySQL 8.0 and MariaDB 11.8. Local standalone success does not establish those
  remote matrix results.
- Intent schema contract now requires migration version >=11 and retains all
  intent-table uniqueness and ownership assertions; v12 no longer fails solely
  because the schema number changed. This contract also runs in compatibility CI.

## Upstream references for later migrations

- [MCP 2026 specification release](https://blog.modelcontextprotocol.io/posts/2026-07-28/)
- [MCP discover wire contract](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/docs/specification/2026-07-28/server/discover.mdx)
- [WordPress 7.1 execution lifecycle filters](https://make.wordpress.org/core/2026/07/29/new-execution-lifecycle-filters-for-the-abilities-api-in-wordpress-7-1/)

These references justify migration investigation; they do not certify the pinned
Adapter or authorize removal of the current wrappers.
