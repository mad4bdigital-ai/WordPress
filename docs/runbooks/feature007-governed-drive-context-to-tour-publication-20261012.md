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
