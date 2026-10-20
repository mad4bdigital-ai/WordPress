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

## Implemented follow-up: opt-in bounded continuation

`context/source-scan-plan` now accepts `resumable=true` to start an enrolled
Staging inventory. Pass the returned `continuation.scan_cursor` to the same
operation for each subsequent step. One request lists at most 25 children or
normalizes one file. The original 10-second provider budget remains unchanged.
Plain single-request scans remain supported.

Progress uses one non-autoloaded AES-256-GCM encrypted checkpoint per exact
site/brand/actor/source/credential/registry identity, with a 900-second TTL,
8 MiB plaintext cap, 500 files, 120 folders and depth eight. Provider pagination
and document bodies never appear in the cursor or audit. Signed, revision-bound
cursors, an existing connection-owned advisory mutex, ownership readback,
append-only diagnostic intent and exact SQL compare-and-swap prevent concurrent
append or silent overwrite. Replaying the previous page returns its checkpoint
without re-normalizing files. Checkpoint expiry, corruption or loss fails closed.
`restart_scan=true` requires the exact current cursor and replaces diagnostic
progress with a new generation; it cannot clear assets or owner approvals.

The existing Google Changes API fences the account or selected shared drive from
the initial change token. Any reported change, pending changes page or unverified
response denies final completeness. This is conservative: even unrelated changes
in the same change feed require a fresh inventory. The feed and exact scope are
rechecked on completed readback and before Apply. Unsupported or incomplete
normalization, shortcuts needing separate scope review, credential refresh,
registry/profile/actor drift and bounds require explicit remediation/restart;
they are never silently skipped or certified as absent.

A complete result supplies a stable plan and inventory digest. Include its exact
`scan_cursor` alongside the existing plan SHA, inventory SHA and registry revision
when calling `context/source-scan-apply`. Apply reads the sealed final result,
rechecks Drive changes and identity, then retains all original provider Write,
approval and registry gates. Registry revision is checked again inside the
original Context registry lock. Diagnostic pages do not ingest provisional assets.

The native Context Authority Sources tab exposes **Scan in bounded steps** under
the original administrator POST/nonce handler. Each step uses the same planner;
a complete result requires separate checked confirmation and exact-plan Apply.
The existing one-request administrator scan now uses the same completeness gate.

Executable hermetic regressions cover 25 files in multiple folders/pages,
exact-once normalization, ciphertext/tokens, forged/stale/cross-scope cursors,
provider changes, normalization failure, timeout, audit failure, lost lock, CAS
failure, cache loss, expiry, explicit restart, exact final planner/Apply parity
and unconfirmed/forged native Apply refusal. These establish source behavior,
not Google OAuth integration or ETG/ARE acceptance. The earlier "Still required"
section remains the live acceptance checklist; source implementation alone does
not supply provider certification, Brand review or a live complete inventory.
