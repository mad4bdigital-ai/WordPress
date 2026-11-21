# Host v129 — Root-private approved Desired registry

This is an additive, source-only transactional registry for the Live-First
four-state model. It does not change WordPress options or Host Runner
permissions and is not a remote API.

## Why it exists

Approved Desired is not the same as Git HEAD, Current live code or an
unverified historical JSON file. The source record needs:
- an exact company/environment/site/host/key/policy binding
- an approved Ed25519 source signature over the desired revision
- a separate current high-water signed by the independent registry authority
- continuity from the previously approved revision, under atomic compare-and-swap
- a fresh current-site observation signed by the enrolled Host Runner

Without those proofs, even a functioning live hotfix is just a review
candidate, never a new approved site Desired.

## Admission flow

1. Independently read the site's current installed file set through the
   enrolled Host Runner and its pinned public key. Read at admission time,
   not by taking a user-provided Current dataclass or Git export.
2. Obtain a signed Owner/approved-source Desired and independent signed
   high-water checkpoint for the exact same new revision. The latter is
   fresh for 30 seconds and prevents replay of older signed Desired records.
3. Open the Root-private SQLite store at its configured directory outside
   WordPress. The store must already exist as an owner-owned 0700 directory,
   with no symlink ancestry. The database is created 0600.
4. Within a BEGIN IMMEDIATE transaction, compare the expected previous
   approved-record digest and require the exact next revision.
5. For the first revision, the approved baseline must equal the freshly
   signed live Current. For later revisions, the approved baseline must
   equal the immediately previous signed Desired file state. This retains
   evidence of unreviewed live drift without silently overwriting hotfixes.
6. Append the signed record and checkpoint to immutable revision rows and
   atomically replace the head in that same SQLite transaction. Roll back
   the whole transaction on any failure. SQLite synchronous=FULL.
7. Return a non-authorizing Root registry receipt. This is a registry
   update only; it does not deploy any code, issue Host Write or grant
   Owner/Production permissions.

The public keys must be retrieved from the enrolled first-party authority
registry; the caller cannot set a new trust anchor by supplying an arbitrary
public key in a request.

The stored old high-water is historical evidence and becomes stale; it is
not automatically a current authorizing proof. On every subsequent read
for a deployment decision, obtain and verify a NEW high-water from the
independent signer. A stored historical head is explicitly marked
current_authority_verified=false.

## Security and operational boundaries

- The registry source file is Python-only and does not install itself as a
  service, scheduled job, WordPress plugin, Host Runner command or HTTP API.
- Rejected: foreign site identity, unsigned Current, wrong Host signer,
  stale/revoked high-water, duplicate or skipped revision, mismatched
  baseline, old preimage, public/symlinked database roots and a store
  inside the WordPress document root.
- Production stays untouched. Existing Original312/313/318, Root high-water
  and independent source-approval signer must be connected first.
- A signed registry head is not proof the new desired code is installed:
  actual implementation is always the later exact site-specific atomic
  install operation, with signed Previous backup, commit-time CAS,
  verified post-commit Current and rollback on failed postcondition.
- Cross-signer key rotation and multi-host single-leader registration need
  their own owner-approved migration. Do not bypass historical signature
  verification when rotating or retiring keys.

## Source module / current status

- Code: ops/host-authority-v128/desired_registry_store.py
- Tests: tests/host-v128/test_desired_registry_store.py
- This feature has only been exercised with ephemeral test signer keys
  and isolated temporary databases. No production or Staging Desired
  authority has been minted or updated.
