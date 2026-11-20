# Host Authority v124 — master-based source-only Release Candidate

**Base:** `master` at `c34b93fb485c33608a0d1cd3681ac97dd25e18cf`
**Validated source:** `ba644d24aa5c0a0750e35cc61b5e558a5a6a6401` (v123, signed first Anchor).
**Scope:** All Royal Egypt / Staging / hosting-2.

This is a **source-preservation and review candidate**, **not** a runnable
WordPress or Root release. It imports 29 SHA-256-pinned source, review and
reference-document files from the verified feature head, on top of master,
without merging ~994 unrelated feature commits.

## Deliberately disabled

- No new `wp-content/` files. The Owner UI, certificate verifier and AJAX
  asset are quarantined under `deploy/host-authority/review/v124/`.
- No activated WordPress MU-Plugin, REST/AJAX endpoint, Original306 Worker,
  systemd timer, Root commissioning marker, Owner Request, or Original318
  write.
- No Production deployment; the live Staging Revision 1 and its original313
  signed Root records remain untouched.
- Host source paths under `ops/` and `deploy/` are **not auto-loaded**.
  Do not run any of their direct `__main__` paths on Production.

## Mandatory runtime dependencies not yet present in master

The source manifests on this branch must not be mistaken for runtime parity.
A separate manifest-bound WordPress release must reconcile and test the
five original runtime components from the source branch:

- Original305 signed owner command intake;
- Original306 central VPS Broker/claim;
- Original313 native WP-CLI enrollment;
- Original318 Host exact grant and signed readback;
- Original328 signed remote Hostinger transport.

These components reside in `mad4b-platform`, which is not present in the
current master source tree. Source discovery and SHA comparisons must precede
any selective import. Additional first-party provider and registry
dependencies must be resolved recursively, with both original site identity
and authorization checks intact. Do not invent mock Production grants.

## Follow-on release gates

1. A source completeness scan covering original 305/306/312/313/318/328,
   exact Root Source Registry, signed Broker/independent key identities,
   WPML/Fluent Forms implications only if affected.
2. An explicit per-file dependency contract for any PHP plugin entrypoint,
   WordPress hook and Root artifact. Review and reconcile changes from master
   first; source files are not blindly overwritten.
3. Hermetic PHP/Node/Python tests and real Staging read-only parity. The
   already issued Rev 1 remains signed and immutable.
4. New branch based on **fresh** master, narrowly scoped PR and required
   `Repository release verdict` and `Repository feature boundary`
   status checks, plus exact-head owner attestation
   `OWNER_ATTEST_SINGLE_OWNER` for the intended merge head.
5. Production release only under its own independent site-specific approval.
   This v124 source candidate does not authorize it.

## Verification

`python3 -B tests/host-v124/test_master_source_only.py`

The test checks 29 file hashes, no runtime activation, five known missing
originals, no WordPress or CI file changes, and source syntax without importing
or executing Root code. It does **not** claim a new Host Anchor.
