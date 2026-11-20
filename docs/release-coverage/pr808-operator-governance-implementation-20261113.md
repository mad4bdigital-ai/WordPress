# PR #808 — Registered policy governance for Operator Grants (source review)

**Status:** source-only; **no Production activation, no owner-signed release**.

## Why the previous hotfix was wrong

The previous Operator `nonproduction_governed('write')` returned a static
Local/Development/Staging allowlist. This contradicted MAD4B's policy-governed
site/company admission, even though it matched the old Enrollment metadata.
Deleting that predicate in isolation would have left the outer Enrollment
Ability, first-party Ability Census and Remote Operation Parity enforcing
incompatible policies. The new design treats this as an end-to-end contract.

## Existing engines reused

- `Operation Registry` owns the *declarative* exact operation and write-policy
  identity. No static operation-by-operation dispatch switch; unknown, duplicate
  or invalid descriptors are denied.
- `Registered Write Admission` combines the authoritative Registry with exact
  enrolled Site Profile UUID, origin, profile revision/digest, managed runtime
  Write, first-party Policy Resolution and **effective** high-risk runtime
  policy. Staging and Production use the same resolver; Production requires
  currently effective, profile-bound runtime policy. This is a **preflight**
  only, explicitly not a signed Grant, approval or authority.
- `Operator Grant Convergence` retains the real Developer Authority, exact
  verified OAuth step-up/CIMD client, certified plan, five new grants maximum,
  expected Plan SHA, exact source/site/profile binding, audit, completion and
  14/14 grant readback. It no longer applies a hardcoded environment blacklist.
- `Governed Ability Census` accepts only exact first-party callback provenance
  plus its owner-reviewed registry policy metadata. Legacy Staging-only
  operations remain Staging-only until individually migrated.
- `Enrollment Dispatcher` revalidates the same request/site/step-up/operation
  policy on direct and brokered execution. It binds registration digest,
  policy digest, input schema SHA and exact child execution fence. Unverified
  runtime metadata, policy-class changes and unknown operations fail closed.
- `MAD4B_SCP_Abilities::can_enrollment_dispatch()` routes the new registered
  policy class through its verified Admission and retains unchanged legacy
  Staging Remote Operation Parity on other operations.
- The outer `mad4b/enrollment-execute` metadata declares **policy-governed**,
  not unconditional Production access. Discover/info remain non-mutating.

## Verified source and negative tests

The test suite checks both environments, inactive effective Production policy,
revoked Site Profile, incorrect origin, disabled Write, revoked managed runtime,
Policy Resolution DENY, duplicate/malformed registered operation, forged
unconditional MCP metadata, first-party callback provenance, stale prepared
request digests, direct dispatcher bypass, outer Ability policy classification,
legacy parity conservation, and zero callbacks/Grants. Also run the original
Enrollment and Release Candidate regression tests.

## Explicit remaining release and compatibility blockers

1. `MAD4B_SCP_Developer_Authority` contains historical informational
   `production_allowed=false` fields in its status, registration and **canonical
   plan**. Do **not** rewrite this plan field casually: its SHA is in exact
   existing approval/Grant contracts. A separate reviewed schema-version
   migration must reconcile or deprecate the legacy field without invalidating
   stored signed historical plans.
2. Legacy Remote Operation Parity and older Enrollment Abilities still carry
   explicit Staging policies. They are left intact deliberately. Individual
   operation policies can migrate via the Registry only after independent
   review; there is no blanket privilege uplift.
3. The existing central first-party provider enrollment, signed Original306
   associations, current Original312 authority, Original313 independent
   verification and canonical GitHub Release Verdict remain mandatory. Local
   source-only fixtures, SHA manifests and CI do not issue any such proof.
4. No code has been installed on Tours.dev or Wayfatours. No Grant, WordPress
   option, Job, Claim or approval ticket has been created or replayed.

**Disposition:** `HOLD__OWNER_SIGNED_RELEASE_AND_HISTORIC_PLAN_MIGRATION_REQUIRED`.
