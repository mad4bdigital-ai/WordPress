# CSO01 — Integration compatibility and adversarial operating model

**Status:** Candidate design + bounded read-only PHP implementation in PR #368. All 81 tasks / 11 gates remain unaccepted. No grant, plugin save, secret ingress or production operation.

## Evidence from All Royal Egypt Staging (10 Oct 2026)

Runtime `https://staging.allroyalegypt.com`: WordPress 7.1.3; PHP 8.3.35; MAD4B Control Plane `0.4.0-rc.96`, Adapter `0.7.0`. The installed runtime does **not** contain PR #368. Governing Site Profile is Staging, while the default `wp_get_environment_type()` reports Production (`wordpress_environment_explicit=false`). Deployment binding not configured or bound; same-origin clone protection unavailable. Treat authority as site-profile-governed but mark Host/Clone/Production-promotion validation **unproven**.

Live metadata search saw **644** registered Abilities. Session-safe diagnostic recorded **36** ChatGPT tool registrations and **75** governed write candidates. A provider Ability listed in 644 can still be absent from tools/list; neither number is a certification. The session-safe report is `HEALTHY` for subject/session identity only (`valid_for_release_merge=false`); deferred deep provenance, browser, provider and staging acceptance remain distinct. Diagnostic overhead: HTTP request 4,218 ms and 425 DB queries across 6,154 included files; those observations are **not** a frontend performance benchmark.

Broad projection-status/read-catalog/provider-matrix calls intermittently failed internally; scoped search and 6-ability prepare succeeded. This is a real-world reason for *bounded semantic search → exact prepare → verify → display*, not indiscriminate enumeration.

## Real provider contract samples

| Family | Observed exact read Ability | Input contract discovered | Main integration complexity |
|---|---|---|---|
| Elementor | `elementor/get-dynamic-tags` | `post_id`: positive integer | Page builder DOM IDs, dynamic tags, template inheritance, revisions, Theme Builder conditions, unsafe clone/replace |
| JetEngine | `jetengine/get-cpt-definition` | `post_type`: 1–20 characters | CPT definitions, serialized meta, repeater/group fields, relationship tables, field conditions and schema drift |
| Fluent Forms | `fluentforms/list-forms` | optional `limit`: integer 1–100 | Form definitions vs private submissions, nested conditional logic, PII, webhooks, anti-spam, attachment fields |
| Rank Math | `rank-math/get-post-schema` | `post_id` integer, optional boolean with `default: true`; root `default: []` | Free/Pro capabilities, JSON-LD graph arrays, canonical/hreflang, defaults, proprietary options |
| WooCommerce | `woocommerce/get-product` | `product_id`: positive integer | Variations, inventory, tax regions, currency, stock concurrency, refunds and pricing policy |
| WPML | `wpml/status` | empty input `[]` | Languages, translation jobs, TRID groups, mapping URL/site locales, synchronized fields |
| WordPress core | `core/get-site-info` | optional filter depending on provider | Multisite/network admin, nonces, roles, REST exposure and conflicting capabilities |

Scoped exact read execution was also completed for the four provider status Abilities, without mutation:

- **WPML:** available, primary plugin `sitepress-multilingual-cms` version **4.9.7**; its repository-family adapter declared read-only/non-authorizing, with six active runtime WPML-family plugins observed. It did not expose mutation.
- **JetEngine:** available **3.8.15.4**, but baseline certified **3.8.11.2**; `provider_certification.status=version_drift`. Do not advertise an edit/write-ready adapter or even an exact-runtime-certified read form until recertification.
- **Fluent Forms:** available, family read-only adapter; `entries_exposed=false`, `submission_values_exposed=false`, `mutation_exposed=false`. Form definitions and submissions are distinct permission/privacy domains.
- **WooCommerce:** `available=false` and `uncertified_provider`, despite registered `woocommerce/get-product` and `woocommerce/update-product` names. No live read/write readiness may be inferred from those registrations.

**Consequent contract:** CSO01 `integration-inspect` must check `MAD4B_SCP_Provider_Compatibility_Certification` for exact runtime availability/version/probes *before* describing a family as ready. Absent certification means a **preview-only, unverified** schema; unavailability, explicit version drift or failed structural probes means **blocked** with a specific recovery action. Exact provider readiness never grants WordPress execution or permission.

Every row above is **observed read metadata**, not a demonstrated live read execution or a writable integration.

## Required integration lifecycle

