# Host v121 — original First Anchor complete Root workflow (Staging)

## Scope

All Royal Egypt / Staging / hosting-2, operation
`company.host-authority.anchor.issue`, first Host Anchor Revision 1.
The generic provider execution gate remains disabled. Never reuse an
expired Original305 request or an Original306 Claim.

## Source paths

- `first_anchor_coordinator.py` — purpose-bound Root-only coordinator
- `patch_original306_first_anchor_runner.py` — exact source / SHA-controlled
  integration candidate for existing Original306 worker

## Acceptance phases

1. A **fresh WordPress authenticated owner approval** creates Original305
   Request + original306 QUEUED Job with the immutable Root plan reference.
   This Root runner never creates or impersonates the Owner Request.
2. Original306 worker verifies Original305+328+plan via pinned v95; v118 stores
   genuine QUEUED before the Claim, v120 independently signs the Broker Row
   against the original enqueued bytes, and the existing Broker signer signs
   Original306 Claim. Each step fails closed.
3. Immediately after signed Claim, only a matching v118+v120 witness may call
   v121. The independent v119/v86 claim verifier, fresh Original328, pristine
   Original318 Revision 0 and 30-second claim freshness must all pass.
4. Original313 v105 signs/persists Owner Enrollment using the independent
   exact Root key. Original312 v100 signs the Anchor, Ledger and Broker
   bootstrap using its enrolled original Broker key and fresh WordPress
   snapshot. Source-backed SSH principal `target_ssh_principal` is checked
   against the ledger's `ssh_principal`; this never creates a second SSH
   identity or writes to the original WordPress Job.
5. Exact signed docs and Original313 approval are stored immutably before
   original313 v99 reserves a signed Prewrite. The independent proof builder
   verifies that *exact* durable prewrite before any WordPress write.
6. A fresh Original318 Revision 0 readback must pass; the one-shot WordPress
   `mad4b wpcli-host-grant anchor-reconcile-signed` command is armed in an
   exclusive Root durable send-intent BEFORE being called. On unknown result,
   no automatic retransmission is allowed.
7. Independent WordPress Original318 readback and Original313 v99 finalizer
   produce Revision 1, signed Checkpoint, high-water and Final Receipt. A
   missing receipt can be finalized from original signed documents after
   observing the exact Revision 1 Anchor, without a second WordPress write.

## Crash and concurrency protection

- All CLI / worker issuance paths share an exclusive Root `flock`.
- Repeated signed Owner Enrollment / Original312 documents / Prewrite are
  **not** reissued after a process restart. Reuse requires exact signed
  private record readback, genuine current WordPress originals and freshness.
- An armed original318 send-intent is an unconditional no-retry marker.
- Postwrite-only recovery reads Revision 1 and checks all signatures.
- Once Final Receipt exists, the background worker must not repeatedly
  consume WordPress CLI slots.

## Activation

Installing source files is not commissioning. The coordinator requires
Root-only `/var/lib/mad4b/original-host-v2/
.first-issue-root-commissioned-v121.json` with an exact SHA-256 of the
installed coordinator, staging scope and mandatory fresh Owner request.
No caller-supplied plan, key, path or arbitrary company is allowed.

The worker overlay must be installed through an exact SHA compare-and-swap
only after tests pass, with backup and rollback. A current expired Job must
return HOLD and generate no Owner Grant, Enrollment or Anchor.

The owner UI still requires a separate authenticated confirmation for a
new Original305 Request. Never turn this into a background-generated Owner
approval, or modify the expired job's expiry time.

## Verification

- tests/host-v121/test_first_anchor_coordinator.py
- tests/host-v121/test_root_pipeline_simulated.py
- tests/host-v121/test_worker_source_candidate.py
- all v120, v119, v118 and Original105/100/99 regression suites
- on Staging: one live authenticated Owner Request followed by real signed
  Original306 Claim and first-party signed Root / WordPress readback.

**Successful code tests alone are not proof of a live issued Anchor.**
