# IMP08 / IMP09 — Encrypted Multi-Part Review and Live Provider Acceptance Evidence
**Feature 007 / PR #366 · 10 October 2026 · Staging-only review actions, no automatic import**

## IMP08: workable multi-part reviewed source, not a background worker

The existing commercial review accepts at most **500 rows per immutable encrypted source**. IMP08 introduces a separately isolated, exact-Profile-scoped **batch inbox**: one active batch per Profile, 2–10 chunks, 1–500 rows and ≤1 MiB encoded source JSON per chunk, at most 5,000 rows. Chunks are individually AES-256-GCM encrypted using a host-managed key derived for the enrolled Staging site/Profile and bound via associated authenticated data (AAD) to batch ID, chunk index, profile policy digest and plaintext SHA-256.

**Why:** lifting MAX_ROWS to 10,000 would increase review memory, approval size and duplicate-identity risks and invalidate existing guarantees. Chunked source staging retains bounded local validation and allows independent readback of every page.

### Execution sequence (no provider writes)
1. Use `mad4b/business-activity-import-acceptance-gates` to review live host keys and provider prerequisites.
2. `mad4b/business-activity-import-batch-begin` with `profile_slug`, `expected_chunks=2..10` and `confirmed=true`. Creates an exclusive per-Profile active slot and durable nonautoloaded manifest.
3. `mad4b/business-activity-import-batch-append` with exact `batch_id`, `chunk_index` and `source:{profile_slug,headers,rows}` for each part. Runs existing site-owned field mapping, money, interval, WPML/group and identity validations, then writes a single immutable encrypted chunk with independent readback. No overwrite of duplicate chunk indexes. Split a WPML translation group **only on complete group boundaries**.
4. `mad4b/business-activity-import-batch-verify` re-decrypts/revalidates every chunk and confirms its source, policy and plan digests. It refuses to mark the batch ready with missing chunks, blocking issues, duplicate identities **across chunks**, or split WPML groups. Aggregate warning count and independent full manifest SHA are returned without source row values.
5. `mad4b/business-activity-import-batch-approve` requires `confirmed=true`, exact `plan_sha256` and **all** nonblocking warning count. Stores a separate immutable manual-export-only approval; no WP post edits or importer execution.
6. In the WordPress guided import wizard's handoff step, pass the exact `batch_id` in the URL while the Profile is selected. An approved batch displays independently nonce-protected `Download approved part N` actions. Every part revalidates the **entire batch** and its approval before encoding a bounded CSV in `php://temp`; no network response headers are sent until validation completes.
7. `mad4b/business-activity-import-batch-archive` requires explicit confirmation and persists a redacted immutable audit before deleting encrypted chunks, approval and manifest. The active Profile slot is released only if every deletion has been independently read back. An interrupted archive with an **identical, authenticated audit tombstone** can retry cleanup; if its Manifest was already deleted, a separately checked exact batch-ID/count tombstone allows bounded recovery of only that specific active batch. A mismatched tombstone or failed cleanup keeps the Profile locked. Different-source tombstones cannot release the slot. This is a fail-closed *source inbox* recovery mechanism, not transaction rollback for external WordPress/provider writes.

**Important limits:** No unattended queue worker is registered. Individual append operations must be initiated by the administrator or authorized MCP client. There is no distributed DB transaction spanning independent WordPress options; if any intermediate write or cleanup fails, the coordinator refuses further release. A host-verified cleanup/recovery mechanism is needed for stuck manifests, and concurrent archiving/appending has not yet passed MySQL stress certification. This approach does not imply direct support for 100,000-row streaming uploads.

### Cross-chunk refusal

- No two independent source rows may have the same exact source identity across any staged chunk. WPML group ID is *not* the per-translation unique ID.
- Translation groups must be wholly represented inside one chunk under complete-group policy; this avoids breaking language links when an external importer processes separate CSV files.
- Provider system metadata and source identity are not silently inferred from a row index or WordPress `post_id`.
- The global approval receipt is bound to all chunks, not to a manifest alone; changing any chunk makes its `plan_sha256` stale and prevents exporting *any* part.
- Never silently publish, delete WordPress records, perform an external WP All Import job, or retry a provider write from the batch reviewer.

**Simulation fixture** `tests/imp08-batch-review-runtime.php` builds **502 rows** (500 + 2) across two complete language-group chunks and exercises incomplete manifest refusal, duplicate-index refusal, exact approval, archival, replay prevention and cross-chunk ID collision. Fixture is source-committed but **not executed on live Staging yet**.

