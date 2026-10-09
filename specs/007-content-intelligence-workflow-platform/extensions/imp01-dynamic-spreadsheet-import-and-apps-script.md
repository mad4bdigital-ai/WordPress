# IMP01 — Dynamic Spreadsheet Intake, WP All Import and Google Apps Script

**10 October 2026 · Feature 007 · PR #366 · draft / Staging-only.**

## Goal and existing contracts
One Content Experience Profile / Business Activity Contract supplies the site-specific schema, identity, field allowlists, roles, source priority, review requirements and destination policy. IMP01 is a reusable import facet; it must not embed tour, driver, DMC or All Royal business logic. Reuse MSR01 for provenance/conflict analysis and MSR02 for authenticated provider effects once an adapter earns conditional-write certification. Importing commercial prices is a high-impact action.

### Transport/source matrix
| Source | Intake | Update semantics | Release gate |
|---|---|---|---|
| Uploaded .xlsx/.xls/.csv/.tsv | Parse in a controlled converter; select sheet/table; normalize typed rows; hash exact file | Preview + explicit approved mapping | Only a validated transformed CSV handed to a preconfigured WP All Import job; no hidden import API |
| Google Sheets via managed Google app | Explicit spreadsheetId, sheetId, range, revision/digest + source owner | Read-only diff initially; no claimed atomic CAS | A value-hash preflight/Apps Script lock is NOT sufficient evidence of a Google Sheets atomic CAS across editors |
| Apps Script bound to native Sheet | Script Property HMAC secret, exact HTTPS site REST URL, 5-minute timestamp and nonce; bounded raw JSON payload | Signed inbound **review inbox** only | Site enrollment, profile scope, origin grant, standalone secret provisioning, Staging acceptance |
| WP All Import Pro | Existing import/template with explicit ID and plugin version | Native create/match/update/delete as configured in plugin | Admin-defined template, unique key, explicit field update policy, staging dry run and post-import readback |
| Existing site records | WordPress post/meta/taxonomy/relationship reads | Independently compare scoped identity and values | No silent title-based match or auto-create on missing relation |

### Required configurable option families (not a claim that all are implemented as remote calls)
- **Source & structure:** xlsx/csv/tsv/xml/Google Sheet, multiple tabs, header line, encoding, delimiter, empty-row filter, formula treatment, schemas, locale/date/timezone, serialized-value policy, attachment limits.
- **Identity & match:** source-owned immutable key, composite stable ID, import job identity, match existing vs create, case-sensitive IDs, duplicate strategies, change hashes.
- **Destination:** post type, taxonomy, post status, author, post parent, custom/JetEngine/ACF/meta field mappings, language & WPML group, taxonomy terms, relationships, media, gallery.
- **Commercial data:** typed currency allowlist (never typo-autocorrect), price tiers, inventory, tax/fee rules, date windows, season overlap detection, commercial approval, rollback.
- **Update policy:** create/update/skip, selected-field updates only, merge strategy, revision comparison, null vs missing behavior, partial/atomic group policy, no implicit delete.
- **Operations:** manual/on-change/schedule, incremental window, quota limits, concurrency/locks, dry run, staged-only canary, chunk size, backpressure, retries, monitoring, signed receipts, redacted error log.
- **Governance:** source owner, profile revision, exact plan digest, role, field-level review, rights/IP, rollback/restore, runtime evidence, Staging/Production boundary. All unrecognized plugin options fail closed until discovery.

### Four checkpoints
1. **Inspect:** classify source and normalize read-only with exact source digest; show schema diff, unmapped columns, duplicate keys, missing references, WPML grouping, price issues, risk.
2. **Review:** brand authority and commercial rules reviewed separately; conflicts assigned to user/team; no source wins because its file is newer.
3. **Handoff:** publish an approved CSV to a preconfigured WP All Import import on an authorized Staging host or use a future certified direct provider adapter. Manual upload and wizard configuration remain supported; a repository source file is not proof of an installed WP All Import job.
4. **Verify:** compare WordPress post IDs, language groups, row count, field hashes, import logs and independent readback. Reconcile failures with MSR02; never infer success from HTTP 200 or a queued background task.

