# MAD4B — Staging Operation Terminal Reconciliation (source-only)
Date: 2026-10-17
Base: PR #399, exact head `80e1bd4b60407ff286aba319bc42e3fbe55bb9f1`.

## Problems and resolution

1. **False VERIFIED with a retained site lock**. Terminal commit now requires the HMAC-signed operation row to pass through `FINALIZING`, release the site lock and verify its absence **before** publishing `VERIFIED`. A failed delete yields `FINALIZATION_LOCK_HELD`, and a failed final state update yields `FINALIZATION_INCOMPLETE`. Both deny new Apply requests and never report a completed operation.
2. **Old VERIFIED row while a new operation owns the active lock**. Historic `VERIFIED` is kept as historic success when the latest index points to the new operation, while a `VERIFIED` latest entry with an unexpected retained lock is reported as `VERIFIED_LOCK_HELD`.
3. **Owner Receipt consumed before reservation rejected**. Read-only `validate_claimable` performs the exact signature, expiry, owner, site and plan checks without creating a claim. It precedes site-wide atomic reservation; the existing one-time `claim()` is invoked only *after successful reservation* and before any governed write. A failure after reservation still retains a fail-closed recovery lock and must not be blindly repeated.
4. **Initial vs post-write plan confusion**. The existing immutable `operation_id` continues to bind to the owner-approved initial plan. Completion Audit records both `owner_approved_initial_plan_sha256` and `verified_post_execution_plan_sha256`. The HMAC-signed journal also retains `post_execution_plan_sha256`. The post-execution plan is not substituted retroactively for the approved CAS.
5. **Unresolved states rendered as a new owner approval**. The owner's read-only OAuth diagnosis prioritizes independent status and Audit readback for `FINALIZATION_LOCK_HELD`, `FINALIZATION_INCOMPLETE`, `VERIFIED_LOCK_HELD`, missing/contradictory locks and interrupted execution.

## Staging-only acceptance plan
- The existing Feature 007 Pre-Staging Hybrid Audit runs owner/journal PHP fixtures on both PHP 7.4 and PHP 8.3.
- New fault injections simulate failed `delete_option`, failed final `update_option`, old verified state with retained lock, and reservation without a current index.
- Existing real disposable WordPress+MySQL 8 and WordPress+MariaDB 11.8 matrix now adds five **parallel** WP-CLI requests competing to reserve either the exact same plan or five different plans for the same site. It requires exactly one admission and an independently verifiable signed active record. The test changes only disposable CI WordPress data.
- G1–G9/UI manifest proof must report `drift_count=0`. The modified workflow's exact G8 fingerprint is updated.
- No Staging mutation, owner approval, package installation, Production change, Developer or Breakglass.

## Remaining explicit release barriers
- **Not an atomic distributed exactly-once transaction.** A Staging timeout can occur after any external side effect, and a retained lock is *evidence requiring recovery*, not proof that an operation failed.
- Recovery unlock for an uncertain or finalizing operation must be a **separately owner-authorized, exact-evidence** process. Do not delete a lock with a cron job, TTL, SQL workaround or on a guessed outcome. A terminal incomplete state cannot be made complete by simply editing the signed option.
- CI result for the **new exact head** and real Staging acceptance on both ETG and All Royal Egypt are required. The disposable CI database is not either site's production- or staging-hosted MariaDB.
- The diagnostic canonical scope hash does not replace the full mutable plan hash and does not prove real Admin/OAuth parity.
- Provider-native rollback is still only available for previously certified bounded actions; this change does not make arbitrary provider settings reversible.
- Operation journal signatures are site-secret bound; owner-governed key rotation/recovery remains required and is not bypassed by this patch.
- The owner may approve a final lock-reconciliation/rollback flow after independent postcondition evidence. No destructive automatic unlock was introduced.

## Release decision
Keep this as a **Draft stacked PR** until exact CI passes, owner attests the head and each Staging site passes its own OAuth/Write/rollback/browser acceptance. Completion states should be presented as `VERIFYING`, `RECOVERY_REQUIRED` or `VERIFIED`, based on authoritative readback rather than URL reload or old Audit count.
