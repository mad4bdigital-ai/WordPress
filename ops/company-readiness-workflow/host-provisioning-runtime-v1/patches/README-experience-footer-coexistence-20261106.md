# MAD4B Experience: dynamic Footer owner coexistence (2026-11-06)

**Scope:** `mad4b-experience/src/presentation/bootstrap.php` from the live
Tours.dev staging source. The Experience plugin is not otherwise represented
as a complete deployable plugin in this Git checkout.

## Change

Avoid redeclaring `MAD4B_Footer_LookFeel` while the existing
`mad4b-footer-bridge` plugin is active (including multisite activation) or
the class has already been loaded. Preserve the legacy Footer hook owner;
Experience automatically resumes its Footer module when that bridge is
inactive and the class is not already defined. Do not detach the separate
Wayfatours `357` Options Bridge as part of this change.

## Exact, reproducible source proof

- Git source snapshot: `../live-only-module-snapshots/mad4b-experience/src/presentation/bootstrap.php`
- Source-only change patch: `mad4b-experience-footer-coexistence-20261106.patch`
- Original pre-patch Bootstrap SHA-256: `544378e52d6de4aa6ef374e7aff103d9a9fe69a734893fe5848245c4829f2bff`
- Patched Bootstrap SHA-256: `470b00846323ee5b22f1634991b752196d92f38942983b3c4210b556452a0061`
- Patched **immutable** 38-file package SHA-256: `fe81eaa244d33e06f9d9fbb51933535956d495355985aee80a0d0c0ae63a7969`
- Pinned native installer command SHA-256: `c9d3df50b400028819ea3ad26087ba3536ef14efc355c91b19a64c5d619feb1d`
- Verification: PHP syntax passed, original contract self-test 28 checks passed,
  24 package PHP files passed syntax, package/preflight alignment `ALIGNED`.

Run `python3 ../tests/test_experience_footer_coexistence_patch.py` from this directory,
or invoke that test by absolute path. It reverses and reapplies the patch on a
temporary copy of the committed Bootstrap and validates both SHA-256 digests.

## Operating boundary

This Git record documents the live Staging fix; it does not represent a GitHub
push, a plugin deployment, a new 305 grant/job, a completed installation, or
an original 313 certificate. Wayfatours Production remains unchanged and the
previous failed job must not be replayed. A new owner-authenticated original
305 must be bound to the new package and command hashes.
