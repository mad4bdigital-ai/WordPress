# MAD4B — Universal WordPress Admin Operation Coverage (Feature 007)

## Purpose and truth labels

**Keep both engines.** Plugin/update/CI-outage recovery remains separate from
admin operations. The `mad4b/manual-workflow-discover` aggregator can request
`include_plugin_updates`, `include_admin_operation_profiles`, and
`include_admin_surface_coverage` independently. The first is about versioned
software packages; the other two are about WordPress UI actions.

An arbitrary WordPress screen, form, AJAX handler or REST route is **never**
a remotely executable control merely because it can be enumerated. The
`Admin Surface Coverage` layer provides a complete *framework for growing*
coverage across discovered sources; it does not assert that every action is
already implemented or safe to execute.

**Five-level coverage model**:
- **L0:** metadata observed (not necessarily complete in a REST request).
- **L1:** typed contract or schema is reviewable.
- **L2:** original governed planner/executor is registered and resolvable.
- **L3:** a user-authorized Staging mutation executes through that original
  plan/executor and returns a durable attempt receipt.
- **L4:** source-bound postconditions, audit, compatibility checks and
  compensation/recovery are verified in real runtime.

The new inventory deliberately never claims more than L2. Executed/verified
states must come from separately evidenced runtime receipts, never from
a discovered UI menu.

## Sources and current coverage

The table distinguishes **source metadata discovery** from a hypothetical
executor strategy. "Adapter required" is a real integration task, not a
stated live operating capability.

