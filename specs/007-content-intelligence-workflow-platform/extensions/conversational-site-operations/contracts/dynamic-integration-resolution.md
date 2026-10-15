# CSO01 — Dynamic Integration Resolution & Conflict-Safe Execution Contract

**Status:** Binding design + read-only source guardrails, not a certified write implementation. Candidate #368; do not infer permission, secrets handling, full renderer or Production readiness from this document.

## Purpose and non-negotiable principle

An observed WordPress plugin, registered Ability, CPT slug, option key or meta key is **evidence**, never a write target or grant. Resolve every task by **semantic intent → exact enrolled site/blog/environment → capability producer → runtime version/feature → registered read shape → independently certified operation lane**, then decline unsupported state with an actionable reason.

CSO01 should be usable on All Royal Egypt, Egypt Tour Gates, other WordPress sites, clean installs and WordPress Multisite without site-name branches or assumptions about a particular plugin.

## Provider-neutral integration identity (candidate data model)

Use typed descriptors registered with existing MAD4B capability/adapter infrastructure. This is a **logical contract**, not a new authorization subsystem:

| Attribute | Meaning and trust rule |
|---|---|
| `site_ref` | exact Site UUID + canonical origin + environment + blog ID + enrollment identity + runtime generation; hostname alone never conveys Staging |
| `provider_ref` | explicit adapter ID and native plugin basename, observed version, package/runtime fingerprint, signed certification generation |
| `operation_ref` | canonical Ability name, original execution lane, input/output schema digests, permission callback identity, side-effect classification |
| `semantic_ref` | domain/field identifier, locale, post type, taxonomy, relationship or site option, cardinality, normalized value type |
| `storage_ref` | opaque registered WordPress native API/adapter operation; no arbitrary table/option name resolution or raw SQL |
| `source_of_truth` | authoritative provider **per field**, optional mirrors (WordPress, Google Drive, CRM/CDN), approved merge policy and sync direction |
| `preview_ref` | schema digest, current observable revision, display-safe enum/choices, independently read current values when permitted |
| `execution_ref` | operation-level grant, fresh authority, exact approval, idempotency key, lock/CAS requirement, checkpointed side effects |
| `proof_ref` | receipt, independent readback, browser or Host attestation when relevant, privacy/redaction/retention basis |
| `rollback_ref` | native revision/compensating operation if truly supported; otherwise explicitly irreversible/partial |

A compatibility adapter can only state **READY_FOR_READ_PREVIEW** after schema/identity preparation. Actual read dispatch still has separate authority. **READY_FOR_WRITE** requires independent per-capability artifact, behavior, authorization, concurrency and rollback certification. Unknown = deny; no fallback to database writes.

## Source-of-truth conflict resolution

For a field in WordPress + Google Drive + another service, resolve by explicit, versioned policy:

1. Store `field_owner` as exactly one authoritative source, and `mirrors` as observational targets. Never use global “Google Drive wins” or “WordPress wins”.
2. Use `field_revision`, `source_generation`, `last_approved_digest`, `observed_updated_at` and signed connector evidence; timestamps alone cannot prove order across clocks.
3. Mark `IN_SYNC`, `SOURCE_CHANGED`, `MIRROR_CHANGED`, `CONFLICT`, `UNAVAILABLE`, `PARTIAL_APPLY` or `UNKNOWN_COMMIT`. Do not silently overwrite on CONFLICT.
4. Preserve an immutable approved version and incoming candidate. Render a value-redacted diff until authorized to read values; secrets never go through chat.
5. On disconnected connector, commit to the source only if the configured policy explicitly permits partial propagation and queues a durable replay with dedupe. Never claim two-sided success before two independent readbacks.
6. Re-evaluate field ownership, rights, locale, privacy and approval on every execution. Migrate ownership only through an explicit plan; never infer from discovery order.

## Vendor/feature-specific operating constraints

- **Elementor:** templates, global widgets, dynamic tags and theme assignments can affect pages outside target. IDs are local to site and translation; preview DOM and condition evaluation must use the exact render context. Don't mutate JSON trees without a provider-native operation and original revision.
- **JetEngine:** CPT, taxonomy, meta box, relation, repeater, Query Builder and Listing Grid are separate facets. Account for serialized values, bidirectional relations, cycles and field conditional logic. Version drift on All Royal (installed 3.8.15.4 vs baseline 3.8.11.2) proves exact recertification is essential.
- **WPML/Polylang:** language and translation groups are first-class in field identity. Enforce per-locale permissions and avoid changing a source language by writing a translation ID. Verify hreflang, slug and canonical parity independently.
- **Rank Math/SEO:** distinguish Free/Pro, schema graph changes, redirects/sitemaps and metadata; output schema != input schema. Never assume every form attribute has a safe reversible write path.
- **Fluent Forms:** form definitions are not entry/submission values. PII, CAPTCHA, webhook secrets, notifications, automation and spam logs are distinct protected data classes.
- **WooCommerce:** plugin may have catalogued abilities while absent. Product/variation, inventory, pricing, tax and order/payment updates need transactional business constraints; do not auto-retry non-idempotent commerce effects.
- **Media:** attachment, original bytes, EXIF, alt/caption, translations, derivative sizes, CDN objects and external license/rights are distinct lifecycles. Deletion cannot assume remote purge or backup rollback.
- **Bit Flows/automation:** execution eligibility and configured credentials are separate. Avoid webhook recursion, duplicative form submissions, lost queue lease and exactly-once promises.
- **WordPress core/third-party settings:** option/autoload, multisite network option, WPML option translations, private meta and serialization need explicit native capability adapters, never generic update_option() inferred from a key.
- **Google Drive/brand context:** Drive docs may be a canonical brand source for one field but merely supporting evidence for others; handle moved files, revoked grants, document permissions, revision conflicts, offline operation and retention per tenant.

