# Feature 007 / G9 — Adversarial objection and acceptance register

Scope: PR #288, child of Integration Hub #258, the ten G9 tasks T4066–T4070/T4091–T4095. This document records **challenge scenarios**, not a release certificate. No G9 native release, rollback or disaster-recovery action was executed during this review. PHP 7.4/8.3 GitHub Actions jobs must run to completion before any test result can be reported as PASS.

**Statuses:** SOURCE_HARDENED means a source predicate and/or hermetic negative fixture was added but the exact-head executable CI has not been confirmed. RUNTIME_BLOCKED means the requisite native actor, independent witness or Staging acceptance evidence is not yet present. DESIGN_GAP means completing it requires an owning subsystem or explicit governance review. An unverified status is NEVER a PASS.

| # | Severity | Assumed objection / failure attack | Current defensible response | Verification gate | Status |
|---|---|---|---|---|---|
| O01 | P0 | Replanning creates a different digest and reuses the same release | Stable site/cohort/ring/artifact/epoch operation identity; append-only CAS denies repeats | Two different plans for same release, second rejected | SOURCE_HARDENED |
| O02 | P0 | Clone/foreign multisite site reuses external state | Local blog, enrolled UUID, canonical origin and environment enforced | Foreign blog/origin/UUID negative fixtures plus Staging cloning drill | SOURCE_HARDENED |
| O03 | P0 | Persisted anchor disappears while DB still claims it existed | Loss-marker and missing-file/directory quarantine | Delete file after initialization and verify no automatic recreation | SOURCE_HARDENED |
| O04 | P0 | Valid older file overwrites newer file | DB checkpoint of next revision **and** exact file SHA; mismatched state quarantined | Commit revision 2, restore valid revision 1 file | SOURCE_HARDENED |
| O05 | P0 | DB mirror downgraded while external file remains newer | Exact mirror revision+file SHA comparison | Restore older marker without touching file | SOURCE_HARDENED |
| O06 | P0 | DB and external disk both restored together to consistent old version | Both checkpoints can roll back together; local SHA is not a keyed/quorum witness | Independent off-host monotonic anchor, certified restore/failover drill | DESIGN_GAP |
| O07 | P0 | Host A and Host B each acquire separate local flock | Site-exclusive host proof is required for local CAS | Real multi-host split-brain drill or shared distributed/fenced lease | RUNTIME_BLOCKED |
| O08 | P0 | Revoke grant or candidate after plan capture | Same-cycle current grant fingerprint and candidate under CAS; no blind retry | Controlled grant/candidate revocation during CAS, plus native pre-dispatch assertion | SOURCE_HARDENED |
| O09 | P0 | A one-time approval is consumed twice by duplicate authorization | Do **not** re-run full mutation admission in a loop; distinguish grant readback from consumable ticket | Native admission idempotency and one-time approval revalidation protocol owned by governance | DESIGN_GAP |
| O10 | P0 | Plugin file changes after cheap manifest check | Full runtime package hash verification both before authorization and within CAS | Tamper one package file between checks | SOURCE_HARDENED |
| O11 | P0 | Restore epoch or worker generation advances during admission | Exact native Runtime Generation and Restore Epoch reasserted inside CAS | Concurrent deploy/restore fault injection, then live executor guard | SOURCE_HARDENED |
| O12 | P0 | Signed execution receipt belongs to unrelated native journal | Signed operation_journal stage for same UUID, full journal chain, terminal head and independent double-read | Cross-journal receipt substitution with real signed fixtures | SOURCE_HARDENED |
| O13 | P0 | Terminal journal event must include signature created **after** itself | Link only to G9 reservation, native request/target/resource and earlier durable terminal receipt; independently verify later signed receipt | Construct journal then unified receipt in actual order | SOURCE_HARDENED |
| O14 | P0 | Caller writes forged G9/native linkage in supplied receipt | Linkage must originate in independently read append-only terminal native event, not in request | Staging native executor emits exact event, signed terminal receipt, independent readback | RUNTIME_BLOCKED |
| O15 | P0 | Unregistered G9 reserve Ability is accidentally treated as executable | Read-only interfaces contain no reserve mutation; operational readiness reports missing native binding | Reviewed Capability Descriptor, exact grant, authorized executor dispatch, no broad write grants | DESIGN_GAP |
| O16 | P0 | Green signed receipt equals release/canary acceptance | Execution != site acceptance; provider/host/external-effect readback still required | Independent signed site/rollout acceptance per ring | RUNTIME_BLOCKED |
| O17 | P0 | Restore claims no external side effects just because inventory is empty | Empty/untrusted effects never clear quarantine; no automatic write re-enable | Provider-side complete inventory and separately signed recovery acceptance | RUNTIME_BLOCKED |
| O18 | P0 | Pilot-to-canary promoted using arbitrary prior-ring SHA | Wider-ring reservation blocked until native accepted ring proof exists | Signed previous-ring acceptance and fresh per-site health | RUNTIME_BLOCKED |
| O19 | P1 | A later COMMITTED fleet operation hides an older PREPARED/FAILED operation | Per-operation unresolved aggregation, not only last per-site event | Earlier unresolved op + later separate COMMITTED op fixture | SOURCE_HARDENED |
| O20 | P1 | Rollback RECONCILING incorrectly reported as rollback completed | Rollback intent, risk and reported terminal claims separated; no signature implied | In-progress rollback vs COMMITTED rollback fixtures | SOURCE_HARDENED |
| O21 | P1 | Read-only site says authority eligible while exact grant snapshot stale | Snapshot-ready, candidate-match and SHA required for site eligibility | Generic ready=true, snapshot=false fixture | SOURCE_HARDENED |
| O22 | P1 | Reader boot fails but public register_abilities() is called directly | Private reader-pinned latch gates ability registration | Forced competing reader plus direct register call fixture | SOURCE_HARDENED |
| O23 | P1 | Site has host diagnostic object but no certified isolation | Diagnostic presence distinguished from host-isolation verification | Local reader reports host evidence present but isolation false | SOURCE_HARDENED |
| O24 | P1 | Filesystem world/group-writable or symlink path bypasses local lock | Private anchor directory policy, rechecks at CAS/readback | chmod/symlink/race cases; Linux and Windows host acceptance | SOURCE_HARDENED |
| O25 | P1 | Power fails after DB checkpoint but before atomic file publish | Mismatched checkpoint fails closed; no blind recovery | Disk/fsync/fault-injection host drill with abrupt process/power termination | RUNTIME_BLOCKED |
| O26 | P1 | Workflows are queued, skipped or absent yet considered successful | Repository status and report explicitly deny completion | Exact-head PHP 7.4/8.3, all required cumulative CI SUCCESS | RUNTIME_BLOCKED |
| O27 | P1 | G7/G8 sibling bootstrap changes are overwritten | Integrate all three require_once/boot hooks; resolve G7/G8 operator workspace overlap | Deterministic child merge integration tests on Hub, Staging browser acceptance | RUNTIME_BLOCKED |
| O28 | P1 | Static source strings are mistaken for executed tests | Source guard is an additive smoke test, not proof of runtime behavior | Native PHP 7.4/8.3 hermetic and integration tests, exact-head CI run links | RUNTIME_BLOCKED |
| O29 | P1 | External anchor is corrupt, but closure UI hides all blockers behind a raw error | Read-only closure returns an explicit error code, no retry and no runtime authority | Foreign DB marker yields reported external_fence_unavailable and operationally_closed=false | SOURCE_HARDENED |
| O30 | P1 | Provider/effect/health completeness marker masks revoked provider, unknown effect or stale sample | Check every provider/effect and typed health timestamp in site observation and closure status | Independent negative hermetic fixtures for each while completeness is true | SOURCE_HARDENED |
| O31 | P1 | Repeated G9 bootstrap after failed reader pin returns null and hides failure | Cache exact boot result for worker, true or WP_Error, without repinning | Repeated healthy and failed boot test workers | SOURCE_HARDENED |
| O32 | P1 | A foreign plugin pre-registers one G9 read Ability and triggers partial trusted registrations | Preflight all three names, no overwrite or partial initial registration; fail with typed namespace collision | Foreign preexisting Ability fixture leaves zero registered G9 local Abilities | SOURCE_HARDENED |
| O33 | P0 | Existing Runtime_Release_Set performs native updates and writes a local transaction receipt, but lacks signed G9 journal producer and reservation consumption | Keep G9 native binding **unimplemented** and release acceptance false; never substitute plain runtime-release receipt for signed execution | Reviewed executor adapter with exact gated reservation, signed operation journal and independently verified Staging provider effects | DESIGN_GAP |

## Exit criteria

The following must be **simultaneously verified**, not inferred from a code diff:

1. Exact final HEAD and full cumulative Hub tree, with all required GitHub CI checks completed as SUCCESS.
2. G7 and G8 independent signed capability/host/acceptance foundations integrated with G9; G9 owns no hidden runtime authority or general grant.
3. Native Staging executor consumes the exact G9 reservation **before** any provider side effect, does not consume single-use approvals twice and emits a valid signed, replay-resistant native correlation record.
4. Pilot release and compensating rollback are run against certified Staging hosts; independent provider and external-effect readback is recorded.
5. Canary/general are admitted only using verified previous-ring site-local acceptance receipts and policy.
6. Disaster recovery/failover drill includes DB-only rollback, external-file rollback, joint rollback, host split-brain, crash ordering, and quarantine until independent post-restore acceptance.
7. Owner reviews exact-head evidence and explicitly authorizes merge separately. Production remains out of scope.

## Non-goals / restrictions

- Do not connect to or mutate the WordPress live site from this review window.
- Do not silently register or widen an execution Ability, grant Developer/Breakglass, or merge Draft G9.
- No G9 source-only artifact can assert Production readiness, external provider reconciliation, actual signed rollback, or successful Staging disaster recovery.
