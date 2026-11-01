# Original Company Manager admission — exact Staging environment

This source candidate is a **Staging-only** reconciliation of the original Central Company Manager / Broker admission chain with independently verified company/environment Site Profiles.

## Failure confirmed on live Staging
- `all-royal-egypt:staging`: centrally verified V4 Site UUID `49c562d1-8f2f-456f-b454-26816c6ba4cb`, canonical origin `https://staging.allroyalegypt.com`.
- Original Company Manager (manager code `wo-vacation`) exists with enrolled WordPress owner and active membership. Only `wayfatours` has the old company-wide assignment.
- `316` reads environment-signed identity, but the legacy `300/301/305/312` admission path still attempts `MAD4B_Company_Portfolio_Manager_V1::company_binding(company)`, which checks the company-wide **Production** registry. It must not be populated with a Staging Site Profile.
- `317` implements original WordPress owner-authenticated, revision-exact propose/approve/activate/revoke of a Staging-only assignment. It has been installed on central Staging, but **no original owner proposal or approval has occurred**. It does not approve itself.

## Reviewed compatibility

This folder contains exact source candidates for the already-live `300`, `301`, and `312` plus a new `318` read-only bridge. All must be installed atomically, subject to exact SHA verification, native operator plan, independent readback and rollback.

- `300`: if the exact registered company execution target is Staging, resolve entitlement using `318` instead of the company-wide Production assignment; for other environments retain the original authorization logic.
- `301`: use the exact signed Staging binding for target-specific read/inspection and delegation, but **do not** admit writes without an owner-approved active Staging assignment; original WordPress administrator must still be authenticated.
- `312`: recheck the environment-specific owner-approved assignment on **each claimed Job**, while preserving all original 305 grant, 306 signed Job, 312 host proof, and non-replay checks. Revocation must deny new claims. Its unmodified 313 native readback requirement remains mandatory.
- `305`: unchanged — its original intake calls `301/300` and still requires a real owner-confirmed REST session or enrolled Unified Administrator principal for WP-CLI enrollment. WP-CLI `--user` does not grant owner approval.
- `318`: cannot issue grants, approve proposals, create a transport, start a Job or mark receipts. It binds signed V4 Site UUID, exact canonical origin, current active owner membership, active Staging-only assignment, exact binding digest, matching original owner proposal/approval digests, and pre-existing verified native provider.

Environment proof is not a transport capability. Even after a valid Staging assignment, `remote_write_ready=false` until the separate original Native Executor is certified and has its independent readback.

## Verification

```sh
for f in ops/mad4b-staging-manager-bridge-v1/*.php; do php -l "$f"; done
php ops/mad4b-staging-manager-bridge-v1/tests/staging_admission_hermetic.php
```

Synthetic tests include missing assignment, original read-only identity inspection, active owner-approved Staging assignment, blocked write without Native Executor, immediate revocation, loss of signed identity, and a Production-target mismatch. No live WordPress job is created.

## Release gates
1. Merge source after exact-head tests and owner release attestation.
2. Snapshot SHA256 of all four source candidates, the actual live MU plugin sources, and original Content Hub task ledger before release.
3. Deploy **central Staging only**, atomically, via its already-approved Native VPS Operator; no Production modifications.
4. Independently read back the manager plan, `300/301` assessment, `312` denial for an unsigned Job, existing original queue digests, and Staging HTTP 200.
5. Require the original enrolled owner to create/approve/activate the environment assignment in the original authenticated browser session. The owner cannot be impersonated with WP-CLI `--user` or an SSH root process.
6. Enroll the approved `hosting-2` transport through the original 305 owner path, then prove 306/312/313 with an independent Job and exact host-side identity.

No grant, approval, Production deploy or claim of complete Native WP-CLI capability is authorized merely by merging this source.
