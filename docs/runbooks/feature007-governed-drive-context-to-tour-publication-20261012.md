# Feature 007 — Governed Google Drive Context to Tour Publication

Date: 2026-10-12. Scope: MAD4B WordPress Control Plane, **Staging only**. This is an operational handoff, not a release certificate or authorization to bypass original source ownership or publish competitor-owned material.

## Problem and source fix

The AI/owner mutation-ticket planner previously rejected `context/governed-source-upsert` because its `label` field was detected by heuristic brand-text screening as potentially brand-bearing, with no provider-specific field contract. Likewise the owner transfer's `owner_evidence_reference` and asset review's `review_note` may be long audit metadata; lacking explicit provider classification would block otherwise eligible requests.

`config/semantic-content-field-contracts.json` now contains exact `google_drive_context` semantic declarations for:
- `context/governed-source-upsert`: exact folder ID, descriptive label, recursion/write policy, registry CAS fields
- `context/legacy-owner-transfer-apply`: source/proof IDs and exact reviewed owner reference, never a free-form ownership claim
- `context/remote-owner-review-apply`: exact asset decision/plan and review audit note, not new publishable brand content

The same catalog now also covers six brand-bearing text operations — `context/brand-draft-create`, `context/brand-draft-append`, `context/brand-profile-save`, `context/create-drive-asset`, `context/update-drive-asset`, `context/recreate-drive-asset` — and two indirect materialization operations `context/materialize-brand-draft` and `context/reconcile-brand-materialization`. Their **inline text is explicitly brand-bearing**, preserving the signed Context receipt/policy requirement; only exact artifact IDs, revisions, formats and source pointers are classified as non-content metadata. A reviewed source is still mandatory before such text becomes publishing authority.

These fields are **operational/audit metadata, not brand copy**. The semantic classifier never creates authority. Unknown content fields still fail closed. Provider proof, Google Drive permissions, original folder and file identities, administrator OAuth, exact mutation ticket, policy, provenance/manifest binding, idempotency and audit remain independent mandatory checks. A source label or approval note is not proof of ownership. No wildcard/bypass rule was added.

Regression fixture `tests/context-semantic-source-approval-runtime.php` checks all three metadata operations and adversarial extra brand-content fields plus ordinary tour content requiring context. Registered in `tests/feature007-manual-preflight.py` for native PHP 7.4 and PHP 8.3. A source-level PASS is **not** a site deployment or browser certification.

## Generalized, discoverable execution sequence

