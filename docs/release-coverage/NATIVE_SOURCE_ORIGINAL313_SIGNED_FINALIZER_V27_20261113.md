# V27 — Original313 Native Source Provider Finalizer (source-only)

2026-11-13. No live first-party Registry change, signed Job, site mutation,
private key extraction, Central proof ingest or Original313 receipt.

## Integration, not a parallel signer

`native-source-publisher-v1/original_313_source_finalizer.py` is now declared
as the candidate provider's `finalizer` component and SHA-pinned by the same
original Dynamic Operation Registry manifest generator. The source-only
Original306 Preclaim and independent postimage verifier remain distinct
components, all under their existing first-party root path contracts.

The Finalizer checks, **before any signing**:

1. The actual SHA-pinned installed first-party Registry manifest and exact
   installed finalizer bytes, with no source-worktree module substitution
2. A root-private, original immutable CAS plan, independently signed
   plan-to-operation association and exact original305 plan digest
3. The already claimed original312 Job and its one-time reservation, original
   site/operation/command identity, root-only Broker Grant and separately
   signed Master Admin ticket with live WordPress approval readback
4. The real Host apply receipt with correct issued time and signed312 window,
   plus independent postimage readback of all allowed target files
5. The actual per-operation first-party Central313 operational-ready status
   and support for independently signed Native v4 evidence

The proof layout is the existing `mad4b.dynamic-313-independent-proof.v1`
plus `mad4b.native-313-independent-host-proof.v4`. Their signatures are
created only by the **already enrolled Original313 independent signer**
via the original root `native_signer(index)` loader and never by a new
provider key. The original Broker client handles the signed pending and
final WPCLI finish. Existing `dynamic-313-proof ingest`, `native-313-proof
ingest`, and `broker-remote-ssh wpcli-finish` are reused for Central.

The source-specific durable finish receipt uses its own suffix to avoid
collisions with existing Code Release and Resource CAS receipts. Existing
signed proofs/final receipts cannot be overwritten after partial failures:
matching prior Original313 certification is read-only and idempotent; stale,
mismatched and expired states remain HOLD. No Host Apply/rollback path is
available from this component.

## Validation scope

The test suite uses disposable cryptographic keys **only inside isolated
fixtures**, no installed or production signing key. Python validates the two
signed proof layouts, verifies signatures, rejects postimage and original312
scope drift, and asserts fail-closed behavior before signer access when the
first-party WordPress operation is not enrolled. The real Original313 PHP
Dynamic verifier accepts a properly signed fixture proof and rejects changed
signature, output SHA, or transport identity. First-party Registry fixture
loads and validates the candidate finalizer's exact SHA-pinned bytes.

All tests are source-only. They **do not** demonstrate a live signed Original313
finish, current Production authority or readiness to enroll. The remaining
first-party release gates are independently tracked by the read-only V23
registry extension preflight. The original V19 release guard needs its own
signed approval; original306 Worker and signed association issuer have not
been deployed, and the root policy and Central Write Ability are still absent.

Do not copy this finalizer, replace the installed Registry index, turn on a
policy, or ingest offline fixture signatures into Central. First-party signed
release deployment, real Original305/312 execution and independent live readback
must each produce their own genuine acceptance records.
