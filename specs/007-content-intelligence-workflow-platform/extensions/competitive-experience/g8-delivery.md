# Feature 007 — G8 repository implementation evidence

Scope: `PR #289`, a Draft child of Integration Hub `#258`. All statements below describe repository source and disposable fixtures only. They do **not** assert that code has been executed on enrolled Staging, that a provider was exercised or that Production may be changed.

## Landed source

| Slice | Source | Boundary | Status |
|---|---|---|---|
| Automation admission and SLO | `includes/class-mad4b-scp-automation-slo.php` | Signed CAS tickets, exact restore/build binding, L2–L4 Staging-only, default-paused independent kill switch, bounded queues/budgets/cooldown | Implemented; CI/live not yet certified |
| Existing Cron worker fences | `includes/class-mad4b-scp-runtime-convergence.php` | Automatic worker now checks live ticket before owned mutation phases. Manual governed entry remains separate | Implemented; concurrency/live proof pending |
| Supply trust | `includes/class-mad4b-scp-g8-supply-provenance.php` | Reuses existing `Certification_Pack_Registry::verify_pack` to enforce signature/revocation/restore epoch/artifact binding; compares exact candidate source/dependency/mirror claims, per-capability quarantine only | Read-only; needs end-to-end signed-pack fixture |
| Schema migration | `includes/class-mad4b-scp-g8-schema-migration.php` | Declarative additive DAG, dry-run semantic diff, one-record CAS cutover, bounded reversible prestate, stale plan/reader rejection | **Owned observation documents only**; authoritative policy/grant migration remains governed review |
| Fuzzing/chaos | `includes/class-mad4b-scp-g8-compatibility-fuzz.php` | Reproducible data-only fixtures; malformed/schema/hidden effects, timeout, cancellation, duplicate and concurrency faults, differential reports | Disposable simulator only; no provider execution, no live effects |
| Capability-local convergence | `includes/class-mad4b-scp-g8-capability-convergence.php` | Existing provider assessment is source of truth; fingerprint/structural/behavioral drift is classified by capability; compatible reads survive unrelated artifact drift | Read-only; no auto-promotion |
| Operator surface | `includes/class-mad4b-scp-automation-slo.php`, `includes/class-mad4b-scp-operator-workspace.php` | Paused switch state, SLO denominator and conservative provenance/migration/external acceptance labels | Read-only evidence beyond scoped switch action |

Paths are relative to `wp-content/plugins/mad4b-site-control-plane/`, except this report.

## Executable repository gates

- `tests/g8-automation-slo-runtime.php` — exact ticket, CAS, switch revision and repair accounting.
- `tests/g8-extended-runtime.php` — capability scope, signer-verifier rejection, mirror/dependency/downgrade denial, additive migration+rollback, differential fuzz, queue storm, metric corruption and site cooldown.
- `.github/workflows/feature-007-g8-contract.yml` — exact-head checkout, PHP 7.4 and PHP 8.3 syntax and hermetic fixture checks.

The supply regression uses a *stubbed* existing signature-verification call to prove how G8 handles valid/invalid verifier outcomes. It is **not** cryptographic signature coverage; the real existing Certification Pack signature CI remains mandatory.

## Gate ledger (no premature closures)

