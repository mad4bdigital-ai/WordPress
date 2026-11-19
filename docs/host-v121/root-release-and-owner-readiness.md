# Host v121 — Staging Root release and signed Owner readiness

Scope is permanently fixed to **All Royal Egypt / Staging / hosting-2**.
Code merges do not create an Original305 Owner Request.

## Executable safeguards

- **Root-only worker overlay**: validate the full, exact previous Worker SHA and
  replace atomically after a timestamped Root 0600 rollback backup.
- **Root v121 issuer**: immutable SHA, owner/Original328 + signed v118/v120
  evidence, original v105 Enrollment, v100 Broker documents, v119 and original
  v99 independent Prewrite, one Original318 signed reconciliation, independently
  signed postwrite high-water and receipt. No generic execution.
- **Root commissioning** is an explicit root-owned JSON marker pinned to
  the installed issuer source, Staging scope, and mandatory new Owner approval.
  It is not automatically created by the Git branch or frontend.
- **Readiness publisher** is *not* an issuer and issues no Owner Grant. It loads
  only installed SHA-pinned first-party source; requires Root commissioning,
  original Broker signing key identity, signed original328 readback, and
  pristine Original318 Revision 0. Its one WordPress option write is a signed
  UI readiness signal; the frontend verifies its signature against the enrolled
  Broker public key. This does NOT authorize a Host write.
- **Renewal timer** refreshes the signed readiness after three hours.
  The publisher fails closed when Root code changes, the Anchor is no longer
  pristine, Original328 becomes unavailable, or any required original signer
  is absent. The old request never becomes usable again.
- **Owner screen** allows ONE new authenticated Original305 POST only after
  the signed readiness is valid. It never auto-checks the consent box, and
  retains original stale Job ID as audit only; it never changes an old request's
  TTL or silently repeats an uncertain POST.
- **No Production changes** or generic Host operation enablement are part
  of this release.

## Final closure evidence

A production-quality closure requires from actual original sources:
1. The owner's new Original305 Request (not the stale request) and Original306
   signed Claim, plus the separate original Broker-signed v120 QUEUED witness.
2. Original313 signed Enrollment, original312 signed Anchor/Ledger/Bootstrap,
   original313 immutable Prewrite, and exactly one Original318 signed write.
3. Real Original318 Revision 1 readback and Root original313 signed Revision,
   Checkpoint and Final Receipt; automatic readback shown in the owner UI.
4. Original registry generic Host execution still disabled; original306 other
   operations unchanged; resource budgets and rollback verified.

Passing synthetic tests or publishing a readiness signal never counts as a
live Anchor issuance. If no current authenticated Owner Request exists,
record **ready for owner approval**, not **Revision 1 issued**.
