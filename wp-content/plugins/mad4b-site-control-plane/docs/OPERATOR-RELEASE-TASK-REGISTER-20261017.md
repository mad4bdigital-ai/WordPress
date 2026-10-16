# MAD4B — Simplified Operator Delivery Task Register

Date: 2026-10-17
Goal: Fewer steps, smooth dynamic operations, scalable existing capabilities; postpone optional enhancements.
Baseline: PR #400 / 76ea7644370bfcbac9880d7a4f4defb5abf9056e (Draft, not deployed).
Safety: Staging only after separate owner approval, with Production, Developer and Breakglass excluded.

## Required operational delivery tasks

| ID | Priority | Task | Current status |
|---|---|---|---|
| OP-001 | P0 | Validate Owner Review first, then reserve and consume claim once | DONE IN SOURCE; needs live readback |
| OP-002 | P0 | Prevent replay and distinguish FINALIZING / VERIFIED / uncertain state | DONE IN SOURCE; needs live readback |
| OP-003 | P0 | Update stale Admin CI contract to current guarded ordering | DONE IN SOURCE; pending CI |
| OP-004 | P0 | Admit the concurrency loser readback-denied state; still prove only 1/5 wins and exactly one DB record | DONE IN TEST; pending CI |
| OP-005 | P0 | Complete exact-head PHP 7.4/8.3, MySQL/MariaDB and security checks | PENDING CI |
| OP-006 | P0 | Issue certified matching MCP Adapter and deterministic MAD4B plugin ZIP with fingerprints | PENDING CI PACKAGE |
| OP-007 | P0 | Independently inspect built ZIP archive, provenance and dependency list | PENDING ARTIFACT REVIEW |
| OP-008 | P0 | ETG and All Royal Egypt independent Staging OAuth, grant, Write and Browser Acceptance | PENDING SEPARATE LIVE ACCEPTANCE |

## Short normal route

Discover → Plan → Approve only if mutation required → Execute → Verify → Done.
On uncertainty, read the existing operation, reconcile and resume without blind retry.
Owner review concerns exact change approval, not ordinary diagnostics or page refresh.
Successful package checks permit installation testing, NOT authority activation or Production rollout.

## Deferred improvements (no new architecture in this cut)

| ID | Priority | Task | Resume trigger |
|---|---|---|---|
| OP-D01 | P2 | Consolidate wp_options Staging journal with existing Durable Execution and Operation Journal | Verified Staging transactional DB readiness and safe legacy migration |
| OP-D02 | P2 | Resource- and host-wide lock hierarchy | Observed shared-resource collision |
| OP-D03 | P2 | New evidence registry and cross-site incident correlation | Scoped incident evidence |
| OP-D04 | P2 | Generalized provider writer / rollback / advanced experiments | Proven provider defect with certified native API |
| OP-D05 | P2 | More workflow automation and UI restructuring | Demonstrated operator friction after short-path rollout |

Do not mark runtime ACCEPTED from source tests, CI, or package ZIP alone.
