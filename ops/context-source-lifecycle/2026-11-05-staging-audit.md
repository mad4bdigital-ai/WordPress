# MAD4B Context Source Lifecycle — Staging Audit (2026-11-05)

**Scope:** `tours.dev.mad4b.com`, plugin `mad4b-site-control-plane`, both `google_drive` and `wordpress_media` source providers. This document contains *observed* runtime behavior and read-only/hermetic tests. No Production changes, content approval, registry Apply, media deletion or cross-brand ownership transfer was performed as part of this review.

## Live status / tested identity

- Context Authority: 1 registered source, **0 registered assets**, registry Revision 83.
- Active source: Google Drive folder **Brands**, mode `governed`, provider `google_drive`, still `selected` and `last_scan_complete=false`. Source remains restricted to its existing Site/Brand identity.
- Google connection: `connected=true`, `token_healthy=true`. No OAuth reconnect needed.
- Context blockers: `governed_context_source_scan_incomplete`, `governed_context_assets_missing`.
- The 54 pending WPML Tour translation Content Jobs are **not** thereby unblocked for writing.

## Provider comparison

| Lifecycle phase | Google Drive | WordPress Media | Judgment |
|---|---|---|---|
| Register | Site+Brand-bound folder root, governed or task attachment; OAuth grant checked | Single site-wide library, `manage_options` + `upload_files` + policy, root canonicalized by Context Authority | Both provide source identity; UI entrypoints differ |
| Select scope | Exact Google folder, recursive option | MIME checkbox scope, not folders/selected IDs | WordPress is broad site-wide scope; document this distinction |
| Start scan | Legacy one-request `scan_folder` had 10-second wall budget; fixed UI buttons now set `resumable=1` | `inventory` reads **all** eligible attachment IDs for chosen MIME types (max 1000), one synchronous request | Non-parity; P1 |
| Resume | Sealed cursor/scan-page/checkpoint supported and verified | No cursor/checkpoint; complete-inventory only | Non-parity; P1 |
| Normalize | Drive exports/text/PDF/spreadsheet via provider normalizers | UTF-8 text, markdown, CSV, PDF text, XLSX/XLSM; OCR-needed or unreadable attachments rejected | Safe fail-closed, poor per-file recovery |
| Register observed assets | Complete Drive scan requires exact Plan SHA256, provider digest, registry revision, explicit Apply | Complete `scan_complete` syncs unreviewed assets to registry without separate Apply; human approval still distinct | Different staging semantics; document explicitly |
| Review | Registry review with content hash/revision/actor proof | Same registry review | Positive shared control |
| Read back approved Content | Gateway has `google_drive` provider implementation | **Gateway rejects `wordpress_media`** | **P0 correctness gap** |
| Re-scan modified assets | Complete inventory and revision/content hash checks | Raw attachment hash/version reuse; error if a file cannot be read | Safe admission but all-or-nothing |
| Missing/deleted | Only certified complete scan can mint absent/deleted evidence | Scoped MIME absence logic avoids affecting unrelated types | Positive safety control |
| Remove source | Registry removal audited, external Drive files not deleted | Registry removal audited, original WP attachments not deleted | Review decisions/index removed; implement recoverable archive if required |

## Verified tests

