# IMP10 — Dynamic Mapping Mutation/Maturation, Schema Drift and WPML Readback

**Feature 007 / Child PR #366, 2026-10-10. Exact site-owned policies; source code implemented, native/live acceptance NOT_RUN.**

## Why this feature matters

Dynamic mapping is not a single "match similar column names" algorithm. It is a **bounded, authority-bound, versioned process** where changing schemas trigger distinct steps:
`DISCOVER → OBSERVE DRIFT → PROPOSE → SIMULATE → HUMAN APPROVE PROFILE REVISION → STAGING APPLY → INDEPENDENT PROVIDER READBACK → CERTIFY`.

Schema drift is observed change, evolution is governed policy, and maturation means a proposal gains trust **only through evidence** (not through repetition or AI confidence). The installed plugin cannot claim a universal provider mutation mechanism because WordPress posts/meta, JetEngine CCT/tables, WPML link tables, WP All Import jobs, Google Sheets and Drive have different transactional and version semantics.

## Implemented source changes

### A. `mad4b/business-activity-import-mapping-evolution-plan` (read-only)

Inputs support **one of two exclusive evidence routes**:

- **Before upload:** `{profile_slug,observed_headers,expected_profile_authority_sha256}`. Bounded set of 1–80 header identifiers. This mode is important because an invalid source with a renamed required column cannot be staged as an approved encrypted snapshot. Header-only review is explicitly *not* data verification.
- **After upload:** `{profile_slug,snapshot_sha256}`. Decrypts exactly the current Staging source; matches site UUID, Profile revision/authority digest and policy digest; samples up to 100 rows per mapped source column only for redacted type-count observations.

The response contains:
- Current site-owned source→Meta/WPML mapping, changed/missing input columns, unexpected and quarantined columns.
- **Lexical-only** source alias suggestions (case/underscores removed), each marked *not semantic proof*; many-to-one or ambiguous aliases cannot auto-resolve. No values, customer data or secrets in the plan.
- Criticality and independent acceptance conditions for identity fields, monetary fields, date intervals, WPML language/link columns and relationships.
- Bounded counts of observed scalar types and blocking price/date type anomalies, without source cell values.
- Current Profile and source hashes, proposed changes, missing/stale destination allowlist checks, explicit provider gaps, and stable deterministic `plan_sha256`.
- `mapping_mutation_authorized=false`, `third_party_write_authorized=false`, `ready_for_production=false`.

### B. `mad4b/business-activity-import-mapping-mutation-simulate` (read-only)

Input is the exact previous drift evidence + `proposal_plan_sha256` + *complete candidate* `candidate_validation`.

- Replays the drift plan (rejecting stale Profile/source hash), normalizes candidate through the **same existing site-owned validation authority**, and refuses unknown/unapproved destination Meta.
- A destination identity registry change is a separately governed identity migration — **never a source-header rename**.
- Checks every required/identity/pricing/date/relationship/WPML source column exists in observed evidence, binds proposed validation hash, and labels high-impact changes.
- Does **not** persist modified profiles, modify source or WordPress posts, update WP All Import, infer JetEngine relationship rules, enable fallback Modes, grant Production or issue an import permit.
- Before any future actual application, operator must compare sample business meaning and supplier rights, secure exact Profile approval, archive/revalidate old snapshots, and verify the installed provider's native results.

This creates a *simulation and approval intake*, not an automatic mutation engine. The existing `content-experience-profile-plan/apply` is the later governed route if and only if an owner separately authorizes it.

### C. `mad4b/business-activity-import-wpml-readback` (read-only)

An independently reviewed encrypted snapshot is inspected one bounded translation group at a time:

1. Resolve each source record via a **site-approved external identity Post Meta**, using an unfiltered all-language WordPress post query limited to two results. Reject missing/duplicate identities and collation-distorted matches.
2. Retrieve native WPML `wpml_element_trid` and `wpml_element_language_details` per found post; check expected language code and common internal `trid`.
3. Use `wpml_get_element_translations` to independently verify every language points at the exact expected post ID, and required languages exist under the approved Profile.
4. Return source-group digest, status, failures and next group cursor, **not** raw supplier text.
5. Never assume the source `_wpml_import_translation_group` value equals WPML's internal `trid`. WPML post link readback is **not** WP All Import source provenance or JetEngine relation certification.

WPML hook parameter differences matter: `wpml_element_trid` and `wpml_get_element_translations` use `post_<CPT>`, while the `element_type` argument of `wpml_element_language_details` takes the raw post type. See WPML's published hook contracts.

