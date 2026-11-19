# PR #814 — Hermetic Native Source CI vs Original Host signed evidence

**Scope:** improve the source-only GitHub regression runner; release remains **HOLD**.

The original unified Workflow ran every `test_native_source*.py` module on a
fresh GitHub Ubuntu runner. Twelve modules require the actual Original305,
Original306, Original312 and Original313 root-owned enrolled Registry, signed
historical Host records, local ephemeral admin authorization, or original
historical Git objects. GitHub does **not** have that trusted Host state.
Attempting to execute those tests in a disposable Runner yielded 59 errors
and nine failures, obscuring useful source regressions. Some test-only PHP
assertions additionally use a pinned Docker image `php:8.5-cli` with
`--pull=never`, which was not preloaded on the Runner.

The new `ci_portable_native_tests.py` discovers Native Source Python test
modules from the repository and runs every module **except explicitly named
Host-evidence consumers**. Its JSON result lists all 12 excluded module names
and the precise trusted Host dependency. New Native Source modules are
included automatically without source-operation allowlists. The partition
fails closed if any declared Host-dependent module disappears, and reports
`SOURCE_ONLY__HOST_EVIDENCE_RELEASE_HOLD`, always with
`original_host_authority_verified=false`,
`original313_production_receipt_verified=false`, and
`release_authorized=false`. Three new regression tests prove these
properties, automatic discovery, and unknown Host-module refusal.

The workflow verifies the `php:8.5-cli` Docker test image is present, fetching
it only if missing **before** running `--pull=never` isolated tests. This is a
public PHP test-image preflight, not a dependency enrollment, signed
Original313 attestation or production Docker/WordPress mutation.

Local source acceptance: **101/101** portable Native Source tests, **3/3**
partition integrity tests, **9/9** unified behavior invariants, and **103/103**
Company Release core tests passed. This is not equivalent to completing the
12 separate, Host-only files; they remain unverified by a GitHub Runner and
must be checked by authenticated first-party Host readback before a real
release. Existing Owner exact-head attestation, full Production Readiness,
source parity, signed Original312 and 313 receipts, and Target CAS gates are
unchanged and cannot be made green by this CI reclassification.

No code has been installed to sites; no grants, Jobs, signed approvals or
worker policies were modified. No `master` merge, source branch deletion or
Production execution is permitted by this note.