| # | Manual WordPress operation family | Discovery evidence | Safe adapter design before L3/L4 |
|---|---|---|---|
| 1 | Core WordPress settings (site title, timezone, writing/reading, discussions) | `$wp_registered_settings`, REST settings schemas | Exact setting allowlist, typed schema, option revision, sanitization/effects review, readback |
| 2 | Third-party options or nested plugin settings | Registered Settings API, plugin provider contract, admin route | Source-owned field-level mapping and sanitize callback review; no arbitrary `update_option` |
| 3 | API keys, tokens, OAuth credentials | Redacted identifier class only | Dedicated governed secret vault/reference, verification without plaintext disclosure |
| 4 | Plugin lifecycle/install/update/rollback | Certified provider manifest and plugin inventory | **Existing** plugin package plan/apply, exact digest and backups; CI outage uses independent native evidence |
| 5 | Active plugin license, purchases, vendor activation | Provider/version metadata only | Vendor-specific authenticated connector, license policy, cost and external side-effect approval |
| 6 | Theme install/update, templates and theme.json | Admin screen and any approved WordPress API | Separate certified theme artifact and runtime rollback, child-theme compatibility |
| 7 | Pages/posts/CPT create/edit/publish | `get_post_types(show_ui)`, REST schema | Content lifecycle WP Ability, capability checks, revisions, status transitions, rendered readback |
| 8 | Post meta / ACF / JetEngine meta fields | CPT/registered meta/REST schema when available | Typed schema per meta key, owner contract, referential integrity, versioned changes |
| 9 | Terms/categories/tags/custom taxonomies | `get_taxonomies(show_ui)` and REST flags | Exact taxonomy term operations, term count and relationships readback |
| 10 | Menus and navigation, site editor | Registered admin screens and native APIs | WordPress version-aware nav entities, preview, menu placement, permalink/route readback |
| 11 | Gutenberg blocks, reusable patterns, dynamic blocks | Block Type Registry; attribute schema is separate work | Typed block attributes, editor serialization safety, template identity, rendering parity |
| 12 | Elementor templates, widgets, dynamic tags | Admin screens/CPT/block provider metadata where exposed | Elementor provider adapter, template structure migrations and render/preview acceptance |
| 13 | JetSmartFilters, filter indexes and query builders | Plugin API, CPT, AJAX, REST registrations | Typed provider recipe, AJAX/static parity, result identity and invalidation checks |
| 14 | Media library, uploads, replace/delete images | WP media REST/native operations | MIME, size, library ACL, deduplication, attachment metadata, post-reference checks, reversible trash |
| 15 | Import/export, bulk jobs, CSV/XML/ZIP | Admin-post/AJAX routes or plugin-owned operation | Staged file validation, source ownership, idempotent batch cursor, per-item receipt, compensation |
| 16 | WPML multilingual content, translation sync and hreflang | WPML provider surfaces and content type metadata | Source language identity, translation group linkage, locales, slug/hreflang and rollback |
| 17 | Rank Math and other SEO metadata, redirects, sitemaps | Registered settings/meta, provider REST/API when present | SEO field-specific schema, index regeneration, redirect collision and frontend verification |
| 18 | Fluent Forms, Gravity Forms, form submissions/config | CPT/routes and provider schema when available | Separate form-definition vs submission actions, privacy/PII, spam and notification side effects |
| 19 | Bit Flows / Make / n8n workflow operations | Plugin-defined routes and registered actions | Reviewed per-flow provider executor, dry run, outbound side-effect policy, retry dedupe |
| 20 | WooCommerce products, pricing, inventory | WC CPT/REST/plugin contract | Stock/pricing invariants, currency/tax, cross-channel consistency and transaction readback |
| 21 | WooCommerce orders, refunds, payments | WC API when present, UI menu | High-risk external payment/refund authority, irreversible effect receipts and explicit confirmation |
| 22 | Users, roles, capabilities, 2FA | Core account/admin screens | Dedicated identity-management grant, least privilege, dual checks, anti-lockout restore |
| 23 | Comments, moderation, reviews | CPT/REST/admin screens | Moderation-specific permission, spam rules, audit and reversible status |
| 24 | Cron jobs, Action Scheduler, queues | Passive cron hook names only (no args) | Named certified job ABI, idempotency/lease, cost/time limits, runtime completion evidence |
| 25 | Admin-AJAX handlers | `wp_ajax_*` registered action names | Source review of nonce, permissions, payload, effects; new exact governed Ability, never AJAX replay |
| 26 | `admin_post_*` / HTML form handlers | Registered action names | Separate server-side plan/apply adapter, sanitization and CSRF/OAuth semantics |
| 27 | REST custom routes | Already-instantiated REST route registry | Method/schema/permission review, exact typed Ability, no generic URL proxy |
| 28 | Plugin admin menu screens without server schemas | Registered/capability-filtered menu slugs; REST may not materialize menus | Plugin source inspection + manifest, fixture or owner-reviewed adapter; no inferred write |
| 29 | Transients, cache, index/rebuild, permalinks | Admin routes/cron/REST when exposed | Bounded cache invalidation/rebuild recipe and performance/SEO readback |
| 30 | Backup/restore and disaster recovery | Approved recovery operations only | Independent backup provenance, restore drill, write lease and exact environment fencing |
| 31 | Multisite/network-wide admin settings | Distinct network admin surfaces | Per-blog/network scope and independent superadmin authorization; never infer from one site |
| 32 | File editor, filesystem, direct SQL, arbitrary PHP, shell | May be visible as menu or source entry | **Explicitly forbidden generic execution**; only separate independently authorized developer service |
| 33 | Host/runtime constants and server environment | Site Profile/status evidence | Host-independent WordPress-native mode where verified; config.php/host values cannot be safely set by normal option writes |
| 34 | Webhooks, email, SMS, integrations and external services | Provider endpoints/action descriptors | Egress/secret scopes, deduplication, rate limit, consent and outbox/delivery verification |
| 35 | Bulk multi-plugin or multi-site workflows | Canonical registered per-site operations | DAG dependency isolation, dry-run preview, per-target consent, partial rollback and replay recovery |

The row catalog is a **coverage plan**, not 35 implemented execution adapters.

## Discovery behavior

New abilities registered under the existing plugin bootstrap:
- `mad4b/admin-surface-coverage` — read-only bounded inventory and
  `counts_by_kind`/coverage level. Deep scans observe registered operations,
  admin route/menu metadata, core settings keys (never option values), REST
  route definitions without calling handlers, authenticated AJAX and
  `admin-post` hook names (not callbacks), UI CPT/taxonomies, block type
  names and cron hook names (never args).
- `mad4b/admin-adapter-blueprint` — read-only proposal for one **exact
  snapshot-bound** source surface. Returns the required reviewed adapter
  artifacts and never registers/grants/executes anything.

Scan modes:
- `fast`: avoid REST, AJAX, admin-post, block and cron traversal; always
  state `not_scanned_kinds` explicitly.
- `deep`: include these sources, with bounded output and truncation markers
  (`MAX_PER_KIND=96`, `MAX_TOTAL=512`).
A missing `admin_menu` in an MCP/REST request is unknown, not proof that an
admin screen does not exist. A WordPress plugin may add dynamic JavaScript
buttons or remote dashboards invisible to PHP registration inspection.

Snapshots are SHA-256-bound to site UUID, Site Profile digest/origin, caller,
scan mode, discovered source identities, registration classification, and
category counts; an altered runtime schema or site invalidates the proposed
adapter plan.

