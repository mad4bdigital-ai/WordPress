# IMP13 — Source Archival Fencing, Commercial Review and First-Run UX

10 October 2026 · Feature 007 · PR #366 source implementation · Draft only

## What was actually closed in code

**A. Archive-commit source fence:** A durable archive tombstone now makes normal `read_manifest` return `mad4b_batch_archival_in_progress`. Therefore source `verify`, `append`, `approve` and `export_chunk` cannot reuse any part of a batch whose archival intent already exists, even when a previous cleanup failed after audit persistence but before Manifest deletion. `archive_unlocked` uses the separate exact site/batch archival proof and can finish the bounded cleanup with the Profile mutex. This fixes a dangerous source-resurrection race. It does **not** fence an external WP All Import write or revoke a CSV download already completed.

**B. Commercial source review:** The main `MAD4B_SCP_Activity_Import_Review::inspect` now identifies PHP-serialized array/object-like source cells using an anchored signature `a|O|C:<decimal>:` and records **`serialized_relation_requires_certified_driver`** as a blocking row issue. The source remains stageable for *diagnostic review* but cannot gain exact-source approval until fixed/handled by an independently certified typed relation driver. The importer never calls PHP `unserialize()` on untrusted supplier data. This extends the earlier read-only Dynamic Mapping type warnings to the actual approval gate.

**C. Better operator labels:** The XLSX Mode had duplicate keys in the English label registry, causing its real upload label to be overwritten. The second entry was removed. Validator codes `identity_missing_or_duplicate`, `invalid_wordpress_post_status`, and `serialized_relation_requires_certified_driver` now have specific guided-review messages instead of mismatched or generic labels.

**D. New-site onboarding:** With **0 Content Experience Profiles** the wizard now shows a read-only bounded list of registered WordPress content types and administrator edit access, through the new IMP12 `MAD4B_SCP_Import_Schema_Onboarding::plan([])`. It instructs the operator to explicitly approve one target Profile, stable external ID, currency/tier/date validation, languages, Meta allowlist and source Modes before upload. It does not create any Profile, infer JetEngine CCT schemas or silently select a CPT.

## Practical acceptance simulations

| Test | Expected behavior | Code status |
|---|---|---|
| PHP worker crashes after archive tombstone but before all pages removed | Further verify/append/approve/download return `mad4b_batch_archival_in_progress`; only exact authenticated archived cleanup may resume | Guard and fixture committed; native Staging not run |
| Simulated 502 rows with two complete translation groups per chunk | Identity collisions across chunks block full-batch approval | Existing IMP08 fixture + IMP13 archival extension, runtime not run |
| Supplier currency `ERU` not in site allowlist | Block `currency_not_in_approved_allowlist`, do not guess `EUR` | Existing runtime + new combined fixture, PHP not run |
| Publication status `puplished` | Block `invalid_wordpress_post_status`, do not silently publish | Existing runtime + combined fixture, PHP not run |
| PHP-serialized `related_properties_id` source shape | Block `serialized_relation_requires_certified_driver`, no `unserialize` | IMP13 validator guard + negative fixture committed |
| After reviewer corrects values using approved business source | Review reruns current Profile validation, blocker count may reach zero; prior approved source is not rewritten | Isolated fixture committed, not run |
| First admin opens import page with no Profiles | Show read-only real post-type choices and guided owner approval path | New UI path and static test committed, browser not run |
| Existing legacy JSX import URL or unknown mode | Do not bypass governed per-Profile server-side source-mode authorization | Existing IMP07 safeguard |
| WordPress editor / WP All Import / MSR02 write simultaneously | **Do not claim** batch-local mutex serializes external writers | Shared write-fence certification NOT DELIVERED |
| Google Sheets multiple browsers update same row | **Do not claim** Apps Script locks are a global revision-CAS | Conditional cross-editor CAS NOT DELIVERED |

## Grounded All Royal Egypt commercial input

An inspected prior conversation CSV `Allroyal_Tour_Rates_WP_All_Import_SOURCE.csv` has 180 rows and 20 headers; 36 translation groups each have five language members `en/es/fr/it/de`. The `base_currency` column contains 108 `ERU` and 72 `USD` values, all 180 statuses are `puplished`, and date fields are Unix timestamp text values. These are raw-data observations, **not instructions to auto-correct or to import**. A five-language site Profile would be needed for that source, instead of a two-language test fixture. Source use rights, actual destination CPT and JetEngine CCT relation schema remain subject to site-owner and provider readback. Do not normalize `ERU` to `EUR`, `puplished` to `publish`, or PHP-serialized relation data automatically.

## Required independent proof to claim operational completion

1. Execute exact-HEAD `feature007-manual-preflight.py` with PHP 7.4/8.3 interpreters. This includes new `imp13-commercial-source-safety-runtime.php`, `imp13-archival-and-commercial-source-contract.py`, and earlier comprehensive code/fixture gates.
2. Deploy the signed, pinned plugin package onto authorized Staging; read back binary SHA, Control Plane version, origin and Site Profile UUID. Source changes on PR #366 are not the currently observed installed rc.96.
3. Confirm first-run UX on mobile/RTL/keyboard, field errors, stale nonces, role denials and no-Profile state.
4. Certify WP All Import **installed version**, import job configuration/CSV provenance and native post-write readback on disposable records; no source snapshot approval is a permit to run a provider job.
5. Independently certify JetEngine CPT/Meta vs CCT/relations, WPML language links/trid for every group and any localized status/date parsing requirements.
6. Implement and independently test cross-editor Google Sheets conditional CAS, a shared external writer fence and post-failure transactional compensation **before** enabling unattended writes.
7. Verify Brand Core authority/rights, owner approvals and independent Production promotion policies. No merge or Production mutations occurred.

**Status: source-level P0 fixes implemented; external writer/CAS/rollback and exact installed runtime certification remain openly blocked.**
