# Host v120 — Original Broker-signed QUEUED witness

**All Royal Egypt / Staging / hosting-2 / First Host Anchor only.**

## Finding

Original306's WordPress Broker Job changes from QUEUED to CLAIMED during
the signed Broker claim. The v118 immutable preimage preserves the original
QUEUED record, but Original313 v105 and Original306 v86 additionally require
a trustworthy Broker Row containing job ID, command digest, target, site,
company, environment and Broker host ID.

The existing Broker event journal logs *attempt/claimed status*, not an
independently signed, complete Broker Row. Do not manufacture it from the
current CLAIMED job or treat an event-log label as original source proof.

## Source-only implementation

- broker_row_witness.py: The original Root Broker Ed25519 key signs a
  **separate domain-bound** witness over the genuine fresh QUEUED job
  originally captured by v118, before any Original306 Claim.
- The signer module and the v118 preimage module are SHA-pinned. The live
  capture verifies the current QUEUED WordPress originals a second time,
  checks the exact original Owner, Grant, Original328, transport, Root Plan,
  and enrolled Broker public key, and then signs once. No caller may pass a
  JSON file, private key or arbitrary tenant through the CLI.
- The first-party Broker witness contains the exact Broker Row expected by
  original v86, plus immutable plan/Grant/transport/preimage SHA-256, time
  window, nonce and Ed25519 signature. The Broker public key is the
  exact enrolled host_anchor_312_public_key, distinct from Original313's
  independent signer.
- Root storage is under /var/lib/mad4b/original-host-v2/broker-row-witness:
  root-only 0700 directory, 0600 file, exclusive creation, fsync, no symlink,
  exact signature/schema verification, and no rewriting altered evidence.
- broker_signed_admission.py is **read-only**. It uses the signed witness,
  original v118 preimage, real current WordPress CLAIMED+Claim readback, and
  original v119/v86 verification. Neither this nor any test admits an
  unsigned Broker Row to Original313 signing.
- patch_firstparty_worker.py is a **source-only exact-SHA patch candidate**:
  v118 preclaim evidence -> v120 Broker witness -> existing signed Original306
  Claim. It does not change the Claim signature or call any issuer.

## Tests

- tests/host-v120/test_broker_row_witness.py: 11 hermetic tests
- tests/host-v120/test_worker_candidate.py: 4 tests
- tests/host-v120/test_signed_broker_admission.py: 8 tests

The test keys are synthetic and the private stores are ephemeral.
The installed Broker private key is **not used** in these tests.

## Safety and remaining commissioning

This is NOT a live Host Authority Anchor issuance. The current expired
Owner Request remains unusable. The worker and generic Host Anchor provider
must stay unchanged while the first-party Root runner is uncommissioned.

The v120 signed Broker Row is an **independent Broker signature attesting to
the historical WordPress QUEUED snapshot**. It is not an additional
independent external database; the separate original Broker process must
capture/sign it at preclaim time to preserve provenance.

Before activating the combined v118/v120 worker hook, implement and verify
a fixed, purpose-bound v105 Original313 Enrollment -> v100 Original312
documents -> independently signed v99 Prewrite -> **one** original318
reconcile -> original313 Checkpoint and Final Receipt. Include an exact
broker-row provenance proof, failure injection and process-concurrency tests.

Real-time constraints must be proven using original WordPress and Root
paths. Original305/306 jobs expire quickly and v105 requires a recent Claim.
Under WordPress CLI capacity pressure, defer rather than sign stale data or
extend its timestamps. Only after the signed first-party issuance chain is
commissioned may an owner submit **one fresh** authorized request.
