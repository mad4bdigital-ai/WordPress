# Original305 / CAS source-only reconciliation — 2026-11-16

Parent source: `integration/pr814-821-source-converged-20261115`,
base `57746e6270f68308418cf84ba70a8421a430398e`.
Legacy reference: `fix/company-cas-original305-intent-20261112`.

## Scope

Ported only **additive** dynamic CAS provider handler, independent reader,
finalizer, upgrade *planner*, provider schema/manifest and isolated tests.
Kept the **newer** shared `registry.py`, `worker_adapter.py`, and
Original313 PHP verifier, avoiding a downgrade to the old branch.
The canonical preclaim source was already identical to the old branch.

The historical WordPress MU-plugin, pinned broker public key, legacy
plugin-path duplicates, signed provenance reports, provider index edits,
worker activation and live code were **not** imported. Importing a disabled
provider manifest is not enrolling an operation.

## Safety boundary

- Only `tour-rates.rate_title` is admitted in this source candidate;
  commercial pricing remains disabled.
- Source preview still excludes `company.resource.cas.apply`.
- The Original305, Host312 and Original313 signed approval chain remains
  mandatory and independent of passing local tests.
- As independently observed on 2026-11-16, Host Provider V8 was registered
  but signed readiness, owner approval, Host312 proof, and Original313 receipt
  were all absent. The central gate was `P0_BLOCKED`.
- This commit authorizes **no production deploy, signing, key change, or
  automatic provider activation**.

## Further work

Reconcile the legacy WordPress runtime and older provenance tests against
current canonical PHP ownership; re-evaluate fingerprints and obtain genuine
original signed proof before any runtime activation. Do not restore obsolete
autoload paths just to make legacy source tests pass.