## Deep objection matrix

| Scenario / objection | Required behavior | IMP10 implementation boundary |
|---|---|---|
| `single_price` becomes `singlePrice` | Propose alias, preserve destination Meta only after validation | Header-only draft + what-if; no auto apply |
| `single_price` becomes `total_price` | Never assume equal commercial meaning | No unverified fuzzy auto alias |
| Price string becomes a nested object or date | Quarantine semantic type drift | Redacted type-count observations; no coercion |
| `ERU` appears in amount currency column | Reject under site currency allowlist; never fix to EUR automatically | Existing import authority remains binding |
| `puplished` appears as status | Fail/flag; never silently publish | Existing importer status review |
| An external ID column changes | Treat as identity migration, not cosmetic edit | Critical blocker; immutable destination registry denied |
| Provider Meta key disappears | Detect no-longer-allowlisted destination | Explicit conflict, no silent rebind |
| A source adds a field not in destination | Quarantine until approved field ownership | No freeform Meta/CCT injection |
| Two old fields could be renamed to one new field | Ambiguous collision | Fail closed; human mapping decision |
| WPML source group `GRP-1` links two different trids | Verify actual WPML native relationships | Reject on mismatched native trids |
| WPML group has all languages in CSV but only some posts linked | Reject incomplete native readback | Per-group exact post/language check |
| WPML filter is absent or plugin installed but inactive | No fake acceptance from class existence | Refuse provider readback |
| JetEngine CPT Meta vs custom CCT table | Never guess that post_meta query verifies CCT | CCT driver and native tables remain unimplemented |
| Imported post values match but job used a different CSV | Do not attest source provenance | WP All Import hook observation still unverified |
| A user changes Profile after proposal | Reject stale approval | Exact revision, authority digest and mapping proposal hash |
| Two users approve same proposal concurrently | No implicit shared transactional apply | Later Profile apply must use its own CAS and audit; NOT certified |
| Make/n8n and WP All Import update same record | Avoid self-declared global lock | Shared external writer fence NOT delivered |
| Google Sheets online editor changes same row while Apps Script runs | LockService alone cannot prove CAS | Native Google revision/conditional write NOT delivered |
| Host or provider times out mid-write | Recovery must inspect authoritative post-state before retry | External write rollback and fence NOT delivered |
| Approved Profile conflicts with brand/supplier rights | Independent Brand Core/rights gate | AI mapping hints cannot approve licensing |
| Hundreds of new fields / source rows | Bounded inspection and explicit pagination | 80-header/100-row sample; no bulk auto-update |
| Production site differs from Staging | No promotion from dry-run | Owner approval + exact binary/site acceptance still required |

## Intended maturation registry (design boundary)

- **Observed** — signed Profile/source evidence and drift digest only.
- **Proposed** — deterministic suggestion + operator-supplied complete candidate.
- **Simulated** — source field availability and type validation on a controlled sample; does not certify provider effects.
- **Governed** — only after exact Profile plan/apply, owner approval and durable authority readback.
- **Provider verified** — installed-version-specific independent WPML/CCT/Meta/Google Sheets checks, all pages and language groups accepted.
- **Production eligible** — separately authorized release and independently certified rollback/coordination.

Only the **Observed** and **Simulated (schema-only)** stages exist in IMP10 code. A guessed confidence score, a static source check, or a WPML hook event **must never** promote an operation into a later stage.

## Acceptance and operational prerequisites

- New native PHP fixture `imp10-mapping-evolution-runtime.php`: renamed header candidate, proposal exact hash, impossible target, private Meta rejection, stale authority and source policy override.
- New `imp10-wpml-readback-runtime.php`: native filter mocks, distinct real `trid` detection, exact IDs, language membership, invalid group index.
- Static source contracts and PHP lint enrolled in `feature007-manual-preflight.py`.
- **NOT_RUN:** PHP 7.4/8.3 Staging lint/fixtures, MySQL concurrency, browser/RTL, genuine WPML/JetEngine CCT/WP All Import/Google Drive and Sheets exact-version readbacks; linked All Royal Egypt WordPress connector currently returned internal errors.
- **NOT_DELIVERED:** global CAS, third-party WP All Import/MSR02 shared lease, transaction-aware compensating rollback, a signed cross-provider driver catalog, automatic schema migration/apply and Production readiness.

**Go/no-go:** permitted read-only introspection and what-if simulation on enrolled authorized sites; forbidden automatic Mapping mutation, unverified business edits, automatic import or Production deployment.
