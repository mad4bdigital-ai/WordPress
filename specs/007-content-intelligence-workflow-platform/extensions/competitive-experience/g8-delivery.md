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

### Additional deep review — exact Cron generation (2026-10-08)

- **Confirmed P0 boundary gap:** a signed, still-live automatic ticket previously proved the package identity, but did not prove that the mutable Runtime Convergence `target_identity` checkpoint still matched the exact target/current pair used for the original ticket `generation`. A concurrent checkpoint edit could leave a live ticket attached to a different maintenance target.
- **Patched:** the real `guard_automatic_ticket` now rechecks current on-disk provenance, exact checkpoint target, and `sha256(serialize([target,current]))` against the reserved ticket at each owned automatic mutation boundary. The final checkpoint retains `target_identity` through late metadata writes. A sliced safe phase passes the ticket into its checkpoint-writing handoff and requires a fresh admission on the next Cron slice. Existing governed/manual execution remains separate.
- **Executable regression:** `tests/g8-cron-generation-guard.php` uses the **real** Runtime Convergence private guard and disposable runtime Provenance file to verify valid admission, target drift, disk drift, wrong generation, independently revoked ticket and manual-path preservation. Added to the exact-head G8 PHP 7.4/8.3 matrix.
- **Certification note:** this test is hermetic. It does not replace full WordPress/DB concurrent update acceptance, live Host filesystem restore proofs, three frontend probes or external-effect signatures.

### Deeper adversarial review — replay, mirrors and wall-clock rollback

The following gaps were found after the first P0/P1 review and addressed in source:

1. **P0 – automatic checkpoint generation TOCTOU:** `Automation_SLO::ticket_allowed()` alone authenticated the ticket/runtime package, not the mutable target/current pair in Runtime Convergence. The owned mutation guard now binds both values to the original generation digest and rejects a newly restored/replaced checkpoint. Sliced Cron handoffs are fenced, and `g8-cron-generation-guard.php` exercises the real private method with a disposable file.
2. **P1 – missing signed mirror pins:** the candidate could previously omit declared mirrors because it only checked the candidate-provided list. The signed `source['mirror_sha256']` set must now match exactly, including omissions, duplicates and additions. Regression fixtures cover omitted and added mirrors.
3. **P1 – clock rollback:** a structurally valid HMAC state could contain future-dated bucket, ticket or independent kill-switch records. The admission and execution gates now fail closed on meaningful future skew and retain uncertain expired-worker tickets for governed reconciliation; signed negative fixtures cover all three timestamp families.
4. **P1 – orphaned work:** an expired ticket with unknown external outcome remains in the signed ledger, blocks new automatic admissions and cannot be replayed. It **cannot** be automatically cleaned or claimed repaired; external effect reconciliation and governed recovery remain required.

**Remaining limitations:** no executed GitHub CI proof at the latest HEAD, no certified test on real database/Host/WordPress, no signed provider package provenance and revocation acceptance on enrolled Staging, no SLO false-repair/rollback/cost source observations, and no independently governed release/restore receipt. These boundaries stay open; source security fixes and mocked negative fixtures must never be described as end-to-end certification.

### Extended P0/P1 security review — partial bootstrap, evidence provenance and metric integrity

- **P0 missing automatic guard:** `Runtime_Convergence::resume_safe_phases` no longer interprets an absent, incomplete, non-array or exception-throwing `Automation_SLO` admission as manual authority. It parks the checkpoint as `pending_manual_resume`, turns automatic retries off, and retains current manual governance. The hermetic `g8-cron-guard-missing.php` fixture is invoked in separate missing-class and throwing-class PHP processes.
- **P0 mutation ticket verifier:** `guard_automatic_ticket` accepts **only literal `true`** and converts exception/non-true results to a stable denial. The executable reflection fixture covers valid, expired, revoked, false-result, and throwing-verifier cases.
- **P1 provider assessment TOCTOU:** a second Site Profile/external Restore Epoch readback after the existing compatibility assessor denies any cross-generation composite snapshot. Every comparison requires a valid profile digest, site UUID, exact external restore SHA and epoch; missing current eligibility flags cannot silently preserve a capability.
- **P1 certifying misleading provider state:** absent or entirely ineligible capability graph is now reported with `provider_wide_quarantine=true` but **does not** disable providers or touch grants. Behavioral receipt **identity/digest** changes cannot be reported as unchanged merely because high-level verified flags stay constant.
- **P1 secret observation value:** dynamically named schema-migration observation values are checked for secret-bearing scalars, not only sensitive field names. Stored records remain strictly non-authorizing and bounded.
- **P1 SLO ledger invariants:** every admitted workload must be accounted for by exactly one pending ticket or completed outcome; each ticket must match the row's exact profile/restore binding. Re-sealed malformed records are rejected.
- **P1 false repair attribution:** verified runtime repair now requires a specific `mad4b.runtime-convergence-apply.v1` Cron result, a completed checkpoint with consistent changed-safe-phases, current independent readback and a still-valid automatic ticket. This prevents accepting a standalone optimistic payload as completed repair; full causal attribution still needs correlated live operation receipts.
- **Unproven/live gates unchanged:** GH Actions exact-head PHP 7.4/8.3 executions are not certified while jobs remain queued; SLO production-like denominators, real DB/host concurrency, signed provider artifact/readback, external-effect inventory, browser and governed Staging acceptance are separate requirements. These source patches do **not** authorize the merge or Production.