### Current code delivered
- `MAD4B_SCP_Activity_Import_Review::capabilities`, `plan`, `review`, `brand_core_plan` are bounded read abilities. Live property allowlists come from the exact Content Experience Profile.
- Signed Apps Script REST intake `POST /wp-json/mad4b/v1/activity-import/intake`: HMAC-SHA256 over **exact raw JSON bytes**, site-bound UUID, timestamp freshness, unique nonce, 1 MiB limit, 500 rows, 80 columns. Stores only a redacted conflict/plan snapshot as a nonautoloaded option. Never writes WordPress posts.
- WordPress Tools → MAD4B Import Review shows redacted conflicts. A new intake cannot replace an unreviewed one. Setup secret is site-scoped constant `MAD4B_ACTIVITY_IMPORT_WEBHOOK_SECRET` and a separate Apps Script Script Property. Never send secrets via chat, PR comments or GitHub.
- `tools/feature007/google-apps-script/mad4b-activity-import-push.gs` is a sample client for **native Google Sheets**, not XLSX files. It uses Script Properties, explicit tab ID/name, guarded formula treatment, LockService for *script-worker serialization*, and sends no direct content mutation to the WordPress runtime.
- Existing `MAD4B_SCP_Context_Authority::brand_core_coverage()` and `review_queue()` remain the authoritative acceptance gates for `brand_strategy`, `tone_of_voice`, `editorial_guidelines`; IMP01 does not fabricate approval.

### All Royal sample (not a global default)
Attached workbook has two tabs: Sheet3 (180 records plus 35 blank/separator rows) and Sheet5 (180 exact records). Five languages each per translation group; 36 groups. WPML fields: `_wpml_import_language_code`, `_wpml_import_source_language_code`, `_wpml_import_translation_group`.
- 108 `ERU` currency values: BLOCK / explicitly confirm approved ISO currency; do not replace by EUR silently.
- 180 `puplished` status values: BLOCK / map to a configured WP status with human confirmation.
- 12 tier-price ordering exceptions: REVIEW rather than auto-correct; commercial tier rules may differ.
- 90 rows date window 2026-05-01..2026-09-30 (already ended on 2026-10-10), 90 rows 2026-10-01..2027-04-30. Historical records must not be blindly deleted or published.
- Serialized `related_properties_id` is not executable code; validate structure and referenced IDs on staging, refuse PHP object deserialization. `ID` identifies a file record, NOT necessarily an existing WordPress post ID.
- Actual post type, WP All Import job ID, JetEngine meta keys and translation wiring must be discovered on enrolled Staging; no hardcoded All Royal website identifiers.

### WP All Import compatibility boundaries
WP All Import documents importing XLSX/CSV/XML/Google Sheets, matching existing records, custom fields and scheduling with separate trigger and processing URLs. It does **not** provide a stable generic endpoint to configure every wizard option automatically. Use the installed plugin UI and official `pmxi_before_xml_import`, `pmxi_saved_post`, `pmxi_after_xml_import` hooks if instrumentation is deployed; never spoof import-complete certificates or transmit cron secret keys in logs. Unique Identifier is **import-job scoped** and must not be confused with `post_id`.
For WPML, use the current WPML Export and Import workflow: map 3 WPML metadata columns through import plugin then run explicit WPML translation linking on Staging and verify pairs. Legacy WPML All Import workflow should not be implicitly assumed.

### Required native and live tests (open)
- PHP 7.4 & 8.3 lint + isolated fixtures, WordPress 7.x REST signed acceptance, HMAC/tamper/replay/expiry, object-cache and nonce cleanup.
- 500 valid vs 501 rows; duplicate column, duplicate ID, multi-language duplicate group, currency/status exceptions, formula/nested payload, invalid profile, wrong origin/site and over-limit refusal.
- Staging readback: upload CSV via WP All Import with create/update/skip policies; WPML language linking, custom Meta and JetEngine serialized fields, rollback/restore and negative cases.
- Apps Script Script Properties & scopes, locked concurrent writers, changed source during read, tampered signature, no privileged drive credential leakage.
- Brand Core governed source/manual review with exact hashes and conflicting sources; never self-approve.
- No Production promotion without separate explicit authority and independent signoff.

### References
- https://www.wpallimport.com/documentation/importing-an-xml-or-csv-file/
- https://www.wpallimport.com/documentation/define-unique-identifier-correctly/
- https://www.wpallimport.com/documentation/action-reference/
- https://www.wpallimport.com/documentation/cron/
- https://wpml.org/documentation/translating-your-contents/multilingual-content-import/
- https://developers.google.com/apps-script/reference/spreadsheet/spreadsheet-app