No plugin callbacks, AJAX actions, REST handlers, `admin-post` handlers,
cron callbacks or admin form submissions are executed for discovery. Raw
secret setting values, OAuth tokens, cron arguments and login cookies must
never be included in MCP output. Names that look like credentials are
redacted even when returned to an authorized observer.

## Adapter certification and dynamic growth

Each uncovered operation should be promoted through the same bounded
source-owned lifecycle, without hardcoding a new ChatGPT prompt for every
plugin:

```text
Discover L0 -> Evidence Pack -> Typed Adapter Contract L1
            -> Source/Provider Review -> Governed Planner L2
            -> Explicit Owner Approval -> Scoped Executor L3
            -> Audit + Postcondition + Recovery L4
```

**Minimum adapter metadata:** provider/plugin identity and version,
semantic operation ID, target site/environment, available input schema,
values/defaults and secret references, required WP capabilities, OAuth
scopes, exact readback resource, external side effects, estimated cost,
idempotency token, safe retry strategy, compensation/nonreversibility,
rate/concurrency limits, migration/compatibility, tests and evidence hashes.

Preferred typed strategies:
- existing canonical `WP_Ability` + descriptor binding (fastest);
- dedicated settings/REST/entity/media/WPML/SEO provider adapter using
  the certified WordPress APIs;
- reviewed external-provider MCP through its own consent/credentials;
- **never** generic arbitrary `wp-admin` URL replay, screen scraping as
  authorization, direct SQL, magic PHP callback or shell command.

Profiles can change default values and types after authorized review.
They **cannot** change the source owner, invent a new executor, add grants,
relax a native security gate, reinterpret a failed CI check as PASS or
read the plaintext of a protected secret.

A stored profile is **sealed to the semantic operation ABI**, including
registered planner/executor names, source-owned risk classification and
capability descriptor bindings. Changing the original provider ABI invalidates
that profile and requires fresh owner-approved enrollment; a legacy unsealed
profile is not silently migrated to executable trust. `manual_only` does not
produce an MCP execution handoff. The existing original WordPress Ability
input validator is still applied to final variable values on every resolve.

### Testable acceptance categories

1. **Identity:** same site UUID, origin, WP environment, runtime HEAD,
   plugin fingerprint, tenant/network context and actor; deny clone drift.
2. **Discovery:** fast/deep completeness and explicit unknowns; no callbacks
   executed; prevent nopriv AJAX from looking like admin action.
3. **Schema:** required and enum fields, type coercion policy, version
   migrations, rejected unknown fields and large/malicious payloads.
4. **AuthZ:** current WordPress capability, enrolled user, verified OAuth
   step-up, exact ability-scoped central grant, owner approval, no
   privilege minted by the model.
5. **Concurrency:** exact plan SHA, changed profile detection, lock expiry
   handling, duplicate submissions, idempotent retry/replay and outbox.
6. **Side effects:** publications, emails, webhooks, refunds, imports,
   licensing and provider API calls require separate risk/cost policy.
7. **Postcondition:** persist/readback match, frontend/render/REST parity,
   WPML/Rank Math/JetEngine integrations where applicable.
8. **Recovery:** backup if feasible; otherwise explicitly report irreversible
   effects and compensating actions; partial progress remains resumable.
9. **CI outage:** GitHub queued is NOT itself a blocker, but exact native
   source tests, artifact provenance, independent signatures, expiry and
   owner confirmation remain required.
10. **Scale:** bounded discovery/DB/query cost, long-running job receipts,
    pagination, multi-site isolation, stale provider schema invalidation,
    checkpoint migration and plugin update compatibility.

### Practical rollout

- First: exact PHP 8.3 lint and run of `admin-surface-coverage-runtime.php`
  plus all existing admin/plugin recovery fixtures. Test end-to-end in a
  private Staging fixture, not Production.
- Second: select three different real operations: one existing canonical
  ability; one safe registered core setting with a source-owned adapter;
  one third-party plugin AJAX/REST UI operation requiring adapter review.
- Third: certify provider contracts and exact native package, install on
  Staging via existing selected-HEAD recovery lane with owner consent.
- Fourth: verify MCP catalog and governed grants then run each case from
  discovery to postcondition and rollback. Record L0/L1/L2/L3/L4 as
  evidenced runtime results per target; never infer coverage from counts.

**Current delivery status:** the inventory/blueprint source and fixtures are
committed on PR #258. GitHub workflow status alone does not prove execution,
and the installed Staging plugin must be updated before its MCP catalog
contains these new abilities. No blanket third-party plugin execution or
Production authorization is implied.