### Deep code-path invariants — HMAC, thrown workers and provider receipts (2026-10-08)

Additional scrutiny of the previously patched G8 logic found the following, now fixed in source:

- **P0 — serialized object before HMAC verification.** `G8_Record::valid()` used to HMAC-serialize a value retrieved from WordPress Options before rejecting nested objects. An object with `__serialize()` could execute code during a passive integrity check. The shared G8 record now enforces a bounded, passive scalar-only tree **before** any serialization/hash verification. A regression asserts that the object callback is never invoked.
- **P1 — behavioral evidence side effect.** Provider-convergence observation previously serialized an accepted receipt without checking its nested types. It now rejects PHP objects/resources, excessive nesting and oversized values before computing the receipt digest; a malicious `__serialize()` fixture is denied.
- **P1 — thrown automatic worker leaves unknown ticket.** `resume_safe_phases()` used to call `run_safe_phases()` without a worker exception boundary. Worker exceptions and malformed returns now become explicit `WP_Error` outcomes, which are submitted to the guarded ticket settlement and governed retry policy. A throwing maintenance lease regression checks that the error is durably accounted for and non-retryable.
- **P1 — inconsistent signed retry budget.** Pending tickets must have all three extant SLO budget scopes (site, provider and capability), and admitted totals must exactly match pending plus completed outcomes. Signed-but-inconsistent records now fail closed, even if the HMAC itself validates.
- **P1 — false repair denominator/attribution.** Count a verified Runtime Convergence repair only when an exact successful Cron result identifies changed safe phases, the matching completed checkpoint, a still-live ticket and independent current readback. A changed receipt does not itself authorize subsequent work.

Open and explicitly not certified: real MySQL/MariaDB DB concurrency/provenance tests, live signed-pack/revocation and source-mirror provenance, production-like failure injection, measured automation SLOs, host and browser acceptance, post-restore external effects reconciliation, CI jobs on final HEAD, and delegated release attestation. **Repository fixes and self-contained fixtures do not close G8 acceptance.**

## G8 three-gap remediation — 2026-10-08

The three remaining G8 evidence gaps now have executable source-level hooks and
separately defined real-world acceptance requirements. **None of the following
implicitly certifies Staging or authorizes a merge / Production.**

### A. Real SQL CAS on disposable MySQL/MariaDB

- Added `tests/g8-mysql-cas-integration.php`, requiring the exact
  `G8_CAS_DISPOSABLE=1` opt-in and a disposable database.
- `.github/workflows/feature-007-g8-database-cas.yml` runs two independent
  processes against a real InnoDB options-like table, for PHP 7.4/8.3 and
  MySQL 8.4/MariaDB 11.4. Both workers read the same prestate behind a
  barrier, then compete for one SQL BINARY prestate CAS.
- Contract: exactly **one winner**, **one conflict**, signed readback,
  refusal of duplicate insert and replay of stale prestate. No live site
  passwords, Production tables, or Staging database access are used.
- G8_Record additionally rejects executable PHP objects before *any* CAS
  serialization; HMAC validation and checkpoint equality use inert data.
- Until those exact-head Actions jobs complete successfully, the matrix is
  **configured, not certified**. A disposable DB test does not reproduce
  all Site Profile/Host/update-lock behaviors from real Staging.

### B. Repair attribution, outcome chain and SLO truth

- Completed automatic safe-phase checkpoints include a **current-slice**
  changed-phase list. A historical phase carried from a previous Cron slice
  does not establish that a new ticket performed repair.
- The already-admitted worker issues a **local HMAC-only causal receipt**
  bound to ticket fingerprint, generation, current runtime/restore, exact
  checkpoint target and changed-slice digest. The SLO reads the separately
  persisted checkpoint and accepts `verified_repair` only with exact receipt
  and independent fresh convergence readback.