1. **Google Drive pagination (live, read-only):** 12 bounded `source_scan_plan` requests, approximately 13 seconds total; new-file tally progressed from 1 to 10, always `scan_page_pending`, resumable cursor present; still incomplete, **0 assets Applied**.
2. **Google Drive admission (hermetic):** sealed multi-request 25-file fixture PASS; exact Plan/Apply confirmation, stale revision and forged Plan rejection PASS.
3. **WordPress Media inventory (live, read-only):** text/markdown 5/5 complete, CSV 28/28 complete; XLSX scope fails `mad4b_media_file_unavailable`; combined default scope fails before any Apply. Existing attachment ID `66375` exists and is 2,338 bytes, but is unreadable to PHP (`www-data`) because owner/group root and mode `0660`; parent directory mode `0770`. **Do not fix by globally loosening permissions.**
4. **WordPress Media form:** six MIME checkboxes all checked by default; admin nonce and POST present, no cursor/progress and no per-attachment diagnostic surface.
5. **WordPress Media tests:** 15/15 static inventory assertions, 27 hermetic pipeline-admission checks, and 10/10 standalone normalization checks PASS. These suites cover invariants but do not test successful provider-neutral runtime readback.
6. **Provider Gateway:** `capabilities().providers.google_drive` present; `capabilities().providers.wordpress_media` absent. Partial scan Apply rejects incomplete inventory as required; unknown attachments/invalid MIME and binary text rejected.
7. **Canonical source identity:** `MAD4B_SCP_Context_Authority::upsert_source` already maps all WordPress Media aliases to `site_media_library`. An initially proposed cosmetic route change was reverted on Staging and GitHub after confirming the canonical alias; **no duplicate source identity bug remains**.
8. **Source parity:** among 9 core files checked by Git blob hash, 5 match between PR #760 and active Staging; 4 differ: `class-mad4b-scp-context-admin-ui.php`, `class-mad4b-scp-google-drive-context.php`, `class-mad4b-scp-brand-context-builder.php`, `class-mad4b-scp-context-authority.php`. Do not replace the live runtime wholesale from this branch without three-way reconciliation.

## Findings, severity, owned remediation

### P0 — WordPress Media Context read cannot reach Brand generator

`includes/class-mad4b-scp-context-provider-gateway.php` only recognizes `google_drive` in `provider_from_source()`, `capabilities()`, and `read_context_asset()`. Brand Builder uses `MAD4B_SCP_Context_Provider_Gateway::read_context_asset()` for authority content. A successfully inventoried and human-approved WordPress attachment therefore cannot be used through this standard read path.

**Fix owner:** Provider Gateway + WordPress Media Context. Implement a **read-only** Media adapter with exact Site/Brand/source/asset identity, attachment MIME/path/readability, max-byte and registered **raw-file SHA256** verification; then normalize to text and return `mad4b.context-asset-read.v2`. Avoid treating the raw WordPress attachment hash as the Drive normalized-text hash. Do not add write/upload/deletion authority to this adapter. Add exact-hash drift and wrong-source negative tests.

### P1 — WordPress Media whole-inventory deadlock on one invalid file

`WordPress_Media_Context::inventory()` aborts on the first unreadable, unsupported or unnormalizable attachment, and the default form selects all six MIME types. The live XLSX fixture is unreadable, blocking other valid files when this scope includes XLSX.

**Fix owner:** WordPress Media scanner + Media admin page. Add per-file structured diagnostic/retry/repair queue and a resumable/segmented inventory with explicit completeness and scoped missing-file semantics. Permit separately complete MIME scopes to apply, but **never** call a partial scan complete and never infer deletion from it. Repair this one attachment via exact owner/ACL review; no global chmod.

### P1 — Provider and UI lifecycle asymmetry

Google Drive bounded scanning can make progress but currently requires repeated manual continuation via the standalone admin-post progress page. Media is one blocking request, with no cursor or in-page progress. Their Plan/Apply semantics differ.

**Fix owner:** Context Source Pipeline / Admin UI. Define one provider-neutral state contract: `REGISTERED → SCANNING → PARTIAL/ERROR/COMPLETE → REVIEW_PENDING → APPROVED → READY`, with per-provider progress, counts, last error, resume/failed-item retry, exact source fingerprint, and audit receipt. Keep human approval distinct from discovery. Make single-button starts fire an idempotent background job only when actor/source scopes can be preserved, rather than a blind loop in a browser.

### P1 — Broad Drive folder brand-boundary risk

Source label is `Brands` and selected recursively under the current Site Brand Context. There is not yet evidence that all nested documents belong to this one brand. Do **not** auto-apply or auto-approve mixed-brand folders. Confirm the company-specific subfolder, or enforce per-file owner classification/quarantine before any Brand Core synthesis.

### P1 — Four core live/source drifts

