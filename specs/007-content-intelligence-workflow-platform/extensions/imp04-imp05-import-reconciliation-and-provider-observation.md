# IMP04 / IMP05 — Stable Import Identity, Full Conflict Pagination and WP All Import Observation

**MAD4B Feature 007 • 10 October 2026 • PR #366 • Draft.**

## Why IMP04 exists

Imported spreadsheet `ID` may be only a supplier/file record number. It is never a WordPress post ID unless a separate, explicit authority independently proves that equivalence. WordPress SQL collations may compare Meta values case-insensitively, so even a single `meta_query` hit requires an exact readback of the destination identity value. A translation group is NOT a unique per-language imported-record ID.

IMP04 `Content Experience Profile.import_contract.validation.destination_identity_meta_key` defines the **canonical, site-allowlisted WordPress Meta key** holding immutable external source identity. Absent this setting, all native Meta reconciliation is blocked, not guessed. No silent matching by title, row offset, WPML translation group or unverified numeric `post_id`.

### Read-only native reconciliation
`mad4b/business-activity-import-reconciliation-plan` accepts:
```json
{"profile_slug":"approved_profile","snapshot_sha256":"64_lowercase_hex_sha256","start_index":0,"page_size":25}
```
- Load enrolled Staging encrypted source snapshot by exact hash; rerun validation and require original plan/profile revision/authority digest unchanged.
- Read canonical meta identity for up to **25 rows per page** with bounded `get_posts`, scoped to the registered WP post type and exact allowlisted source-identity Meta key. A CPT is supported; JetEngine CCT and custom relational database tables require explicit provider drivers.
- Classify `missing`, `ambiguous` (multiple candidates), `unverified` (unresolved/case-collated ID), `different`, `matched`. Only compare explicitly approved mapped scalar Meta fields and display redacted field name/reason and expected/actual value SHA-256, never bulk full data.
- Exclude `_wpml_` control Meta from ordinary WordPress field equality; a proper WPML language-link readback adapter remains required.
- Result is `read_only`, `ready_for_automatic_import=false`; equality of mapped fields does **not** prove the configured WP All Import job consumed the approved source. Imported-post provenance and lock/CAS cannot be declared certified.

### Full source-issue pagination
`mad4b/business-activity-import-issues-page`: read **200 issues per page** from the encrypted source, recalculating against the current site policy and exact plan SHA before rendering. The WordPress Tools screen offers next-page links for source conflicts and for independent destination comparison. It does not erase the source issues after row 200 or silently approve a partly reviewed record.

### Adversarial tests
- Two WordPress posts with the same external Meta ID must yield `ambiguous`.
- A collated or different-cased Meta match must yield `unverified`.
- Without explicit destination identity Meta, refuse all matching.
- Exact match only after reading back the external ID and each approved mapped scalar field.
- Stale profile or snapshot hash, out-of-range cursor, wrong post type, non-scalar complex Meta, and 26-row read attempt must fail closed.

## IMP05: WP All Import hook observer

The optional `MAD4B_SCP_Activity_WPAI_Observer` hooks only the documented public extension points:
- `pmxi_before_xml_import(import_id)`
- `pmxi_saved_post(post_id, xml_node, is_update)`
- `pmxi_after_xml_import(import_id, import)`

The source of truth for these hook signatures is the [WP All Import official Action Reference](https://www.wpallimport.com/documentation/action-reference/).

The plugin receives **no command to start an import**, no secret trigger/processing Cron URL and no raw supplier pricing via this observer. A Staging administrator may arm a bounded **observation** for an already-approved immutable snapshot and exact configured WP All Import job ID.

`mad4b/business-activity-wpai-observation-plan` proposes an exact bound observation; `mad4b/business-activity-wpai-observation-arm` requires `confirmed=true`, plan SHA and a site-enabled plugin Mode; `mad4b/business-activity-wpai-observation-status` reads the nonautoloaded option journal. Multiple arm attempts for the same import ID are refused. The journal records begin/end timestamps and best-effort `pmxi_saved_post` event counts but **does not certify the source file, WPML linkages or DB transaction success**. Concurrency can cause observer counter loss; this must never be used as a data integrity certificate.

### Why WP All Import automation remains a separate gate
The official WP All Import UI, existing import templates, WP-CLI and host scheduling support unattended execution, but job creation, version-specific field mapping and source-file immutability are not proven by the observer or by exposing a generic set of option names. A bound manual-approved CSV must be selected in the plugin's native import wizard, job ID and version confirmed, import launched by an authorized operator, and **then** an independent source-to-destination comparison across every page must pass.

Native WordPress `update_post_meta($prev_value)` is **not** a full row/transactional CAS/lock across WP All Import and MSR02. Do not infer a safe multi-provider write fence from Meta previous-value checks or post-update hooks.

### Rollback and recovery gates
- Before launch: only operator confirmation and source fingerprint binding, with no post effects.
- External plugin import started: observer logs `external_import_running_unverified` and cannot cancel, roll back or fence WP All Import on its own.
- External plugin import hook finished: `external_import_end_observed_unverified`, no promoted success.
- Post-import: read each source identity page, compare approved fields and independently verify WPML grouping, real JetEngine/ACF relations, changed post status and financial content. If any mismatch, hold as `needs_reconcile`.
- Recovery of partial effects requires a **certified destination driver**, revision fence, pre-image capture/restore policy, idempotent retry and independent post-restore readback. None is shipped as a silent fallback.
- Explicitly **no** Production promotion, no PR #366→#258 merge, no #258→master merge and no automatic WP All Import dispatch in this work.

## Outstanding blockers / certification

| Capability | Source status | Evidence needed |
|---|---|---|
| Site-owned validation, encrypted review, per-source keys | Implemented | Native PHP + Staging host secret + HMAC accept/reject |
| Complete conflict pagination and exact source identity CPT comparison | Implemented read-only | Staging CPT Meta tests, duplicate/collation/latency cases |
| WP All Import public hook observation | Implemented observational only | Installed plugin version, exact import ID, real hook callbacks |
| Post-import WPML / JetEngine CCT/relations full readback | Uncertified | Provider-specific verified driver & live data |
| 1000+ rows, streaming XLSX, multi-sheet batches | Not delivered | Encrypted file/blob store, bounded chunk queue, durable page cursor |
| Native Google Sheets cross-editor CAS | Not delivered | Provider-supported revision/fencing mechanism; Apps Script LockService alone insufficient |
| Unified importer/MSR02 write lock and rollback | Not delivered | MySQL serializable journal or equivalent certified transactional driver |
| Brand Core accepted and approved | Not attested | Live Content Authority required sources, reviewer and readback |
| Production deployment | Not authorized | Staging certificate, owner release and independent promotion gate |

## Required test evidence before declaring Done
Run the repository's `feature007-manual-preflight.py` with real PHP 7.4 and 8.3, its isolated IMP01/IMP04/IMP05 tests, independent WordPress Staging browser acceptance, installed-version WP All Import + WPML import readback, Google Apps Script signature/expired key/nonce tests, and actual concurrent writers/DB loss + restore simulations. Queued CI is NOT passing native tests.

**Decision:** IMP04/IMP05 provide safer read/reconciliation and observable provider lifecycle, but they intentionally leave commercial automated import blocked until execution adapters, sources and host certifications are real.
