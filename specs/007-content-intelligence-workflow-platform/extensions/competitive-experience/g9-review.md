# Feature 007 / G9 — Security, Correctness and Merge Review

**Scope**: PR #288, a child of Integration Hub #258. Covers T4066–T4070 and T4091–T4095 only. All ten remain PARTIAL. The branch is not approved to deploy or merge.

## Findings and remediations in source

| Severity | Finding | Resolution in G9 source | Limitation |
| --- | --- | --- | --- |
| P0 | Per-plan digest in rollout operation key allowed repeat reservations after re-planning | Key is now stable for site/cohort/ring/artifact/runtime/restore epoch; external lock and CAS reject repeat | Native executor must consume the fenced operation identity before any release |
| P0 | Authorization return type was treated as boolean instead of native structured claim | Require exact ability, server, provider, grant id, policy, resource-set and approval impact digests | Capability Descriptor, grant and governed write admission must be provisioned through normal policy; no new grants were created |
| P0 | Grant/candidate revocation race between planning and external CAS | Re-read same-cycle grant fingerprint and candidate match while holding the external lock | Cross-system mutation/dispatch transaction is not yet implemented |
| P0 | Crash between publishing external state and writing a DB loss marker | Publish+verify DB loss marker **before** external atomic rename, then recheck after | Power-loss/fsync and Windows atomic replace still need host certification |
| P0 | Caller-selected lax health limits or unsigned previous-ring hash could bypass preview as execution policy | Reservations require exact host-pinned threshold configuration; only nonproduction Staging pilot modeled | Canary/general ring require independent signed prior-ring acceptance |
| P0 | Local flock may look like a distributed lease | Site-host exclusive isolation must be independently observed; unverified/multi-host topology is rejected | No shared distributed/quorum lock or executor-wide global fence is implemented |
| P0 | DB restore/cloned origin could reuse an external anchor or a marker from another site | Current blog, UUID, environment, origin and mirror identity enforced | Independent cloned-host acceptance and quarantine drills still required |
| P1 | Signed receipt from unrelated mutation could appear as successful native G9 operation | Require exact runtime-release-set-apply ability, core provider, operation, target and signed terminal digest | Signed receipt and journal COMMITTED are **not** release acceptance |
| P1 | Empty/claimed-reconciled external effect list could be treated as verified | Restore stays quarantined without independent effect inventory and post-restore acceptance | No actual provider-side reconciliation is claimed |
| P1 | Stale/partial pilot health, provider revocation and cloned fleet members | Bound observations/thresholds, detect stale samples, cloned UUID/origin, incomplete inventories and partial rollback | Runtime local reader intentionally reports incomplete host/provider proof |
| P1 | File directory permits replacement by unrelated host users | Reject symlink and world-writable leaf directory; external path must not be inside WordPress/webroot | File locking and permissions are not sufficient cross-host guarantees |
| P2 | Health timestamps refreshed on every mock capture caused inconsistent sample digests | Fixtures pin sample observed_at and exercise stale-policy separately | PHP CI needs to finish; fixture presence is not a passing test |
| P2 | No discoverable closure status | Three read-only site-bound WordPress Abilities now expose observation, restore status and current blockers | Readiness surface does not grant execution |

## Deeper review addendum — operational correctness and CI