Staging and GitHub PR #760 differ at four critical source/scan files. The UI button's bounded scan change is pushed to the PR, but **GitHub source is not otherwise byte-identical to the active files**. Reconcile ownership and dependency graph before release; avoid replacing entire site-control-plane files from a stale checkout.

### P2 — Misleading Media `sync_ids()` semantics

`MAD4B_SCP_WordPress_Media_Context::sync_ids([id...])` validates 1–25 attachment IDs, but later calls complete inventory over the **MIME types** of those IDs; it can scan every matching site attachment. Keep this behavior explicit and rename it (e.g. `sync_mime_inventory_from_selected_ids`) or implement a separately scoped, non-destructive per-ID merge contract. Do not silently treat this as a single-attachment importer.

### P2 — Normalization dependency and format coverage

WordPress PDF and workbook normalizers call static helpers owned by `MAD4B_SCP_Google_Drive_Context`; local Media therefore depends on that class being loaded, despite being a local source. Extract the pure normalizers to a neutral module shared by both providers. Image/audio/video assets and DOCX are **not** in the current WordPress Brand Context document inventory; treat them as separate, explicit capabilities rather than pretending they were scanned.

### P2 — Failed-scan UX and recovery

The Media admin page currently redirects with a generic error code; the scanner does not identify the failed attachment ID in a structured UI receipt. Google Drive progress is visible only when users remain in the continuation flow. Provide actionable error code, attachment/source ID, retry and diagnostics links; never expose OAuth secrets or private file contents in redirects/logs.

### P2 — Removal/recycling operator experience

`remove_source()` removes the active Context source and indexed assets under site/brand checks, with audit, not the external files. This is operationally destructive to the registry review state. Offer a governed **archive → detach → restore** workflow with receipts where recoverability is required.

## Already fixed and verified in this work

- The two Drive UI entry points (`Start / continue scan` and `Continue source scan`) now send `resumable=1` to the existing bounded planner on Staging; PHP lint, handler registration and rendered HTML form/nonce checks passed. The change is tracked in PR #760.
- Reconfirmed root canonicalization for WordPress Media; reverted an unnecessary alias edit so no extra behavior change remains.

## Release acceptance matrix

Require negative/positive tests for both providers: source registration/alias identity; single and >1000 items; invalid MIME; inaccessible attachment; partial provider timeout; expired/tampered cursor; source/account/brand rotation; repeated/idempotent scan; added/modified/moved/deleted files; exact reviewed-content hash retention/revocation; source removal/restore; adapter read permission and wrong-site identity; progress UI Desktop/Mobile; no assets or user content auto-approved. Require source parity on all relevant files and passing CI before Production.

**Current closure:** Scan-start UX fixed on Staging and tracked in GitHub. Whole lifecycle parity is **not complete**. Media read adapter, per-file recovery and source reconciling remain separate work; no brand assets were applied or approved in this audit.

---

## Implementation closure and runtime validation — 2026-11-05

The previous "findings" above are preserved as the **pre-implementation diagnostic baseline**; this section records actual work and does not retroactively erase those findings.

### Implemented and verified on ETG Central Staging