- Every settlement commits the counters **and** a locally HMAC-protected,
  hash-linked bounded operation outcome receipt in the same record CAS.
  The rolling root preserves retained-chain continuity after the 64 most
  recent entries. Token plaintext is never exposed in the public view.
- No caller-supplied success boolean can create release authorization.
  The SLO continues reporting `false_repair_rate`,
  `rollback_failure_rate`, `quarantine_rate`, `intervention_rate`,
  and `cost_rate` as **unmeasured/null** until independently correlated
  postcondition, rollback and cost evidence actually exists. A local HMAC
  is not a native cryptographic execution receipt and does not prove
  external effect causality.
- Re-sealed falsified chains, forged checkpoint evidence, receipt replay,
  missing ticket budgets and future wall-clock timestamps fail closed.
- Existing older G8 signed ledgers without this outcome history require
  governed reconciliation/migration rather than silently filling missing
  historical evidence.

### C. Post-restore external effects, G9 and governed acceptance

- `G8_Restore_Convergence::evidence_pack()` verifies bounded, inert,
  exact-matched native `MAD4B_SCP_Execution_Receipt::verify()` witnesses
  against an external-effect inventory; missing, foreign, unsigned or
  incompatible witnesses remain visible as evidence gaps.
- The G8 restore status also checks G9's separately governed passive restore
  fence when integrated from sibling Draft PR #288. G8 cannot synthesize a
  G9 acceptance receipt, grant new capabilities, replay effects, acknowledge
  restore, or enable candidate rebinding.
- Even **valid native execution signatures** prove only a native operation
  occurred; they do **not** prove that an external payment/email/provider
  system was independently rewound or that its complete inventory was
  observed. These independent provider/host proofs and explicit governed
  owner approval are still required.
- Added `tests/g8-staging-acceptance-readonly.php` for an enrolled staging
  WordPress runtime. From an authorized WP-CLI host, run:

```bash
G8_READONLY_ACCEPTANCE=1 wp --path=/path/to/staging \
  eval-file wp-content/plugins/mad4b-site-control-plane/tests/g8-staging-acceptance-readonly.php
```

  The script only emits timestamped sanitized evidence for exact site/runtime
  binding, SLO integrity/outcome chain, zero unresolved workers, fresh frontend
  samples, real MCP inventory, provider pack epoch and G9 origin fence.
  `release_acceptance`, `write_resume_allowed` and governed post-restore
  approval intentionally remain **false** in this read-only report.

### Closure checklist

Real DB matrix green at exact PR head; hermetic PHP 7.4/8.3 matrix green;
signed provider/canary/rollback tests on enrolled Staging; no dangling/unknown
SLO operations; ≥3 genuine current-build frontend samples; signed native
execution receipts and independently verified complete external-effect
inventory; G9 host/runtime rollback/readback; and explicitly governed
post-restore/release owner acceptance. PR #289 remains Draft until those
receipts exist and child-branch bootstrap overlaps with G9 are reconciled
in Integration Hub #258.

### P0 post-closure audit — stale Cron and inert checkpoint admission

A post-implementation adversarial review found an authorization/state lapse in the
WordPress Cron entrypoint: `resume_safe_phases()` previously checked the
runtime identity but **did not require a schedulable checkpoint state**. Thus
a queued event might re-enter automation after a separate worker/owner set
`blocked`, `completed`, `pending_manual_resume`,
`waiting_for_exact_runtime_restart`, or explicitly disabled automatic
retry. This is now fenced *before* any safety-ticket reservation or new
mutation: only `pending_restart` and `pending_safe_phases` can reach
the worker, and an explicit `automatic_retry_allowed=false` is final
until the existing governed lifecycle intentionally re-arms a checkpoint.

In addition, the Cron entrypoint requires the **whole** checkpoint be bounded,
passive data through the real `MAD4B_SCP_G8_Record::inert` check before any
hash, update, or object serialization. Its exact `target_identity` must be
five string fields with pinned digests and no untrusted extra keys; target
identity inspection cannot call an injected PHP `__serialize` callback.
The shared G8 digest/HMAC helpers enforce that passive-data rule themselves.

Executed fixtures were added to the PHP 7.4/8.3 matrix for all terminal/manual/
restore-wait/paused states and injected executable objects. The disposable
MySQL/MariaDB CAS harness is now hard-bound to one fixed loopback CI database
identity, and its workflow asserts that an incorrect target DB exits with a
**safe refusal code before connecting**.

These changes remain source-level until the exact-head GitHub Actions and
separately enrolled Staging tests complete; they do not constitute a live
release or restore acceptance decision.