1. Resolve **exact** origin, Site Profile revision, blog ID, enrolled actor, environment and runtime generation. A host alias or implicit WordPress environment must not switch the target.
2. Search the **existing Unified Capability Gateway** with bounded task text. Keep read/write discoveries separated; labels/categories are not trusted executable permission.
3. Select one canonical Ability and prepare its **schema, execution lane, descriptor identity, authority scope and receipt** through existing gateway. Do not infer trusted adapters from plugin slugs.
4. Compile supported input shape into a typed form, never echoing defaults, sensitive descriptions, credentials, output schemas or sample payloads. For unsupported fields return actionable `UNSUPPORTED_SCHEMA`; don't hide dependencies and claim validation passed.
5. Validate at site/actor/form-hash scope. Recompute the descriptor after plugin update, locale change, role drift or switching blogs. No validation response should echo submitted values.
6. Separate **view form**, **plan write**, **independent approval**, **execute** and **readback**. The PR implements only the first bounded steps. No rule may convert a read form to a mutation.
7. Account for eventual consistency, partial success, queue lease expiration, webhook retries and source/plugin changes. Keep idempotency per operation/record and avoid claiming a global atomic rollback across WordPress plugins.

## Hard objections and fail-closed outcomes

| Scenario | Safe user-facing result | Required proof before enabling edits |
|---|---|---|
| Unknown plugin, obfuscated field or unmatched CPT provider | Unsupported/adapter required, show metadata-only capability names | Signed explicit adapter binding and storage/side-effect contract |
| Two providers claim same Ability ID or setting owner | Deny ambiguous owner; no silent last-writer-wins | Exact vendor/plugin fingerprint and unique owner selection |
| Nested Elementor JSON/repeater/conditional meta | Read-only unsupported with explanation | Typed hierarchical schema, branch and renderer parity tests |
| CPT/relationship loops or circular references | Detect depth/cycle and produce bounded relationship preview | Versioned graph traversal with per-edge permissions |
| Large catalogs (thousands of Abilities) | Search-first, capped result window, non-exhaustive status | Latency/heap/query load tests at representative scale |
| WordPress/PHP/plugin version mismatch or missing paid extension | Degrade as unavailable/unsupported; no inferred PRO license | Version compatibility matrix and inspected live feature flags |
| WordPress multisite and network-activated plugins | Blog- and network-specific inventory; deny cross-blog cached descriptor | Per-blog site and network role enrollment; switch/restore tests |
| WPML translation or locale fallback | Explicit source/target locale and translation group; no content overwrite | Independent per-locale readback and canonical/hreflang parity |
| WooCommerce product edits or reservation/inventory races | No write in read-only foundation | Atomic business policy/stock lock, pricing validation, idempotent compensation |
| Fluent Forms submissions or lead PII | Do not return data records; definitions only under permission | PII classification, minimization, retention and lawful purpose policy |
| API credentials, tokens, password fields | Reject from normal conversation forms | First-party isolated secret ingress; verify/mask/rotate/never echo |
| Schema default includes sensitive value | Has-default flag only; no serialized actual value | Secret classifier + field-level disclosure policy |
| Dynamic option stored in PHP serialized data/custom table | No generic update/delete, no SQL inference | Reviewed adapter with exact storage API and canary/rollback evidence |
| Concurrent manual changes while user edits | Stale descriptor/expected-revision denial | Plan-level compare-and-swap plus independent post-write readback |
| External services timeout, rate-limit or queue retry | Bounded retryable/nonretryable error; no false success | Dead-letter, lease/fencing, rate-budget and idempotency tests |
| Same-domain clone/staging vs actual WP environment differs | Identity review warning, no auto-promotion | Host deployment binding, clone protection and exact origin attestation |
| Plugin deactivated during request or handler throws | Return provider-unavailable, preserve other results | Fault isolation + repeated current-generation preparation |
| Provisional read schema exposes write/side-effect path | Deny; never use discovery metadata as execution proof | Exact classified read lane, server-side authority evaluation |
| User session changed, expired, or revoked | Stop/refresh connection; no cached authority re-use | End-to-end permission freshness and oauth subject test |
| Browser unavailable/preview differs from AJAX/live state | Describe unknown state and request independent acceptance | Real browser/DOM parity and signed screenshot/dataset receipts |
| Performance overload during discovery | Bounded search, budget/stale partial response | Staging baseline for real requests, query count/heap/latency budgets |

## Design principles for extension adapters

Adapters are **data-driven, versioned and explicitly governed**, not hardcoded if/else by site. Descriptor must specify `provider_id`, `plugin_slug`, supported version range, `ability_name`, `read_surface`, `write_surface` (optional and separately approved), `field_schema_sha256`, `site/blog scope`, locale/relationship semantics, side effects, privacy policy, rollback class, adapter signer, evidence freshness, test fixture references and an honest unsupported result. A plugin update invalidates certification until independently rechecked.

Adding arbitrary plugin names to a form catalog is **not** adding editable fields. A safe adapter must first pass capability discovery, semantic field mapping, consent/rights checks where relevant, canary, approval, readback and rollback review.

## Acceptance required before calling this universal

Native PHP 7.4/8.3 adversarial tests on exact SHA; WordPress Abilities API and MCP gateway read/prepare on All Royal Staging with the candidate installed; independent disposable multisite/second vendor site test; 644+ Ability search stress; visual client forms in Arabic/English with keyboard and screen-reader checks; schema drift and user-switch probes; no secret values in request/response/logs; provider-specific edit plans and write readbacks in subsequent separately approved packages. None are satisfied solely by this document or a healthy MCP session.