1. **P0 Media provider readback closed:** new read-only Media \`mad4b.context-asset-read.v2\` adapter in the standard Provider Gateway. Exact Site/Brand/source/attachment ID, uploaded file path, MIME, version, raw SHA256, bounded bytes, and normalized text checks. Rejects unready assets and changed files. 8/8 isolated acceptance/negative tests passed. Write capabilities remain false.
2. **P1 failure isolation / progress / resume:** operator/site-bound short-lived scan checkpoints, one complete MIME scope per request, a MySQL lock and monotonic step, clear failure codes and affected attachment ID, retry of affected MIME only, and no deletion evidence from failed/partial scopes. Live tests: TXT scope processed 5 assets, CSV scope completed with registry total 33; XLSX initially reported inaccessible attachment #66375 without blocking successful other types. Scoped file group was corrected from root:root to root:www-data while retaining mode 0660; XLSX retry then completed, with **34 Media Assets indexed, zero automatically approved**. No global chmod.
3. **P1 Google Drive progress:** existing encrypted/bounded cursor planner is now auto-continued in the admin page one request at a time (visible tab, 1.2-second interval, 100-step automatic limit); pause and manual resumption; certified Apply still requires independent review/confirmation and never runs through the auto-continue script. Real live Google Drive folder \`Brands\` remains incomplete/unapplied; mixed-brand warning now visible in the source list.
4. **P2 exact attachment IDs:** \`sync_ids\` updates only explicitly supplied attachment IDs after bounded normalization; it no longer scans all attachments with matching MIME. Live readback for an already indexed TXT attachment: count 34 before and after and exactly 1 ID refreshed, no absence or automatic approval.
5. **P2 provider-neutral documents / DOCX:** WordPress Media no longer calls the Google Drive class for local PDF/XLSX extraction; a shared local PHP normalizer is loaded from the parent plugin before Media. DOCX textual content supported safely (no macros); synthetic DOCX and PDF/XLSX parity checks passed, including 5/5 standalone document tests. Images/audio/video/OCR remain unsupported as complete text in this Context source and must fail closed.
6. **P2 source lifecycle:** Source UI now provides Archive → Detach and read-only Restore for rescan instead of normal hard-delete. Bounded Site/Brand-scoped snapshot is stored before native audited removal. Restore re-registers source only; it **does not** revive old approvals or create content assets. 6/6 isolated tests passed; actual live source removal/restoration deliberately not invoked.
7. **Source drift:** eleven key plugin runtime files were compared by exact Git blob SHA and now match the PR branch **11/11**, including the latest Staging-only Context Authority/Google Drive/Builder implementation. A file SHA256 inventory is tracked as \`2026-11-05-source-runtime-parity.sha256\`.

### CI and verification

- Dedicated \`MAD4B Context Source Lifecycle — parity and contracts\` CI ran on PHP 8.3 and 8.5 and **succeeded** for exact SHA256 inventory, PHP syntax, Media Gateway, source archive/restore, and DOCX/PDF/XLSX contracts at commit \`e3ed9a4406f5c2161aa820d5fdb0870a9c8364b6\`.
- WordPress home page smoke test HTTP 200; Media admin form renders seven MIME types including DOCX, progress/nonce, and the Staging registry retains Google Drive + WordPress Media as two distinct sources.
- The separate Developer Runtime and ChatGPT Refresh Hotpath checks fail because the candidate plugin header/constant is \`0.4.0-rc.118\` while \`MAD4B-RUNTIME-BUILD.txt\` and \`README.md\` are still at \`0.4.0-rc.104\`; the Control Plane Package release identity check also reports this mismatch. **Do not rewrite independent build/release evidence just to pass CI**; reconcile it through the authorized release build identity process. The Release Verdict still requires exact-HEAD owner evidence.

### Still open / explicitly unsupported

- True image/audio/video content understanding/OCR/transcription has not been implemented: no certified provider route was confirmed for these media types. They are not to be silently classified as complete normalized text.
- The Context Authority limit of 1000 governed assets remains; automatic complete scans beyond that capacity need a versioned, paginated registry design and safe missing-file semantics rather than bypassing capacity.
- Google Drive \`Brands\` is potentially multi-brand; the warning does not prove that its files belong to the selected Site Brand. The folder remains unapproved until exact ownership review.
- Archive/restore was tested hermetically but not against a live destructive user source; the rollback and legacy review preservation behavior needs targeted QA on an isolated test source before Production.
- The two scanning engines now share UI-oriented progress contracts, but their underlying checkpoints are not a single identical backend state machine. The dedicated Google Drive continuing flow and WordPress MIME-scoped continuing flow should remain distinct until a common transactional migration is designed.
- Production rollout, PR merge, and owner release attestation were not performed.

**Operational verdict:** P0 readback and the verified P1/P2 scanning, retry, exact IDs, DOCX and source-only recycle paths are implemented on Staging and versioned in PR #760. This is **not yet a fully certified Production release**.
