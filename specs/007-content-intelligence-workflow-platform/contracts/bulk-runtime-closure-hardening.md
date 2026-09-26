# Contract — Bulk Runtime Closure Hardening

Contract: mad4b.feature007-bulk-closure-hardening.v1

## Purpose

Convert the post-architecture review findings into executable closure invariants. This contract does not expand ordinary Host, Production, shell, SQL, or Breakglass authority.

## Hardening domains

The critical closure slice MUST cover all of the following domains before `critical_kernel_vertical_slice_verified` can become true:

1. runtime vertical-slice proof;
2. fault injection and zombie-worker fencing;
3. evidence durability after side effects;
4. protected backup and restore rehearsal;
5. bootstrap/enrollment minimization;
6. filesystem and process confinement;
7. execution-location truthfulness;
8. recovery independence from WordPress boot;
9. gate DAG acyclicity and closure reachability;
10. cross-executor semantic conformance;
11. Bit Flows exact installed-version behavioral recertification;
12. operator/Doctor/DLQ incident handling;
13. complexity and architecture-freeze guardrails.

## Vertical slice

The minimum live slice is:

request
→ semantic ToolOperation
→ deterministic executor resolution
→ authority decision
→ exact ToolExecutionPlan
→ exact approval where required
→ commit-guard dependency revalidation
→ bounded execution
→ postcondition readback
→ durable ToolExecutionReceipt
→ reversible rollback/forward-fix proof
→ evidence linkage into the closure ledger.

No stage may be substituted with a documentation-only assertion.

## Fault injection catalog

Permanent denial/failure fixtures MUST cover at least:

- runner crash after lease acquisition;
- runner crash after side effect but before final receipt;
- lease loss before commit;
- duplicate delivery/replay;
- stale plan or changed target after approval;
- changed executor/execution location after approval;
- symlink/reparse target swap between validation and commit;
- zip-slip/path traversal;
- shell metacharacter and executable injection;
- output-budget and timeout exhaustion;
- provider timeout after possible remote side effect;
- readback failure after mutation;
- rollback failure;
- evidence persistence failure;
- WordPress/plugin boot unavailable during Recovery Runner use;
- backup corruption or restore interruption.

Every fixture has a stable reason code and expected terminal state.

## Evidence durability

A mutation MUST NOT be reported as `SUCCEEDED` unless its durable evidence is committed.

Where a side effect may have occurred but final evidence cannot be durably persisted, the normalized terminal state is:

`MUTATED_BUT_EVIDENCE_UNCERTAIN`

This state:
- is never auto-retried as a fresh write;
- requires reconciliation/readback;
- is incident-visible;
- cannot satisfy a release gate.

Write-capable executors SHOULD persist an intent/journal record before authoritative mutation and atomically persist the final receipt after verified readback.

## Bootstrap minimization

A bootstrap executor MAY only:
- install one exact attested runner package in the dedicated runner zone;
- create one exact scheduler/service registration;
- create/activate runner-specific identity material;
- consume one single-use, expiring, target-bound enrollment envelope;
- emit bootstrap/enrollment health evidence.

It MUST NOT:
- accept arbitrary commands;
- install arbitrary packages;
- create general cron entries;
- mint Host Write/Execution authority;
- create Production authority;
- access unrelated account/site roots.

## Filesystem/process confinement

Path policy is centralized. All mutation paths require canonical containment after symlink/reparse resolution and immediate pre-commit revalidation.

Process execution requires fixed executable + structured argv. No ordinary adapter may use caller-controlled `sh -c`, `bash -c`, PowerShell expression evaluation, PHP eval, WP eval, or equivalent.

## Recovery/backup closure

`protected_backup_recovery_ready` requires:
- bounded protected backup root;
- integrity digest;
- capacity/readiness evidence;
- backup receipt bound to exact runtime;
- disposable or Staging restore rehearsal;
- restored provenance/readback;
- interrupted-restore handling;
- known-good rollback identity.

Backup existence alone is not readiness.

## Gate graph closure

CI MUST prove:
- unique gate IDs;
- all dependencies resolve;
- no cycles;
- every terminal gate is reachable from declared roots;
- every blocking gate has at least one path to a terminal state;
- bootstrap transitions have explicit postconditions.

## Complexity guardrail

Feature 007 remains architecture-frozen. New contracts/gates/workstreams are allowed only when they:
- close a demonstrated implementation or safety gap;
- map to a concrete task/test/evidence producer;
- do not duplicate an existing semantic contract.

Documentation-only abstraction growth is not a closure mechanism.

## Acceptance

This hardening contract is satisfied only when machine validation proves the hardening matrix is complete and runtime evidence closes the live gates. Repository CI may prove structure and denial behavior; it MUST NOT self-assert live ETG or Production readiness.

Production remains unauthorized until a separate Production authorization path is satisfied.