- **T3976:** source implementation of capability-local observation/diff; actual rollout and reviewer evidence pending.
- **T3977–T3980:** existing canary/rollback/consent/Skills mechanisms identified; this PR has not established a new live/release acceptance or three verified frontend samples.
- **T4071–T4074:** automatic intake, verified-repair numerator, duration/denominator, cooldown/backpressure and switch code present. False-repair, quarantine, rollback-failure, intervention and cost rates remain explicitly `null` until reliable observations exist.
- **T4075:** deterministic repository-negative fixtures present; live concurrent/clock-skew/circuit-breaker acceptance pending.
- **T4076–T4079:** signed-pack source comparison and operator-level status implemented; actual provider packaging/revocation ingestion and live update view acceptance pending.
- **T4080:** stub rejection tests exist; cryptographic end-to-end verification, revoked real key and dependency-drift package tests pending.
- **T4081–T4084:** **owned observation-only** migration path implemented. Never treat this as permission to migrate authoritative registry, grant, risk or policy state.
- **T4085:** restore-epoch, stale plan, rollback replay and unknown-field scenarios included; interrupted DB cutover and mixed-runtime acceptance pending.
- **T4086–T4089:** seeded data-only differential/fault test path implemented. Actual disposable WordPress multi-runtime certification and review receipts pending.
- **T4090:** evaluator cannot execute production/shared/paid/irreversible providers; live harness network/host isolation proof pending.
- **T4091–T4095:** `includes/class-mad4b-scp-g8-restore-convergence.php` now reads the pre-existing external Restore Epoch, runtime generation, signed pack registry, internal migration/worker state and external-inventory evidence, and provides bounded data-only external-effect discrepancy proposals. It **does not** acknowledge restore, invalidate/re-enable real permissions, synthesize receipts or replay externally applied effects. Full DB/files/host generation parity, external provider readbacks, governed grant reconciliation, real rollback/downgrade tests and independently signed post-restore acceptance remain open.
- **Parallel PR integration:** PR #288 (G9) also introduces passive disaster-recovery convergence with independent external resilience anchors. It overlaps this PR in `mad4b-site-control-plane.php` bootstrap only. Resolve the exact bootstrap diff and retain both non-authorizing contracts when composing #288/#289 into #258; never silently remove either registration. No merge is authorized by this note.
- **Cryptography:** G8 hermetic supply test stubs the existing pack verifier; the G8 CI matrix now separately runs the actual `certification-pack-registry-contract.php`, `crypto-profile-runtime.php` and `restore-epoch-runtime.php`. Passing those does **not** prove a deployed signed package, revocation network or key rotation without runtime evidence.

## Required promotion sequence

1. CI must finish and pass on **exact PR HEAD**, including the G8 matrix on PHP 7.4 and 8.3.
2. Close CI/test defects, run disposable WordPress/PHP/MySQL/MariaDB fault and migration acceptance, and reconcile Spec Kit task evidence.
3. Collect separately authorized signed package, external MCP initialize/tools-list, consent/context/host, frontend (3+ distinct samples), browser and reversible-canary receipts.
4. Confirm zero authority expansion, no Production mutations, and unaffected parallel children in Integration Hub.
5. Obtain exact-head owner attestation and separately authorized merge into `#258`. Promotion of `#258` to master is independent.

**This document does not authorize release or merging.**

## Review and remediation — 2026-10-08

Security and correctness review of the current PR branch identified and patched these defects:

| Severity | Confirmed source defect | Remediation in this PR |
|---|---|---|
| P0 | Runtime Convergence marked a repair verified from reported completion without independent current runtime blockers; a switched ticket could inflate success evidence. | Require independent runtime status and live ticket recheck before `verified_repair`; claim-only and switch-race fixtures added. |
| P0 | Capability diff could call newly eligible writes `UNCHANGED` and preserve reads after eligibility loss. | Explicit expansion review and READ_FENCED classifications, including certification/evidence changes and exact site/restore binding. |
| P1 | Schema v3 could be read by undeclared v1 worker, permitting stale readers to interpret future fields. | Explicit N/N-1 observation reader window; N-2 denied and unknown fields preserved. |
| P1 | Signed, expired provider retry buckets accumulated forever and could exhaust scope capacity despite elapsed windows. | Bounded deterministic pruning of old, cooldown-free buckets without evicting active ticket scopes or site bucket. |
| P1 | Malformed fuzz fixtures containing closures/objects hit `serialize` before type validation. | Validate entire fixture tree before serialization; closure fixture yields stable denial. |
| P1 | Automatic worker had late checkpoint/retry/version metadata writes after its last ticket fence. | Recheck independent automatic ticket before late writes and retry scheduling, without changing governed manual paths. |
| P1 | GitHub Actions G8 concurrency group contained the exact commit SHA and could not cancel superseded PR runs. | Stable PR-number concurrency group, retaining exact-head checkout guard. |
| P1 | Rollback receipts relied on row integrity but did not independently revalidate the stored historical snapshot shape/digest. | Verify receipt prestate digest, schema, scope, order and restore binding before readback or rollback. |

Remaining acceptance limitations are **not** solved by these source patches: a successful PHP 7.4/8.3 GitHub Actions run at the final exact head, provider and signed-package integration, real MySQL/MariaDB fault matrix, browser/MCP/host proofs, live 3+ frontend samples, and independent governed Staging/release approval. GitHub queues are not a passing test receipt. Repository code must not re-enable Production writes or grant broad new capabilities on the strength of this document.