## Deep second-pass correctness review (10 Oct 2026)

**Why initial metadata preparation is not a permission grant.** The same All Royal provider has at least two legitimate evidence layers: `jetengine/status` reported the runtime plugin **3.8.15.4** against baseline **3.8.11.2** (`version_drift`), whereas exact `mad4b/provider-capability-certification` for `jetengine/get-cpt-definition` reported **compatible_unattested**, `READ_COMPATIBLE`, `structural_compatibility`. Elementor's `elementor/get-dynamic-tags` had the same structural-only tier. The WooCommerce certificate returned **unavailable** / **UNKNOWN** and `read_eligible=false`. This is *not* a contradiction to gloss over: adapter family drift and individual structural capability compatibility are different dimensions. The form gateway must say **preview-only** for structural-only read capability, **no form** for unavailable provider or explicitly disallowed scope, and **runtime certified** only for an exact certified read contract. There is still no write approval in any of these states.

**Important repaired integration defect.** `MAD4B_SCP_Provider_Compatibility_Certification::assess_provider($family)` with its default null Adapter marks otherwise installed providers `available=false`. The correct canonical API is `capability_certification(array('provider_id'=>..., 'ability'=>...))`; it resolves the catalog-specific `adapter_id` inside the provider registry before assessment. CSO01 now uses this public selector, verifies the exact matched risk/read capability and certification source, then separately fences the certification generation. Mock-only adapter availability assertions previously could not prove this behavior.

**Important repaired gateway bypass.** Direct calls to `Unified_Capability_Gateway::search()` or `::prepare()` skip the gateway's top-level `dispatch()` guards (request-generation admission, policy, tracing and response-byte budget). The CSO01 integration facade now calls `dispatch(array('action'=>'search'|'prepare', ...), 'internal')` for both paths. A gateway denial stops discovery/inspection without falling back to unsafe direct execution.

**Important repaired partial registration vulnerability.** WordPress may leave earlier Abilities registered even if a later `wp_register_ability()` returns null, and unrelated catalogs may still discover those names. CSO01 preflights the complete reserved namespace before first registration and makes `can_read()` return false until **all** seven owned registrations succeed; any surviving partial callback is not executable. Neither an Ability collision nor a failed registration can grant an isolated callback by accident.

**Exact replay fencing.** Both core and provider descriptors now bind to the *complete* enrolled site scope (UUID, origin, profile revision, environment, blog ID, actor-bound HMAC and observed WP environment), not just a subset. Provider descriptors additionally bind canonical input Schema SHA, descriptor-generation SHA, classification SHA, authority-scope SHA, and current provider certification generation. The provider certification state is rechecked after preparation. A change in origin, blog ID, actor, certification generation or read classification requires the user to refresh the form; merely reusing the same field labels is insufficient.

### Still-open objections and specific future acceptance

| Priority | Unresolved technical objection | Concrete acceptance proof |
|---|---|---|
| P0 | Real native PHP test execution and deployment gate | Run PHP 7.4 and 8.3 fixtures on exact current commit; demonstrate full 7-Ability registration + collision/null fail-close |
| P0 | Existing All Royal runtime still rc.96 | Separate signed Staging artifact and authorized staging install; verify live MCP list/find/prepare/validate without writing or revealing secrets |
| P0 | Browser/ChatGPT client renderer not wired to CSO01 | Show interactive RTL and LTR forms, keyboard focus, errors, screen-reader labels and never-enabled Save control |
| P0 | Production promotion and same-origin clone protections not proven | Exact Host deployment binding, trusted environment attestation and proof clone aliases cannot switch authority |
| P1 | Nested JetEngine repeaters, relationship graphs, Elementor templates | Signed typed adapter with depth/cycle limits, semantic readback, version matrix, preview/rollback policy |
| P1 | Fluent Forms submissions/PII or WooCommerce stock/pricing | Separate purpose-limited permission scopes and provider-specific read/write tests; no generic data leak or stock race |
| P1 | Provider upgrade mid-form, metadata drift and plugin deactivation | Reprepare exact descriptor; reject if certification, blog or provider generation changed; prove no stale execution |
| P1 | Local sites, Multisite network plugins and per-blog roles | Independently enroll each blog, allow HTTP loopback only in local/dev, deny cross-blog replay and unsafe staging HTTP |
| P1 | Large site latency under 644+ registered Abilities | Request-generation budgets, gateway enforced response caps, bounded result sets and baseline performance replay |
| P2 | Localization beyond Arabic and WordPress metadatas' unreliable descriptions | Translation key tables, RTL language regression, Unicode edge-case and help-text privacy audit |

All new source checks are candidate **code** changes; this is not native PHP acceptance or live deployment evidence.
