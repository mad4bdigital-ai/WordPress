# Original306 dynamic associated index — source-only proposal

Status: SOURCE CANDIDATE, not an installed worker or permission grant.

The associated_worker_index.py module composes the installed read-only
admission.checked_plan contract with operation_plan_association to select
one source-family operation for one unchanged CAS plan.

## Compatibility with the existing first-party worker

- Uses the original CAS JSON and Original306 command_sha256 format.
  No new Job type or private signing key is introduced.
- Finds matching providers dynamically by the registered plan contract and
  typed source-family capability; no fixed operation ID, site or target.
- When any source-family provider shares a plan contract, admission requires
  a root-protected, independently signed Plan-to-Operation Association.
  The verified association selects exactly one enrolled provider of that
  family. Legacy providers sharing the same generic plan contract cannot
  be selected as fallback for a source-family plan.
- Missing, expired, wrong-scope, forged or missing-store associations deny
  the entire index. This intentionally may block older pending plans during
  enrollment. Authorized first-party migration must reconcile those plans
  before activating a source-family provider.
- Plan contracts with no matching source-family provider retain their
  installed Original306 indexing behavior.

The selected data explicitly reports unsigned and unclaimed state. The
first-party worker must still run original admission.resolve_claimed, a fresh
original312, the signed claim, reservation, and independent Original313.

## Why the original worker is not blindly replaced

The installed Original306 worker_adapter.py differs from the tracked
ops/dynamic-operation-registry-v1/worker_adapter.py. The tracked version
includes additional preclaim and receipt integrations. A wholesale file
replacement could regress signed claims or existing company operations.

Before adoption, a first-party reviewer must reconcile worker branches,
compare function-level differences, pin the new association module as a
trusted first-party dependency, enroll a protected public issuer key and
root-private association store, and preserve receipt/rollback preclaim logic.

## Tests and policy

- tests/test_native_source_associated_worker_index.py executes the actual
  installed read-only worker_adapter.indexed_plans and
  admission.checked_plan on ephemeral plan fixtures. The original maps two
  eligible operations; the candidate selects one signed operation.
- Tests deny missing/expired/bad-signature associations, cross-provider and
  cross-family fallbacks, unrelated malformed envelopes, and a missing
  association on any source-family plan.
- The read-only audit differentiates this candidate from the installed
  Original306 hook, which is still absent and therefore HOLD.

No source-release Provider was enrolled; no live WordPress, Wayfatours,
Original306 worker, Broker/Grant or Production file was modified.

## Regression and worker-drift readback

The full Python suite required isolating imports of the installed Original306
registry, admission and worker modules in the test fixture. Loading them under
global module names during unittest discovery caused a test from another
first-party provider to use the wrong Registry class. The fixture now loads
them using private module names, then restores the original module cache.
No protected worker file was modified.

The release audit now independently reports both the tracked Git worker SHA
and the installed Original306 worker SHA, and refuses to declare ready while
they differ. This is an explicit reconciliation precondition before adopting
the source-only candidate, not permission to overwrite either implementation.

## Legacy migration compatibility

A read-only audit of the real 16 existing plans found 15 sharing the source
candidate plan contract. The current installed worker indexes all 16 plans
successfully. A separate source-only migration preview revalidated the
15 colliding plans against their existing Original306 provider: all 15 were
uniquely attributable, none ambiguous. No first-party association was signed.
See LEGACY_PLAN_MIGRATION_20261112.md. The release audit now explicitly
blocks candidate enrollment while any legacy plan contract collision remains
unreconciled.
