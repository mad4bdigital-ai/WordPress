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