- **P0 / CI root discovery:** both G9 Python validators originally used `Path(__file__).resolve().parents[4]`, pointing one directory above the repository. They now use `parents[4]` with explicit root/workflow assertions; CI path filters now watch `g9-*.py`, not just PHP.
- **P0 / mismatched execution journal:** the existing `Execution_State_View::operation()` includes `scope`, `source_contract`, `evidence.operation_id`, `operation_binding_sha256`, `journal_head_sha256`, `latest_sequence`, and orphan/lease hazard indicators. A bare `COMMITTED` flag does not prove execution for the fenced operation. G9 requires all these independent identity and durability facts, while still refusing to mint release acceptance.
- **P0 / missing native G9 capability:** `MAD4B_SCP_G9_Release_Fence::reserve()` references `mad4b/g9-release-reserve` in native authorization. It is deliberately not registered in the existing Capability Descriptor/grant/executor system. This path remains non-operational and requires an explicit reviewed integration, not merely enabling the Staging host flag. The read-only closure status names this blocker.
- **P0 / database rollback:** if the external anchor file survives but the WordPress DB marker disappears or has `anchor_seen=false`, G9 now quarantines instead of accepting a restored stale database as if first-time enrollment.
- **P1 / fleet claimed transitions:** the reducer denies contradictory terminal transitions (e.g. COMMITTED followed by FAILED) and backwards execution transitions for the same site+operation, in addition to enforcing site-local sequence monotonicity.
- **P1 / lockfile directory race:** a private external anchor directory is checked after mkdir, before acquiring its lock, and again during readback. Both group-writable and world-writable modes fail closed; single-host OS-level isolation must be certified separately.
- **P1 / cryptographic chain boundary:** journal hashes and the current external anchor are still local evidence, not a remotely witnessed quorum transaction. Power loss, broken fsync guarantees, Windows rename semantics, and independent multi-host releases require host-level drills and, where applicable, a distributed lease.

### Exact G9 ↔ native runtime executor contract to implement separately

1. Register an exact reviewed G9 fence reservation capability and policy descriptor through the owning G7/G8 governance process. No blanket permissions, implicit grants, or automatic enabling.
2. Have the existing native runtime-release-set executor consume the **same site/cohort/ring/artifact/epoch operation SHA** under current authority before any provider side effect. Its signed request and operation-journal binding must refer back to that reservation.
3. Produce a signed terminal receipt and independently verified per-site post-update host/provider/health/external-effect readbacks; only a separate signed release acceptance lane can permit the next ring.
4. Implement explicitly approved compensating rollback with independent external-effect reconciliation and a separately signed post-restore acceptance receipt.
5. Run a real Staging multi-site fault matrix for concurrent workers, missing DB mirror, restore epoch drift, partial rollback, provider outage, host swap, cloned origins, signed-receipt substitution, and crash between marker/file transitions.

These are **unimplemented or unverified runtime gates**, not tasks completed by the source-only review.

## Independent dependencies

