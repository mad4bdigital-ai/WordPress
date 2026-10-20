# Google Drive Source Scan — bounded Staging acceptance

This note covers the 2026-10-20 ETG/ARE incident recorded in [Issue #446](https://github.com/mad4bdigital-ai/WordPress/issues/446). It does not grant Google Drive provider Write, authorize Brand asset review, or change the 10-second provider budget.

## Implemented here: typed partial evidence

- The Google Drive `mad4b_google_drive_scan_time_budget_exhausted` error during child-folder listing or file normalization now yields a **truncated, incomplete** `mad4b.google-drive-folder-scan.v2` result, with `scan_time_budget`, `complete=false`, and `partial_scan_requires_complete_rescan=true`.
- Other errors, especially `invalid_grant` / token refresh failure, remain independent errors and are **not** relabeled as budget timeouts.
- The planner may display the observed subset, but neither `context/source-scan-apply` nor the legacy owner-transfer proof may infer absence, deletion, approved membership, or complete source identity from it. An incomplete scan is not a publication or reconciliation receipt.
- No source registry write, Google content publication, token refresh bypass, provider promotion, or owner-review bypass was introduced by this treatment.

## Still required: resumable, generation-fenced inventory

The above correction makes failed reads actionable; it does **not** make a large Drive scan complete across multiple calls. Implement the following as a separate guarded feature before treating ARE's incomplete inventory as accepted:

1. **Bounded scan pages.** Introduce a dedicated read-only discovery/planning operation with a caller-visible bounded page identity, folder traversal queue and Google `nextPageToken`, a strict global MAX_SCAN_FILES/MAX_SCAN_FOLDERS limit, and per-request sub-10-second provider budget. Do not increase the current budget globally.
2. **Confidential checkpoint.** Store progress in a site/brand/source-scoped, authenticated sealed checkpoint with a short TTL, size cap, and one active generation. Never include OAuth tokens, raw credentials or unreviewed document bodies in cursor URLs, logs or approvals. Fail closed when cache evicts or the checkpoint cannot be decrypted.
3. **Generation fence.** Bind site UUID, enrolled profile revision/digest, registered source ID/root, recursive flag, Google account/grant fingerprint, registry revision and provider version/observed item versions. Reject a different source, OAuth identity, changed scope, expired generation, duplicate IDs, or concurrent conflicting resume. A cursor alone is not authorization.
4. **Exactly-once continuation.** Advance the checkpoint by compare-and-swap with an idempotency key and append-only diagnostic receipt. A timeout before persistence must be inspectable; do not retry a possibly committed write blindly. Page failures retain no proof of final completeness.
5. **Finalization.** Prove all folder queues and Google pagination tokens exhausted, no truncated normalization, no unknown content; reconcile duplicates and verify provider snapshot consistency with independent rechecks or explicit provider change-token fencing. If Drive cannot guarantee a stable view across pages, mark `scan_complete=false` and replan. Only a finalized immutable inventory digest can enter the existing exact plan/approval/CAS path.
6. **Separation of authorities.** Candidate-binding-only Staging Write reconciliation is independent of `context/source-scan-apply` provider certification. Certify artifact source, behavioral evidence, rollback and canary/ring before enabling provider Write. Google `refresh_required` is not a refresh failure; verify a governed token refresh and real provider read before claiming continuity.
7. **Brand acceptance.** ETG's four observed new files and ARE's eight new/one modified/eleven unchanged are *provisional* until their respective complete source scans. Verify source-folder membership per site and leave Tone of Voice, Editorial Guidelines, Brand Strategy, and any owner approvals unmodified pending explicit review.

## Regression evidence required before accepting resumable scans

- Partial timeout in folder-listing and in content-normalization yields `complete=false`, no missing/deletion decision, and no apply eligibility.
- Interrupted page, stale source/profile/OAuth/grant, CAS collision, duplicate file ID, path-scope confusion, cross-site replay, expired checkpoint, page token mismatch, cache loss, incomplete content normalization, and provider changes between pages **all fail closed**.
- Repeated identical page calls do not append the same asset twice or create duplicate approvals; no secret or unreviewed body appears in cursor or audit logs.
- A complete Staging fixture with 20+ files and multiple folders passes through several bounded requests, ends with exact readback and a separately valid owner approval, while the same test against mismatched source identity fails.
- ETG and ARE independently return a complete source digest, provider certification evidence and Brand owner-review state; no Production mutation or privileged Developer/Breakglass state is enabled.

Until these conditions pass against the live runtime, this feature remains **NO-GO for Source Scan Write**, regardless of successful Staging candidate binding, package/Skills status or a partial read's file count.
