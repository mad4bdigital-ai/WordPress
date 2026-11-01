# Staging rollout, admission scope, and rollback

## Verified now

- Central Staging original Content Hub on `tours.dev.mad4b.com` stays
  unchanged and its 322 tasks remain untouched.
- The code candidate passed original WordPress Queue E2E and actual
  concurrent WP-CLI processes on a separate same-VPS QA DB.
- Only original `workflow_inspect` and `content_job_read` readback
  operations are independently certified for `certified_only` mode.
- Other operations retain their original Queue behavior in that mode;
  do not claim full deduplication or full GO.

## Exact approved site deployment procedure (not yet applied)

1. Verify original Staging host and database; assert no Production target.
2. Freeze new enqueue ingress and verify executor drain/lease state. Take
   an exact-source file rollback and database backup via the ORIGINAL
   site deployment authority; record SHA256 fingerprints.
3. Confirm authorized release matches the reviewed Git HEAD and the
   release verdict/owner approval. Do not promote a test archive manually
   in place of the authorized release channel.
4. Update the original Hub source on Staging with feature flag disabled,
   and prove CLI and PHP-FPM both run that exact source.
5. Install 3 additive InnoDB tables using the original admin permission.
   Repeat live 20-row or current nonterminal read-only snapshot; compare
   count and digest. Atomically apply and lock current legacy allowlist.
6. Enable `certified_only` only after independent original permission,
   release and rollback checks; verify enabled mode via CLI AND PHP-FPM.
   Resume new ingress in this limited mode and monitor original Queue,
   leader/follower, receipt and lease invariants.
7. Only separately certified provider, AI, publishing, pricing,
   integration and unlinked lanes can later move into full `enforce`.

## Rollback

Disable feature mode and restore the exact prior Hub source via the
original release rollback. Preserve additive tables and verified
receipts for audit; never delete durable follower or finish history.
Do not cancel historical tasks or mint an executor grant as a shortcut.

**NO-GO** for full `enforce` or live release without exact authorized
source, backup, independent operator acceptance and exact-head approval.
