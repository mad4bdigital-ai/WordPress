# Native Source: signed Plan-to-Operation Association (source candidate)

Status: **source-only verification contract, not installed on Original306/313**.

## Original problem

The original first-party Original306 worker indexes a plan separately for every
enrolled provider that shares its plan_contract. The candidate source publisher
formerly tried to carry operation metadata under source_binding *inside* the
original CAS plan. Original313 refuses that modified document with
ORIGINAL_PLAN_CONTENT_CHANGED. Removing source_binding restores the original
immutable plan digest, but the worker can index the same plan under two
different operations.

## Contract (v1)

operation_plan_association.py verifies a separate record, named by the exact
immutable original plan SHA256, stored in a root-private first-party association
directory. It does not mutate the plan. The record includes:

- Original immutable plan SHA256 and exact operation_id
- Manifest SHA256 plus provider and approval Ability (from verified Registry)
- Company, environment, Site UUID and canonical origin from original CAS plan
- Independent issuer name, nonce, short issue/expiry window, Ed25519 signature

The verified record selects exactly one operation for that plan. Tampering with
its operation, scope, manifest, signature, expiry, or file-store permissions
causes rejection. The verifier has **no private signing key**, issuing endpoint,
approval API, Job claim, file writer or standalone release permission.

The test fixture generates throwaway signing keys in memory. Those are NOT
first-party credentials, installed issuer trust or any Original312/313 receipt.

## Required first-party worker integration (NOT implemented)

1. Have the authorized Central issuer independently validate that the
   intended operation, source files, site identity, approved ticket and plan
   are eligible. Issue a short-lived signed Plan Association; enroll the
   issuer's *public* key via approved first-party governance.
2. Store one signed association per plan SHA in a root-owned protected
   directory. Do not overwrite or reuse an uncertain association to retry a
   previously claimed Job.
3. Original306 read-only plan validation must inspect the original unchanged
   CAS document. Then, before indexing possible commands, the worker should
   resolve the operation from the first-party verified Registry and signed
   association rather than iterating all manifests sharing plan_contract.
4. Original306 still validates the claimed Job and fresh Original312,
   reserves the same Job and invokes only the enrolled SHA-pinned handler.
   Association verification is **selection**, not an alternative authority.
5. Original313 independent readback must bind the signed proof to this exact
   operation, plan, Job, manifest and environment (including Production), then
   let only the independent original finalizer issue a signed receipt.

Do not install this contract merely because its tests pass. The original worker
still lacks the hook; it continues to map a compatible plain CAS plan under
multiple operations. The existing release audit records that as HOLD.

## Additional required protections, unchanged

- A signed association does not resolve the existing source/target mapping
  problem or the independently derived changed_count issue. Those remain
  separate release blockers.
- The candidate's Production evidence mismatch remains open.
- Root-owned policy, installed Central review Ability, independently approved
  first-party Registry enrollment and signed 306/312/313 authority are missing.
- No live Tours.dev code, Wayfatours site, installed worker, grants or production
  data were altered while designing or testing this source candidate.

## Verification

Focused tests use two fake provider manifests sharing one original plan
contract, a throwaway Ed25519 key, and an ephemeral root-private association
file. The tests also execute the *installed read-only*
admission.checked_plan against the plain original CAS plan and verify the
signed association through the new pure verifier. They never invoke
execute_claimed or any signed Broker action.

## Non-executing associated worker index

The source-only associated_worker_index.py implements the selection
boundary and retains original command digest generation and read-only plan
validation. It limits source-family plans to one signed Association. It is
not imported by the installed Original306 worker. Before authorized
enrollment, reconcile the tracked source worker's newer preclaim features
against the installed worker rather than overwriting either.
Details: ASSOCIATED_WORKER_INDEX_INTEGRATION_20261112.md.

## Follow-up contract reconciliation — 2026-11-12

The source-only Original313 readback now derives the Production mutation flag
from an exact APPLIED_VERIFIED host receipt *after* grant checks and post-image
SHA verification. The original independent verifier and installed source
worker were not changed; no independent Original313 proof was signed.

The source CAS envelope is **still** not admissible as an original plan in
Original313. Deployment must use an unmodified CAS plan plus a separately
signed association, and resolve the legacy plan/store command collisions before
worker indexing. Do not remove this blocker merely because the evidence flag
now satisfies the independent verifier's environment contract.
