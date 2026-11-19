# Host v118 — First Anchor queued-original preimage (Staging)

## Purpose

Preserve the *genuine* Original306 **QUEUED** Job before the signed Claim
mutates the WordPress Broker Job into
`BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY`.
Original313 v105 requires both historical QUEUED evidence and a fresh genuine
CLAIMED Job; it must not synthesize the prior state from the claimed record.

**Scope:** `all-royal-egypt / staging / hosting-2`, `company.host-authority.anchor.issue`.

## Installed surfaces and boundaries

- `ops/host-anchor-v118/queued_original_capture.py`: root-only source.
  The only supported live capture path obtains the exact job/request/grant/
  parent/transport/Original318 readback and Broker Pending row from the
  enrolled WordPress WP-CLI runtime. It validates the existing SHA-pinned
  Original306 v95 first-claim contract and the enrolled immutable Root Plan.
- Store: `/var/lib/mad4b/original-host-v2/queued-evidence/<job>.json`,
  owned by root (directory 0700, file 0600, exclusive creation, fsync,
  checksum, no symlinks). Identical capture retries are safe. Changes in
  actual original data are rejected; capture timestamp alone may differ.
- `ops/host-anchor-v118/patch_original306_worker.py`: **source-only**
  exact-match patch *candidate*; never installs or activates itself. The
  candidate inserts a fail-closed capture step after existing v95 preclaim
  verification and **before** the original signed Original306 Claim.
  It does not change the signed Claim method, Original312, Original313,
  WordPress, production, other company jobs or Root key use.

The captured preimage is historical evidence **only**. It grants no Host
authority and cannot make an expired or non-matching Job eligible to sign.
Every later stage must re-verify original sources, fresh expiry, signed
Original306 Claim and original318 host readback.

## Live findings, 19 Nov 2026

The previous Staging Owner Request is **expired**, and Original306's Job
is already CLAIMED. A read-only run of the v118 preimage inspector returned
`HOLD_NO_CURRENT_QUEUED_ORIGINAL`, without creating a file or signature.
Original313 enrollments, revisions, checkpoints, transactions and receipts
remain absent, and the Anchor is Revision 0.

Original312 v100, Original313 v105 and v99 sources/key pins exist, but
there is no commissioned one-shot First Anchor orchestrator. The registry
manifest deliberately has `execution_enabled=false`. **Do not flip it**
or route Host Anchor into generic WP-CLI/Host Source execution.

## Release acceptance sequence

1. Complete a purpose-separated Root-only runner with fresh Original305,
   original328, Original306 claimed readback and the stored historical
   QUEUED evidence checked against each other. Before a Claim, v118 must
   be integrated into the root worker under its exact SHA pin.
2. Test an isolated concurrent double-claim and crash after witness capture
   (the same preimage remains safe to reuse; never invent a prior state).
3. Within the fresh Owner Request/Job window, verify original v105 to create
   the independent Original313 Enrollment, then call v100 to sign Broker
   Original312 Anchor + Ledger documents. Revalidate the current WordPress
   Original318 Revision 0 and non-authorizing status.
4. Independently sign and reserve Original313 v99 prewrite admission, and
   persist recoverable exact signed documents before the single authorized
   Original318 WP-CLI reconcile. Do **not** issue the option before reserve.
5. Independently read the signed Original318 postwrite state; then Original313
   v99 finalizes checkpoint and final receipt. Verify the original high-water
   chain and signed readback for Revision 1. Crash/replay must be safe across
   all boundaries, including the option-write → checkpoint interval.
6. Only after hermetic tests and an authorized release activate the **scoped**
   runner. Then a new explicit Owner Request may be made; never replay the
   expired request or mint an Owner approval through Root.

The existing Original306 300-second job window and Original313 v105
90-second Claim freshness check are hard gates. Renewing an expired token,
reissuing the old Job, changing effective timestamps, or generating a
synthetic Owner signature is **not** a recovery path.

## Tests

- `python3 -B tests/host-v118/test_queued_original_capture.py`
- `python3 -B tests/host-v118/test_root_worker_hook.py`

Use a root-owned ephemeral test store only; neither test creates a live
Owner Request, Claim, Enrollment, Anchor, checkpoint or receipt. The live
root module and worker hook are **not** commissioned by merging this code.