## Typed control decision matrix

| Input type | Generic read-only handling | Deferred execution requirement |
|---|---|---|
| scalar string/int/float/bool with bounded min/max | Render as **no-save**, validate exact constraints; never echo submitted values | Versioned native read/write contract + fresh plan/approval |
| enum | Only bounded non-sensitive literal options; reject HTML, credential-like, PII-containing values | Explicit provider choice source and field-level disclosure/permissions |
| nested object/list/repeater | **Unsupported**, no silent flattening | Recursive schema, depth/cycle limit, correct array indexes and typed renderer |
| dynamic relationship/taxonomy | **Unsupported** until certified autocomplete source | Scoped bounded server search, pagination and per-object permission |
| HTML/rich text/JSON-LD | **Unsupported** generic renderer | Sanitizer, full document diff, rendering preview, CSP, rollback semantics |
| attachment/gallery | **Unsupported** generic field input | Isolated upload, rights/virus check, media metadata, variants, cleanup/readback |
| secret/credential | **Never accepted in chat form** | First-party isolated secret handoff, no plaintext return or cross-environment copying |
| conditional/oneOf/allOf/custom vendor schema | **Unsupported**, preserve exact reason | Versioned field resolver and all branch/discriminator tests |
| server-controlled/hidden/default | Never infer writable field; defaults not echoed | Server-side authority and storage contract; hidden fields excluded |

## Concurrency and failure semantics

No cross-plugin atomic transaction is assumed. Plan is an append-only saga with each step in one of `PLANNED`, `AUTHORIZED`, `RUNNING`, `VERIFYING`, `SUCCEEDED`, `PARTIAL`, `UNCERTAIN`, `FAILED`, `COMPENSATING`, `COMPENSATED` and `ESCALATED`. Each step records:

- exact site/blog/provider/descriptor generation and lock or expected revision;
- idempotency key and the **stable external side-effect identity**, if any;
- explicit timeouts and retryable vs permanent errors;
- scope of write and known compensation availability;
- independently verifiable postcondition (plugin native readback, downstream readback, browser/AJAX parity if relevant).

Never rerun an unknown external action until reconciliation establishes whether it committed. Compensating one change does not automatically undo a WPML link, web-hook, CDN object or payment. On provider update, stale approval, changed actor or changed site fingerprint, abort/replan.

## Product experience requirements

1. Ask only for missing information, use compact form controls and progressive disclosure.
2. List **what is known, unsupported, unknown and not authorized** separately. A form preview is not an editable form.
3. Search first with declared-read-only metadata filtering before top-N ranking; support Arabic Alef/diacritics without changing identifiers or values.
4. Bounded search should state `non_exhaustive`, counts and suggested refinements; don't imply a 644-Ability index fits into one ChatGPT tool list.
5. Show field provenance and impact without leaking options/secrets/PII; use non-sensitive labels, keyboard/RTL and screen reader semantics.
6. Recover from expired OAuth, lost connectivity, plugin deactivation, schema change and invalid/missing fields with one actionable next step, not generic “try again”.
7. Require clear review and approval before saving; afterward show independently verified results, links, partial failures and compensation options.
8. Provide safe operator fallback when ChatGPT native interactive widgets are unavailable. Never simulate Save with a chat reply.

## Non-negotiable acceptance suites

- **Provider matrix:** no plugin, deactivated, conflicting, pinned exact version, drift, shadow/canary, certificate revoked, paid feature unavailable.
- **Schema matrix:** no input, empty root default, min/max, Arabic, enum PII, token-looking query, nested, cyclic, polymorphic, JSON-LD, media, hidden.
- **Authority matrix:** different actor, revoked role mid-form, different site, same-origin clone, Staging/Production mismatch, Multisite blog switch.
- **Persistence matrix (future write slices):** WordPress native, custom tables, serialized option, JetEngine relation, Elementor template, WPML locale, external Drive mirror, remote CDN.
- **Execution matrix (future write slices):** interrupted operation, timeout after commit, duplicate request, two writers, stale revision, approval expiry, partial compensating write, provider upgrade between plan/commit.
- **UX/performance:** 644+ Abilities, >25 write hits before a relevant read, Arabic normalized query, 320px view, keyboard navigation, accessible errors, stable memory/query/time budgets.
- **Evidence:** native exact-HEAD PHP 7.4/8.3 + independent Staging Host/browser/MCP, second unrelated site, privacy redaction, no Production mutation. Every failed test blocks the corresponding capability's certification only, not the whole site.

**Current boundary:** PR #368 implements only discovery, exact read schema preparation, safe input validation and refusal states. This document is a target operating contract, not proof of generic edits, approval, secure secret ingress, dynamic remote sync or production-ready functionality.
