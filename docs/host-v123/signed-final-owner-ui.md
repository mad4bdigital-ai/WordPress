# MAD4B Host Authority v123 — Signed certificate in owner UI

**Scope:** All Royal Egypt / Staging / hosting-2, first issued Anchor.

## Problem closed

The owner screen previously displayed **ANCHOR_STATE_REVIEW** whenever
Original318 was non-pristine, including after successful, independently
signed Revision 1. That left the owner without a trustworthy indication of
Original313 Final Receipt issuance.

The original Root documents are immutable:
- Broker-signed current Revision 1, original v81 contract;
- independent Original313 signed Checkpoint 1;
- independent Original313 signed Final Receipt for job
  `5b21b7aae2de8e3ef9342ae2ceaa2802`.

## Implementation

1. Root source `publish_final_certificate.py` first verifies all three
   ORIGINAL signatures against the enrolled Broker and independent public
   keys, checks exact original313 receipt/ledger/checkpoint body hashes,
   checks the original job ID, nonce and Staging scope, and reads Original318
   directly through original first-party WordPress connection.
2. A separate **immutable, display-only** WordPress option stores the
   three existing signed documents (no private keys) with an exact contract.
   A replay with any different bytes fails closed. It creates no Grant,
   new Owner Request or Host execution authority.
3. The owner-only PHP helper verifies the same three Ed25519 signatures
   *again* using the enrolled original Broker key and the exact independent
   public key, checks the signed record linkage and **fresh Original318
   ACTIVE Revision 1 readback**. Only then is `ANCHOR_CERTIFIED` displayed.
4. Failure to verify never falls back to a success state. The old
   `ANCHOR_STATE_REVIEW` remains for a stale, missing, modified or forged
   independent certificate. No new Owner Request button is shown.
5. The old Original306 legacy classifier warning is preserved, not erased.
   The audit appends a separate reconciliation status *only after* verifying
   the real original313 Final Receipt and the chronological success
   journal for the exact job. No Worker logger messages are globally
   suppressed or downgraded.

## Validation

- PHP detached Ed25519 verification with the actual immutable Root signed
  records: 17 positive/negative checks including signature tampering,
  cross-site scope, revision, nonce, WordPress SHA, and unknown keys.
- Existing owner PHP and JavaScript suites retained.
- Live enrolled owner readback: `ANCHOR_CERTIFIED`, Revision 1,
  certificate verified, final panel present, no warning/reapproval buttons.
- Root source and WordPress option unchanged after initial signed
  publication, irrespective of refreshing the owner admin page.
- Generic registry execution remains disabled; no Production writes.
