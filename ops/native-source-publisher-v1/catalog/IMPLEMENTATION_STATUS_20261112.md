# Native Source Publisher v1 implementation status — 2026-11-12

## Completed in Git

- A separate site-native exact-target publisher for tours-central:staging;
  no remote Host Authority v2 prerequisite.
- Reuses installed Company Code Release transactional core with immutable Git
  object, preimage CAS, durable root-private backup, atomic file writes,
  rollback, one-time Broker nonce, bounded acceptance checks.
- Root-owned policy and a distinct Master Admin attestation public key are
  mandatory. Two signer public keys MUST differ.
- Approved ticket attestation is bound to the immutable plan and exact approval
  payload SHA. At execution the native publisher freshly rechecks the actual
  WordPress approved ticket (including ability, provider, site UUID, approved
  status, admin identity, expiry) and reserves a one-time ticket marker.
- First-party SHA-pinned original 306 Registry enrollment is mandatory.
  Original worker root-private reservation and fresh original 312 authority
  are mandatory before the private host handler invokes the writer.
- Independent Original313 verifier reads the real committed host receipt and
  exact site file hash, tied to the same original signed Job; it returns an
  unsigned schema-valid evidence payload. It never signs a Central receipt.
- Candidate provider manifest, evidence schema, policy template DISABLED, and
  executable source-only registration proposal are present.
- Read-only CLI status, plan, and revalidation are implemented; no direct
  apply CLI or issuer exists.

## Test results

16/16 isolated source/manifest/registry tests passed. A second full-suite
comparison found 348 tests on this branch (8 failures, 14 errors) versus 332
tests on its parent (8 failures, 14 errors), with the exact SAME 22 failing
test labels. Those are earlier source/provenance drift failures and cannot be
silently rewritten or counted as passing. The new publisher added no failing
test labels.

The source JS target on Tours.dev was observed at
b9fcf15e7f605cefb4bf21d4ceb73e562e8278e732a1fbacfe6c1dfff578d7c5.
The reviewed candidate target is
da32212e364ef8af046b5756976058e731ef6ec9d7413831e04e411491a45274.
Real Git CAS plan dry-run passed for exactly that file, in a read-only
non-authorizing report.

## Release blockers, not silently waived

1. Existing Central WordPress does NOT have
   mad4b/native-source-release registered as an Ability.
2. First-party original 306/313 Registry has no source.code.release entry.
3. Root-owned local source publisher policy does not exist/enabled.
4. Central Master Admin ticket signer that checks the exact approved ticket,
   and separately trusted Broker release issuer, have not both certified
   this operation. No private signing keys were supplied or copied.
5. Independently signed original313 readback/finalization not performed.
6. The JavaScript candidate was browser-tested by interception, but not
   released to the live Tours.dev site without interception.

No permission, installed registry, live PHP/JS, WordPress data or production
source was changed by this implementation. Approving/registering a publisher
must take place via a separately authorized first-party release channel,
not a raw root file copy or a test fixture signer.