1. **Pin environment and capability identities:** read `mad4b/session-safe-diagnostics`, Site Profile, current build, exact candidate binding, NHI grants and `mad4b/chatgpt-tool-projection-discover`. Require enrolled `staging`, current origin, current hashes. Never rely on a fixed client tools/list.
2. **Inventory the requested exact Drive folder:** use Drive's folder metadata and all direct child pages, honoring provider `next_page_token`. If recursive scope was approved, traverse child folders with bounded depth/item budgets; otherwise `recursive=false`. Record each file ID, MIME type, parent, revision, owner/provenance evidence and intended use. Reject implicit `My Drive` root, unbounded traversal, unrelated files, and silently broadened source scope.
3. **Classify each document before authorization:** Brand Strategy, Tone of Voice, Editorial Guidelines, SEO Research and commercial supplier evidence are distinct categories. A keyword planner Sheet is research, **not** a Brand Strategy; a folder-wide user approval does not change the content's category or prove supplier reproduction rights. Keep originally quarantined legacy Context records separate.
4. **Create source under reviewed boundary:** discover `context/governed-source-upsert`; plan an exact one-time governed mutation ticket on `mad4b-write` using selected source root and current `registry_revision` and `authority_manifest_fingerprint`. Default `write_policy=read_only`, `recursive=false` unless separately approved. AI owner delegation can approve eligible ticket only with exact live OAuth scope and grant; else use human decision; no AI-manufactured owner-proof.
5. **Re-read and scan:** fetch source registry and `context/source-scan-plan` for the new source ID. Scan/apply under independent exact one-time ticket; compare provider inventory/revision/parent lineage. Stop if credentials are stale, original folder is missing, source is foreign, or scan incomplete. Enqueue exact pending assets (never infer all files approved from folder registration).
6. **Review each eligible asset:** use `context/review-queue` and `context/remote-owner-review-plan` (or classified `mad4b/context-ai-review` where permitted); only `context/remote-owner-review-apply` under the verified administrator OAuth subject and ticket bound to same owner may attest original owner review. For document bytes, require content hash/revision; re-review changed content. Retain per-file receipt/audit and verify `context/assets`.
7. **Recover Legacy quarantine separately:** use `context/legacy-reconciliation-census`, `context/legacy-owner-transfer-discover`, `context/legacy-owner-transfer-evidence`, and exact transfer plan. If no eligible candidate or records are foreign/conflicted, stop this lane and request independent original ownership proof. Do **not** re-register quarantined assets as fresh to bypass this guard.
8. **Verify Brand Core:** `context/brand-core-coverage` must return current approved, non-conflicting `brand_strategy`, `tone_of_voice`, `editorial_guidelines` with traceable content hashes. If any missing, do not fabricate strategy or call folder approval sufficient. SEO research remains available as separate non-authorizing evidence.
9. **Stage one tour:** `mad4b/aci01-intake-preview` identifies native `tours-and-activities` type, taxonomies, relations, locale. `mad4b/content-experience-bootstrap-plan` proposes a draft-first profile, then exact profile apply and next-request profile readback. Inspect existing posts and duplicate identities. No direct live publication as first action.
10. **Fact, rights and media gate:** the Memphis Tours Royal Lily page is a competitor **research** source, not a supplier agreement or syndication licence; don't copy paragraphs, photos or unverified cruise itineraries. Resolve the Royal Lily / Salacia discrepancy against independent authorized supplier evidence. Require rights proof for every external image, verified vessel, routes, prices, departures, inclusions and availability; unknown fields are omitted, never invented.
11. **Draft, QA and publish independently:** plan/create a **nonpublic draft** with brand receipt and exact approval; verify post identity, JetEngine relations, taxonomy, WPML locale, Rank Math metadata, media, accessibility, frontend DOM and preview. A separate, approved `publish-plan` and one-time `publish-apply` may transition to public only after current Brand Core, rights, content quality, independent edge/readback receipts, publish authority and duplicate checks all pass. Record stable URL and rollback/undo reference.
12. **Failure recovery:** keep receipts at each phase; on post-write readback mismatch stop and invoke governed compensation/rollback if eligible. Fail closed on OAuth lapse, source/manifest drift, asset revisions, ambiguous permissions, broken dependency or missing runtime profile. Never automatically fall back to raw SQL, unsandboxed Developer, unapproved GitHub CI bypass, or Production.

## Known Staging blockers at initial investigation

- Existing Control Plane on Staging was running the prior package SHA `fa9f8e7eedac3446ec679760996e4812805373d0`; the semantic fix is on a **new PR head** and requires its own exact ZIP build, installation and independent native acceptance before the planner can use it.
- Drive folder `AllRoyalEgypt Brand` had two **direct** items on the observed listing: a candidate Editorial Guidelines Google Doc and a Nile Cruise Keyword Stats Google Sheet. This inventory is a snapshot, not permission to copy content into every Brand Core category.
- `context/brand-core-coverage` previously reported 0/3 ready. `context/legacy-owner-transfer-discover` reported no eligible unbound candidates and one foreign/conflicted legacy record; existing quarantine must not be silently adopted.
- The WordPress-managed Google Drive connection reported a token refresh requirement. Direct ChatGPT Drive access and WordPress site's *separately stored* managed Google credentials are not interchangeable.
- A nonpublic draft/public page had **not** been created; do not report this runbook as a published tour.

## Acceptance evidence required

- Native isolated PHP fixture `context-semantic-source-approval-runtime.php` **PASS** on PHP 7.4 and 8.3 (and package PHP-tree lint)
- Exact changed-path ledger closure and G9 source fingerprints
- New exact-head package ZIP signature/digest and Staging install & candidate binding
- Successful `mad4b-approval-plan` for `context/governed-source-upsert` (metadata-only) with same-subject authenticated decision and execution readback
- Source scan inventory parity with requested files, independent per-file review and asset hashes, no quarantine bypass
- Brand Core 3/3 or explicit blocker, actual tour-only rights/content validation, draft and public URL plus rollback readback if eligible

Always label **not executed** stages honestly. No source edit by itself is a publication receipt.

