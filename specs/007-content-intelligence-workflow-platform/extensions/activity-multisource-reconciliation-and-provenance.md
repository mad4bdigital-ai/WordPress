# MSR01 — Multi-source Business Activity Reconciliation and Provenance

**Feature 007 — 10 October 2026.** A business profile is one governed `Content Experience Profile`; WordPress and any number of Google Drive sources are **independent resources with field-specific responsibilities**, never anonymous replicas in a "latest updated wins" collection. One WordPress site may configure DMC, driver, guide, property manager, school or other activity facets without shipping industry-specific code.

## 1. Source roles must remain distinct

| Source purpose | Example | Allowed use | Never assumed |
|---|---|---|---|
| `record_data` | WP user/CPT profile metadata or a selected Google Sheet record | Field-level 3-way diff and possible guarded import/update after provider verification | All fields are authoritative because this document changed later |
| `editorial_policy` | Approved tone or editorial guidelines in a Google Doc | Content generation constraint and version impact tracking | That document supplies DMC address, guide certification or prices |
| `reference` | Competitor research, third-party catalog or business context | Read-only evidence/citation for planning | Automatic assignment of commercial facts or account identity |
| `media_assets` | Drive photos/media folder | Candidate assets with source and upload identity | Media Library ingestion grants publication or rights |

`activity_contract.sync_targets` is configured inside the **canonical** Content Experience Profile record (not a second plugin or parallel profile store). Every target has a stable source ID, provider, purpose, `resource_kind`, exact provider resource reference, direction, field allowlist and conflict policy. A Drive folder is **not** automatically a single business-record snapshot; files under it require individual independently bound and paginated identity/observations.

A source's optional `field_bindings` maps canonical profile fields to typed provider selectors (e.g. a WordPress Meta Key or Drive Sheet cell/range), with a null policy; executable expressions and arbitrary transformations are rejected. **This mapping is configuration only:** a certified provider adapter must convert and validate sheet/document values before they can be compared or written. A one-way source remains available for `update/improve/reconcile` *comparison* without gaining an unconfigured reverse transfer direction.

The existing `Google Drive` document **All Royal Egypt – Editorial Guidelines** was observed on 10 October 2026 and is explicitly labeled a candidate awaiting governed Brand Context review. It must be a context source, **not** a confirmed authoritative fact database; its private provider ID is intentionally not committed into public sample config.

## 2. Per-field ownership, not whole-file precedence

An optional `field_owners` mapping governs each allowed business attribute. Values: `wordpress`, an existing inbound/bidirectional `record_data` target ID, or `manual_review` (default). Unknown owner, owner without that field, outbound-only owner or context-source owner fails configuration. No field uses modification time to choose a winner.

Example: WordPress owns active status, availability and taxonomy terms; a Drive record might own an approved biography or supporting credentials; a separate Drive editorial document owns writing *rules*, not these facts. **Who can edit a field** is different from **who last modified a file**. Existing JetEngine metadata, reverse user links and WordPress capabilities still govern actual mutations.

## 3. Entity matching and version envelopes

- Entity identity comes from the configured `sync_identity_key`: `post_id` or a verified external stable ID; never name, slug, email or fuzzy text alone.
- One observation is bound to `site_uuid + profile_slug + entity_id + source_id + resource_id + provider revision + observed_at`. The observation records `present/missing/deleted` explicitly. Absence is not a delete.
- For each observed source, persist *independent* source revision, file/document ID, field-specific digest, and the last independently confirmed WordPress write. In the current read-only planner, the caller supplies a baseline; the planner deliberately marks this **untrusted and not authoritative**. A later execution service must load a durable provider-certified checkpoint, rather than trusting its client-supplied shape.
- A provider-supplied `verified=true` input flag is never a substitute for server-verified provenance, and field-level snapshots must include the complete configured field set. 
- Different providers have incompatible version semantics. Google Docs revisions, Sheets metadata/version and raw-file ETags must be normalized by the provider adapter. Never treat timestamps or provider revision strings as comparable ordering numbers across providers.
- Source-bound scopes and immutable per-source resource IDs prevent replacing one Drive document with a similarly named file, or listing the same file under multiple target aliases.

## 4. Three-way source reconciliation (implemented planner)

Given source observation `O`, previously accepted checkpoint `B` and one site-configured owner per field:

1. Require all relevant `record_data` sources, bounded fields, unchanged source identities, explicit `present` state and fresh individual timestamps.
2. Compare each field's **type-aware digest** against its own last checkpoint (never only against the other source's current value).
3. No source changed, same values: `in_sync`.
4. No source changed, different values: preexisting divergence → conflict.
5. Exactly the canonical owner changed, all others unchanged and previous checkpoint consistent: propose targeted copies to allowed destinations **after independent verified provider readback**.
6. A non-owner changed, two or more sources changed, source missing/deleted/stale/renamed, or previous checkpoint incomplete: conflict. No automatic resolution.
7. No operation may apply through this read-only interface. Plans expressly set `ready_for_automatic_apply=false`, `provider_receipts_independently_verified=false`, `checkpoint_authoritatively_persisted=false`.
8. The Drive Context policy source is excluded from the row-data comparison: `mad4b/business-activity-context-impact-plan` independently compares policy revisions used in a generated draft to latest observed revisions. Changed policy → regeneration *review*, not bulk republishing.