1. G7 release/host/compensation acceptance (PR #286) and G8 provenance/schema/capability convergence (PR #289) must be integrated into #258 before final G9 acceptance.
2. Current runtime release executor, Execution State View, signed Execution Receipt and separate host/provider/browser acceptance must demonstrate actual same-cycle Staging work.
3. Multi-host global fencing, provider-side external effect reconciliation, pilot-to-canary release receipts, partial rollback and disaster-restore drills are not yet evidenced.
4. GitHub Actions (PHP 7.4 and 8.3), exact-head repository CI, current Hub cumulative CI and governance owner attestation must finish independently.

## Review disposition

**Source security posture**: materially hardened, but not independently certified.

**Repository test status**: static invariants exist and PHP fixtures are committed. Actions jobs that are queued or skipped are not successes.

**Runtime, multisite and disaster-recovery acceptance**: NOT VERIFIED.

**Merge/release decision**: NOT READY. Keep #288 Draft, do not auto-merge, do not promote to Production, do not grant capabilities based on repository-only evidence.

## Hand-off evidence

- `g9-delivery.json` lists exact scoped tasks and files without misleading DONE claims.
- `g9-delivery.md` describes the code paths and remaining operational proof.
- `g9-security-source-contract.py` verifies branch source invariants in CI, alongside hermetic PHP 7.4/8.3 checks.

## Correction — first-read and Python root regression (2026-10-08)

A deeper execution-path review found two P0 correctness regressions. `Resilience_Anchor::read_path()` required `fileperms()` on a directory which does not yet exist on first installation; first `read()` thus falsely failed with unsafe directory permissions. It now emits an uninitialized zero-revision read-only preview **only if** both directory and DB marker are absent; a missing directory after initialization returns `lost` and requires reconciliation. The hermetic fixture checks no directory creation on first read.

The earlier Python CI root correction was wrong: `Path.parents[3]` resolves `wp-content`, while `parents[4]` resolves the repository root. Both verifier scripts are corrected. Only completed workflow runs can establish runtime success.

## Deeper evidence binding (2026-10-08)

The local resilience observer previously allowed `authority.eligible` with a syntactically valid artifact digest even when `Live_Acceptance_Observer::build_provenance_identity_status()` explicitly returned `identity_ready=false` or `manifest_valid=false`. It also omitted exact equality between the pack registry restore epoch and the external current Restore Epoch. G9 now records `artifact_identity_unready`, `certification_registry_unready` and `registry_restore_epoch_mismatch` as blockers, and does not mark the site eligible while any are present. Hermetic tests inject a deliberately invalid artifact and drifted registry epoch. This is conservative read/preview validation, not a signed Staging acceptance certificate.

## P0 — Valid historical file replay detection (2026-10-08)

The earlier DB loss marker recorded only `anchor_seen` and the site identity; a valid previously committed external file with an older `revision` and matching unkeyed SHA-256 digest could have replaced the current file without triggering a checksum error. G9 now checkpoints the **next** exact `anchor_revision` and `anchor_sha256` in the DB before atomically publishing the external file. All readbacks compare exact marker and file, rejecting old valid files and old valid markers independently. A crash between DB checkpoint and disk publication remains quarantined, not retried blindly. Legacy marker records without high-water fields now require governed reconciliation/migration; never assume old records can be upgraded safely. This pairwise checkpoint is not a proof against an attacker rolling back BOTH DB and external anchor in concert; independent off-host witness/quorum is still required for a true distributed monotonic fence. A hermetic regression replays a valid revision-1 file after committing revision 2 and expects `mirror_anchor_mismatch`.

## P0 — Same-cycle generation/restore CAS admission (2026-10-08)

The earlier G9 reservation rechecked only current Staging write-grant fingerprint and candidate under its external lock; a runtime swap or restore epoch change after the initial snapshot could still precede fence reservation. G9 now calls the existing `Runtime_Generation_Fence::assert_current()` with the exact captured generation and independently rereads `Restore_Epoch::status(false,true)` while holding the external CAS lock, rejecting any drift before publishing a reservation. The hermetic test injects generation revocation after first admission. This does not eliminate cross-host races or replace an executor-side same-cycle assertion before dispatch.

## P0 — Signed receipt and native journal join (2026-10-08)

`MAD4B_SCP_Execution_Receipt::build()` allows the `operation_journal` stage to be `NOT_REQUIRED` for general mutations. G9 previously verified that a release receipt and independently read `Operation_Journal` were each plausible, but did not require the receipt's signed stages to reference the **same** journal operation. G9 now requires a signed `operation_journal` stage with `status=PASS`, `evidence_type=operation_id` and `evidence_sha256=sha256(exact_operation_id)` before its verifier accepts the receipt. Negative fixtures cover missing and mismatched stages. This is still not a standalone release/rollback acceptance certificate; native executor dispatch itself remains unimplemented.

## P0 — Byte-for-byte runtime provenance at G9 CAS (2026-10-08)

The fast `build_provenance_identity_status()` validates packaged manifest metadata but documents `full_runtime_hash_validation_deferred=true`. It cannot prove the exact plugin files on disk are unchanged. G9 reservation now calls the existing `build_provenance_status()` full byte-for-byte package check before native admission **and again inside the external CAS lock**, requiring `runtime_manifest_match=true`, `manifest_valid=true`, `stale=false` and the same package manifest digest as the site binding. Passive read-only status continues using the cheap identity observer. Hermetic tests reject a fast-valid but full-invalid package. Native executor must still independently validate the package immediately before any provider side effect.

## P1 — Direct WordPress Ability registration bypass (2026-10-08)

After earlier hardening, `G9_Read_Surface::boot()` denied duplicate/foreign reader pinning and refused to attach the `wp_abilities_api_init` hook. However its public static `register_abilities()` still registered read endpoints when directly called by another PHP component. G9 now requires an internal `reader_pinned` latch set only after successful code-owned reader registration. The negative hermetic test exercises direct registration after failed boot and requires zero Abilities; a separate positive boot PHP test covers the three private read-only schemas. This does not grant new execution capabilities.

## Correction — independent native identity namespaces (2026-10-08)

The previous receipt fixture manufactured equality between the transport request ID and the Operation Context UUID, and between the native authorization target fingerprint and the G9 logical operation digest. These are separate identities in the existing core. G9 now verifies the real signed receipt contract without equating those namespaces. Verification additionally requires the signed `operation_journal` stage to identify the exact native UUID, a complete independently read Operation Journal trace matching the canonical committed head, and an explicit native terminal link joining the G9 operation/plan/site binding to the native request/target/resource-set and both receipt digests. A second state and reservation read rejects mid-verification drift.

The native runtime-release-set executor does **not** currently write this G9 link or register the G9 reservation descriptor/grant. Current valid but unlinked receipts remain `native_link_unavailable`; source-only fixtures never establish an accepted native rollout. The regression now exercises the real core Execution Receipt builder/verifier and Execution State View normalization with hermetic crypto/persistence doubles. Its default fixture mirrors today's unlinked claim and is denied. The separately labeled synthetic future-producer fixture only tests the passive verification contract.

## Correction — fresh directory permission admission (2026-10-08)

PHP may cache a previous `fileperms()` result. The G9 directory permission fixture changed mode from writable to private and then reached CAS with the stale mode, preventing the intended database-marker failure path. Directory path admission and the post-create CAS check now explicitly clear stat cache before validating current permissions. Fixtures also clear it after each deliberate chmod. The private-directory requirement is preserved, and a later unrelated worker making the directory writable is not hidden by an earlier cached safe mode.

No local PHP/Python runtime was available for this direct-GitHub correction. Only completed GitHub Actions on its resulting exact HEAD can establish execution success; source review is not a runtime certification.

## P0 — Remove infeasible future-receipt digest from immutable terminal event (2026-10-08)

Adversarial causal-order review found the G9 verifier required a `execution_receipt_sha256` in the completed Operation Journal terminal event. The unified signed `Execution_Receipt::build()` consumes the durable terminal receipt and may only be materialized **after** that journal event, so the prior immutable event cannot depend on the subsequent signed receipt hash without prescribing an unsupported sign-before-journal sequence. The G9 terminal link now requires already-available native request, authorization target, resource set, exact G9 reservation and **durable terminal receipt SHA**; it does not carry the later execution receipt digest. The signed execution receipt is separately verified cryptographically and must contain a `PASS` operation-journal stage for the same UUIDv4; journal chain and head are independently read twice. A synthetic `execution_receipt_sha256` in terminal safe metadata is explicitly rejected, with regression coverage. This is still an unimplemented native producer contract and does not confer release acceptance.

## P1 — Fleet status laundering and rollback-intent semantics (2026-10-08)

`Fleet_Rollout::inspect()` originally used only the last **site** event to decide uncertainty. A new operation reporting `COMMITTED` could mask an earlier `PREPARED`, `FAILED`, or `RECONCILING` operation at that site. It also set `partial_rollback_observed=true` after mere rollback intent or `RECONCILING`, falsely suggesting any rollback had finished. The reducer now considers the last state of **each operation** when reporting unresolved sites; PREPARED counts as unresolved, and completed rollback claims are tracked separately from rollback intent. A separate `rollback_risk_detected` reports partial/uncertain intent, while `partial_rollback_observed` requires at least one reported terminal rollback and remains explicitly non-authorizing. Tests cover both state laundering and rollback intent vs terminal claims. Fleet summaries still require signed per-site acceptance and do not confer authority.

## P1 — Passive eligibility must match executable grant readiness (2026-10-08)

The G9 site observation previously allowed `authority.eligible=true` from generic authority `ready` and candidate status while `current_grant_snapshot_ready=false` or the grant-row fingerprint was absent. `reserve()` requires these stronger facts, so operational read-only status could overstate eligibility. Capture now requires a current grant snapshot, exact candidate binding and valid grant fingerprint along with its existing runtime/restore/artifact proof. A hermetic test makes only the grant snapshot stale while `ready` stays true. This change tightens passive reporting; no grants or authority were created.

## Adversarial objection register

See `g9-adversarial-closure.md` for 28 objection scenarios ranked by P0/P1 severity, exact source mitigation, missing signed native/Staging evidence, and a separate exit criterion. SOURCE_HARDENED is a code claim, not a passed test or release certificate.