## 2026-10-12 lifecycle hardening — Google Full Grant is not provider write authority

Observed on **All Royal Egypt Staging** after user-initiated OAuth reconnection:
Google dedicated OAuth became `connected=true`, `credential_fresh=true`,
`refresh_required=false`, with Drive Read + Write granted. This fixes the
**OAuth** gate only. `context/status` still showed **0 governed sources and
0 governed assets** and 1 source / 12 assets in legacy quarantine.
`context/update-drive-asset` was still `DISCOVERED` and in `shadow` due
to missing first-party artifact evidence and selected source. No Drive write
was made in this investigation.

The revised lifecycle maintains **independent, ordered gates**:

1. **OAuth grant:** available scope and valid refresh capability. An expired
   access token is refreshed only on a provider request, never on passive
   WordPress admin rendering; no reconnect unless refresh actually fails.
2. **In-tree provider identity:** first-party `google_drive_context` can
   demonstrate exact current build fingerprint plus the six fixed, readable
   Context implementation file SHA-256 values and current rollback contracts.
   This is *runtime identity consistency*, NOT independent signed-package
   attestation and NOT behavioral or rollback certification. Unknown, stale,
   untrusted, partially matched or non-in-tree providers fail closed.
3. **Context source boundary:** choose a specific Drive folder; verify current
   Site Profile, original folder/file ownership, host identity and selected
   read-only policy. Never adopt legacy quarantine automatically.
4. **Behavioral and rollback evidence:** certify bounded update/recreate
   readback and exact compensation under a governed owner-approved canary.
   Source policy and OAuth alone never make a write eligible.
5. **Create prohibition:** `context/create-drive-asset` remains blocked with
   `mad4b_google_drive_create_rollback_not_certified`. The scoped Brand
   materialization and rollback mechanism is **not** interchangeable with an
   arbitrary unmarked generic Create. Only an exact independently validated
   creation identity, registry compensation and deletion/restore receipt could
   support a future separate certified create capability.
6. **Approval and execution:** after the previous gates, revalidate current
   NHI grant, exact input, one-time approval ticket, revision/CAS, Context
   Receipt for brand-bearing text, write postconditions and rollback evidence.

The read-only `grant_operational_lifecycle` field under
`context/google-drive-status.write_capability` now exposes OAuth, source
selection, policy eligibility, independent certification and generic Create
denial. `mad4b/provider-closure-matrix` exposes a `prerequisite_chain` on
each blocked write row, **without** ever setting `auto_activation_allowed`.
The first-party artifact path is implemented in
`class-mad4b-scp-provider-compatibility-certification.php`; bounded
write capabilities remain `shadow` until the required trusted behavioral
and rollback evidence arrives.

**Host-local Binding approval deadlock:** the binding Apply input schema
formerly required `_mad4b_approval_ticket_id` before
`mad4b/approval-plan` could create one. The schema now permits planning
without the pre-existing ticket, but Apply still validates and claims the
real one-time ticket before filesystem work. A Host-local binding alone does
not replace independently signed host proof, durable crash recovery,
source ownership review or Staging deployment acceptance.

**Source-only regression coverage:** run the native PHP fixtures
`provider-first-party-context-artifact-runtime.php`,
`provider-google-grant-prerequisite-chain-runtime.php`,
`google-grant-operational-lifecycle-runtime.php` and
`deployment-binding-native-runtime.php`. The first three are wired into
`.github/workflows/feature-007-pre-staging-hybrid-audit.yml` on PHP 7.4;
additional PHP 8.3 package verification and a fresh All Royal Staging
readback are still required. A queued CI run or uploaded source commit
does not imply certification.

**Operational next steps:** package the exact PR head; verify package
fingerprints and dependency/rollback preflight; install **Staging only**;
reconcile exact candidate Write binding; use newly functioning
`mad4b/deployment-binding-native-plan` → one-time
`mad4b/approval-plan` → permitted decision → `...-apply` → new-request
readback; then run independent host proof, folder inventory and owner
attestation. Register a new strictly bounded read-only Context source where
eligible, scan every page and classify/approve the three Brand Core categories
separately. After Context Receipt, test one *nonpublic draft* and verify it;
publish only via a separate approved transition. Never widen Google OAuth,
auto-migrate quarantine, or enable arbitrary Create to make a report green.