### Conflict response examples

| Objection / operational scenario | Required handling |
|---|---|
| WP and Drive edit biography concurrently | `concurrent_source_edit`; compare both versions and open human review |
| Drive changed a field owned by WordPress | `non_owner_changed`; preserve WP until owner/reviewer decision |
| Owner's Drive text changes, WP unchanged | Read-only suggested WP update; require exactly bound Drive file revision and WP CAS |
| Drive file moved/renamed | Stable ID survives move; validate read access and source binding; no name-based rebind |
| Drive file deleted, hidden, inaccessible or OAuth expired | Distinguish verified tombstone from permission failure; no propagated deletion |
| User created but WP post insertion fails | Keep recovery reservation and partial IDs; do not create a second account blindly |
| Document exists in two Drive folders | Deduplicate by Drive file ID, not path or title |
| Editor changed Brand Guidelines | Detect policy version impact; revise draft only after brand approval |
| Media added to Drive and WP in same interval | Resolve asset by cryptographic file hash/provenance and content relation; don't duplicate blindly |
| Spreadsheet cells include formulas, dates or formatted prices | Provider adapter must preserve typed values and locale semantics; hashes of display strings alone are insufficient |
| Same profile in multiple languages | Canonical entity + locale + translation identity required; never overwrite a translation from another locale |
| Production profile points to a Staging Drive file | Reject environment/resource binding drift; never promote inferred provider URLs |
| Partial outbound Drive update succeeds then WP fails | Durable outbox, provider CAS, exact readback, compensation or human reconcile; no "all succeeded" |
| Loop: WP → Drive → WP | Causality token, last-accepted checkpoint and content hash; suppress self-echo |
| Two workers sync same entity | Distributed reservation/lease, fencing token and checkpoint CAS, not timestamp-only locks |
| Admin changes field ownership while sync is pending | Profile revision/authority digest drift → cancel/replan |
| Sensitive WP user fields or Drive content shared broadly | Explicit field allowlist and provider ACL checks; no secrets or personal identifiers in public readback |
| Outage or rate limit | Bounded backoff, replay-safe idempotency, degraded read-only rather than destructive fallback |

## 5. Two-phase execution contract (not claimed implemented)

**Plan:** freeze site identity, profile authority, entity identity, exact source IDs, source versions, checkpoint revision, mapped field allowlist, redacted before/after diff, change origin, and selected owner. The read-only planner can enumerate these requirements using supplied evidence.

**Apply:** re-fetch authenticated provider snapshots, validate the **actual** existing checkpoint, acquire a per-entity fencing lease, enforce per-provider write scope, execute an exact if-match/write/update to *one* provider, record operation identity and provider receipt, then fetch the same resource again and validate readback. Repeat only for allowed destinations. Persist the new checkpoint **after all destination readbacks**. Fail closed on partial operations and record compensating tasks; never infer a 2PC transaction spanning Google Drive and WordPress.

A scheduled background watch requires enrolled provider connectivity, event/cursor checkpoint, retry journal, deduplication, ACL lifecycle, tenancy binding, and stateful operation processing. The present feature does not include that executor. The general WordPress Google Drive plugin available to ChatGPT is a user-facing connector, **not automatically a verified server-side connector installed inside any WordPress site**.

## 6. Adversarial acceptance gates

- Migrate an older activity profile (missing owner mapping) to manual-review ownership without widening grants.
- Validate 1, 2, 3 and 12 configured sources; 13th rejected; duplicate source or resource ID rejected.
- Unknown field, sensitive key, outbound-only canonical owner, editorial policy acting as business owner rejected.
- Baseline not certified, incorrect source revision, changed site/profile revision, missing resource, stale observation and deleted file must not produce executable writes.
- Same-field concurrent WP/Drive/Drive edits create explicit conflicts; owner-only update creates **advisory** proposals.
- Approving editorial guidelines does not create user accounts or mutate CPT meta; editing a user profile does not rewrite global Brand Core documents.
- Native PHP fixture, WordPress+JetEngine profiles, real Drive Doc/Sheet provider readback, simulated mid-flight crash, rollback and browser acceptance required before production.

## Implementation status
Source implementation in Draft child PR #366: optional `field_owners`, `sync_identity_key`, and `purpose/resource_kind` per target; `MAD4B_SCP_Activity_Source_Reconciliation::plan()` and `context_impact_plan()`; read-only `mad4b/business-activity-reconcile-plan` and `mad4b/business-activity-context-impact-plan` in canonical Experience abilities. No extra tourism plugin and no automatic Drive file write. The PHP fixture covers clean, canonical-only, non-owner, concurrent, stale, missing, deleted, changed resource ID, divergent checkpoint, untrusted client verification, and policy-revision changes. It is committed but not a substitute for native runtime acceptance.
