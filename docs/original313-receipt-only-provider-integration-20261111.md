# Original 313 receipt-only recovery — first-party candidate v1

## Exact target and current proof

The Wayfatours Production original Job `a37629fc009a97afe2fb66cef34a48ca` finished Host Apply for original Git plan `c07011d760b1e7c59ef432a1a38c49cc445a89a674738e58e42b323119179b3f`. Its historical signed Broker grant, Host Apply time, Original 306 reservation, signed finish attempt and live two-file postimage passed independent readback. The original Original 312/313 Central Job expired before Finalization. No currently eligible Original 313 certificate exists.

## Candidate operation

- `company.code.release.receipt.reconcile` is **not** `company.code.release` (`apply`). It has its own manifest, evidence schema and distinct command digest.
- The immutable recovery request binds the original Job ID, original plan SHA256, company/environment/site UUID, Git commit, complete exact target SHA256s and hashes of the original plan file, Broker grant, Host Apply receipt, Original 306 reservation and signed finalization attempt.
- The source handler is read-only; it always returns **pending new signed recovery authorization**. The independent verifier recomputes the original evidence and present postimage before reporting `RECEIPT_ONLY_POSTIMAGE_VERIFIED_PENDING_ORIGINAL_313`.
- The evidence schema `success_state` is intentionally a state **never emitted by this Host-only candidate**. Only a separate original Central signed verification can create `CENTRAL_RECOVERY_RECEIPT_INDEPENDENTLY_CERTIFIED`.
- A forged `verified=true` field in caller-supplied authority does **not** become authority. The handler never marks a new recovery grant verified and cannot write WordPress, content, schema or an Original 313 certificate.

## Enrollment gates still required

1. Verify and publish reviewed provider source through first-party source release (code, dependency SHA256s, manifest and index). Do not mutate the current enrolled index by hand.
2. Register the distinct Original 305/306 command hash and Operation Provider, with a **new** signed 312 grant for `receipt_only_readback` and exact original Job/plan (never renew expired originals).
3. Implement a separate Central original 313 recovery verifier that binds this new 312 Job to original historical evidence and current live postimage. It must be independently signed, append-only, idempotent, and visibly distinguished from an ordinary timely Original 313 receipt.
4. Re-test revocation, changed live file, reused old Job, wrong tenant, missing receipt, simultaneous recovery, non-matching independent readback and 313 unavailable cases. Do not claim completion before genuine Central 313 readback.
5. Keep Tenant schema migration separate: four new tables, approved DDL plan, fresh isolated-restorable backup, signed execution grant, post-DDL readback, no automatic site-wide write scope.

## Current acceptance

Tests use a temporary root-owned first-party registry index and check that the candidate manifest passes the installed registry validator. Actual installed `/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled/index.json` is unchanged. The candidate Recovery Plan is metadata only, not an enrolled Job, and cannot trigger execution.
