# Host v119 — First Anchor admission and signed prewrite interoperability

**Scope:** All Royal Egypt / Staging / hosting-2 / First Anchor Revision 1.

## Implemented

The first_anchor_runner.py file is a **Root-only read-only commissioning gate**.
It reads the immutable Original306 QUEUED evidence captured by v118 and checks
the exact source hash pins for v105/v100/v99/v118. It retains the provider's
execution_enabled=false boundary and refuses a reconstructed QUEUED Job.

The admission verifier requires:
- Same original job, request, Grant, transport, plan, company, environment, host and site.
- Original306 QUEUED and CLAIMED records differ only at the three known claim-state fields.
- Fresh Owner Request and claimed Job, with 115+ seconds of signing headroom.
- Current Original328 active and Original318 pristine Revision 0.
- Original v86 claimed verification and independently sourced Broker Row.

The prewrite_admission.py file is a **pure signed-envelope composer**:
- Checks original312 signed anchor, bootstrap and ledger compatibility.
- Creates an exact original313 Prewrite approval with an independent *passed* signer.
- Requires a durable, authenticated original313 v99 transaction plus verified dual
  signatures before assembling any Original318 proof.
- Has no live signing-key loader, CLI issuer, WordPress mutation or automatic retry.

The recovery classifier holds or distinguishes: missing Enrollment/Prewrite,
Prewrite-only, postwrite without Final Receipt, complete receipt requiring
independent validation, and unauthorized or inconsistent host state.

## Tests

All synthetic keys and temp stores; never the live signer or WordPress:

- tests/host-v119/test_first_anchor_runner.py — 10 cases
- tests/host-v119/test_signed_prewrite_interop.py — 7 cases
- tests/host-v119/test_original_end_to_end_synthetic.py — 5 cases

The integration tests use original v100 signed documents plus original v99
real Prewrite reservation and independent Checkpoint/Final Receipt logic in
an isolated temporary private store. Tests cover signed postwrite, bad readback,
recovery after failed postwrite observation, repeat reservation, and immutable
signed receipt replay.

## Uncommissioned dependencies and safety boundary

**This source-only release is not a live First Anchor issuer.**

- Registered host-anchor execution_enabled must remain false.
- An authentic independently sourced Broker Row must still be connected to
  a fixed Root reader; it MUST NOT be invented by mapping fields from a
  WordPress Job or from a test fixture.
- The v105 first-party Enrollment must first verify the fresh exact original305,
  original306, original328, original318 and v118 historical QUEUED witness.
- The v100 signed documents must be saved immutably before v99 independently
  signed Prewrite, then one original318 signed reconcile, then original313
  checkpoint and Final Receipt, and an independent signed Revision 1 readback.
- The old Original305/306 Job is expired and cannot be replayed, renewed by
  changing timestamps, or used for the live signer.
- Concurrent first-issue and crash/replay of the actual commissioned worker
  plus WordPress Original318 remain separate acceptance gates.
- No new REST route, Production change, Host permission or generic WPCLI
  execution path is authorized by this source-only PR.

Commission a narrowly scoped Root-only issuer only after independently
proving the broker-row source and complete failure-injection acceptance.
Only then generate one fresh owner-authorized Original305/306 Job and finalize
the exact signed evidence in its validity window.