## IMP09: evidence-producing provider readiness without false certification

New read-only `mad4b/business-activity-import-acceptance-gates` accepts `profile_slug` and returns bounded booleans for: enrolled site and Staging runtime, enabled Profile and import validation, explicit source Mode allowlist, AES-GCM host key, vetted XLSX runtime, WP All Import/WPML/JetEngine detection and bounded batch review prerequisites. It also reads Brand Core's missing-context count when the live Context Authority provides it, without displaying Brand Core documents or assuming review acceptance.

**A plugin being loaded ≠ compatible version/certified driver.** Presence is recorded separately from the following required live evidence:
- Exact GitHub HEAD ↔ installed Staging build fingerprint
- PHP 7.4 and 8.3 native lint/fixtures, installed WordPress version and PHP compatibility
- Mobile/RTL/keyboard browser acceptance, CSRF/nonce rejections and error recovery
- An actual WP All Import job template/version/import ID, the exact CSV contents selected by the wizard, and post-write source-to-destination readback for every chunk
- WPML live TRID/language parity, JetEngine CPT/CCT and relations with post meta, serialized values and native field types
- Google Sheets across-editor conditional-write revision semantics (advisory Apps Script LockService alone does not prove a global CAS)
- **Shared certified write fence** between WP All Import and MSR02, with independent pre-image, post-image and compensating rollback for partial failures
- Fully reviewed Brand Core coverage/ownership, site-specific authorizations and separate owner approval for Production promotion

**Readiness decisions:** Neither the staging presence report nor the encrypted batch approval can set `ready_for_automatic_import=true`. Production remains blocked until each separate evidence category is validated against an exact release artifact.

## Acceptance grid

| Failure / scenario | Implemented in source | Native/live evidence |
|---|---|---|
| 502 source records, 500+2 chunks | Encrypted intake + aggregate readback | PHP fixture NOT_RUN |
| Tampered chunk or changed Profile | AAD/SHA, revalidate each part | PHP / MySQL stress NOT_RUN |
| Duplicate source identity across chunks | Aggregate collision refusal | PHP fixture NOT_RUN |
| WPML group split across chunks | Explicit split detector + group-in-chunk completeness | WPML post-link verification NOT_RUN |
| Stale approval or warning acknowledgement | Bound exact plan / warning count | PHP fixture NOT_RUN |
| Import active while MSR02 sync writes | Third-party writer certification expressly false | External transactional write fence NOT_DELIVERED |
| Crash during archival cleanup | Durable audit, independent deletion readback and bounded retry for matching tombstone | Fault-injection stress NOT_RUN |
| WP All Import finished hook | Observer reports *unverified* | Actual job/source/WordPress readback NOT_RUN |
| JetEngine CCT and relation mapping | Independent source validation / no generic CCT writer | Provider-specific native readback NOT_DELIVERED |
| Google Sheets concurrent editors | Source contract recognizes revision risk | Conditional CAS driver NOT_DELIVERED |
| Staging readiness | New live read-only checklist ability | Connection currently fails; NOT_RUN |
| Production | No promotion authority granted | BLOCKED |

## Operational handoff

1. Run `wp-content/plugins/mad4b-site-control-plane/tests/feature007-manual-preflight.py` on a checked-out, clean repo with exact HEAD and PHP runtimes, recording each fixture result and a negative-test matrix.
2. Verify the target staging origin/site UUID and secure Host key flags, installed library/plugin versions and Content Experience Profile. The previous connected All Royal Egypt read calls returned internal errors, so **there is no verified live readiness result**.
3. Upload the exact certified plugin package to approved Staging only; run a real CSV+XLSX import review, 502-row batch and WP All Import manual job to a disposable staging CPT with a representative WPML/JetEngine schema.
4. Verify all records and relations using independent native providers and restore the Staging database snapshot after fault injection.
5. Only with complete evidence and owner authorization move towards an independent Production release process. **Do not merge #366 into #258 or #258 into master as a substitute for acceptance.**

**Verdict:** IMP08/IMP09 provide an operable, bounded reviewed-source path and a readiness diagnostic surface. They are *not* a universal, automatic, transaction-safe multi-provider importer; third-party write fencing, Google Sheets CAS and certified rollback remain distinct future development gates.
