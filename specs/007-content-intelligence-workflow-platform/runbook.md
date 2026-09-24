# Runbook — Feature 007

## Rule
Every cycle begins with exact identity and ends with readback/evidence. Never infer readiness from a branch name, plugin activation, or previous approval.

## A — rc.59 lineage reconciliation
1. Fetch PR #45/#47 exact heads and merge base.
2. Enumerate #45-only commits.
3. For each: SHA, intent, files, security/governance surfaces, replacement code, replacement tests.
4. Classify SUPERSEDED/EQUIVALENT/REQUIRED.
5. REQUIRED requires a targeted port and regression contract.
6. Re-run compare and CI.
7. Emit hashed reconciliation artifact.
Stop on unknown intent, missing replacement evidence, or unresolved REQUIRED.

## B — exact Staging acceptance
1. Freeze SHA.
2. Build exact package.
3. Record artifact/hashes.
4. Deploy exact reviewed package.
5. Read provenance.
6. Refresh catalog.
7. Run status.
8. Generate fresh authority plan.
9. Apply only exact-ready plan.
10. Read candidate binding/write readiness.
11. Save evidence.

Never reuse approval/plan/package evidence bound to an older candidate.

## C — provider recertification
1. Observe installed runtime.
2. Acquire exact package.
3. Hash package/critical files.
4. Semantic delta from prior baseline.
5. Review security-sensitive surface changes.
6. Disposable runtime.
7. Certify reads first.
8. For each write: capability, risk, expected state, readback, reversibility/recovery, canary, evidence.
9. Promote capability only.
10. Verify target runtime matches candidate.

A newer upstream release means update_available only; it does not auto-upgrade or auto-invalidate the installed exact certification.

## D — Content Job recovery
1. Read state/stage/latest event.
2. Read current artifact heads.
3. Identify hard blocker or retryable error.
4. If inputs unchanged and step idempotent, resume checkpoint.
5. If dependency changed, invalidate dependent artifacts and return to earliest affected stage.
6. Cancelled history remains immutable.
7. Never repeat irreversible publication without target-state readback.

## E — ContextPack rebuild
Triggers: source version/review/authority change, job requirement change, site/brand/language change.
Re-resolve → select eligible sources → new ContextPack → compare hash → invalidate downstream only if changed → preserve history.

## F — Workflow execution
Read workflow/SHA → generate WorkflowPlan → confirm certification → confirm no unmanaged privileged MCP → acquire grant/approval/budget → execute exact SHA/plan → read execution → persist evidence. Site mutation is a separate MAD4B capability call.

## G — Draft publication
Require FinalQA → exact PublishManifest → read target state → expected fingerprint → invoke governed content/media/SEO abilities → read back → verify mutation receipt → store target object ref. Public publish remains separate.

## H — Host boundary
If work needs outside-WP files, server logs, PHP/server config, SSH, OS services, other DBs/domains, or account backups: do not widen WordPress filesystem/Developer authority. Route to Host Connector or fail external_authority_required.

## Evidence naming
- feature007-lineage-reconciliation-<sha>.json
- feature007-provider-cert-<provider>-<digest>.json
- feature007-job-<job_id>-gate-<gate>.json
- feature007-publish-<job_id>-<manifest_sha>.json

## Incident severity
P0: authority bypass, Production mutation without authorization, unmanaged privileged MCP side-channel, provenance mismatch, rollback corruption.
P1: wrong capability eligibility, state/stage corruption, cross-site/brand context leak, duplicate irreversible mutation.
P2: provider outage, partial research, degraded QA, observability defect.

## I — Governed tool / host execution

1. Identify semantic ToolOperation.
2. Resolve exact target/environment.
3. Read eligible certified executors.
4. For read-only operation, authorize and execute bounded read.
5. For write, observe current state and create ToolExecutionPlan.
6. Bind exact executor/target/candidate/path/resource scope.
7. Obtain required authority/approval.
8. Commit-guard dependency state immediately before execution.
9. Execute through structured argv/API mapping; never caller shell text.
10. Read back target state.
11. Persist ToolExecutionReceipt.
12. On failure, follow explicit rollback/forward-fix; do not silently switch to broader executor.

Fallback to WP-CLI, Host Runner, provider API/CLI or Recovery Runner is permitted only when a certified mapping satisfies the same semantic/authority requirements.

## J — WordPress unavailable

If WordPress/plugin boot is unavailable:
1. do not widen ordinary MCP/Developer authority;
2. use the minimal Recovery Runner/transport;
3. read package/runtime health;
4. verify known-good attested package;
5. restore only the bounded recovery target;
6. verify WordPress/MCP recovery;
7. stop before content/host writes outside the recovery contract.

Manual interactive hosting shell is the last-resort operator path and remains outside ordinary automation.


## K — Unified implementation closure

Use `implementation-closure.json` as the current workstream index and `gate-graph.json` as the hard dependency graph.

Execution:
1. verify exact master baseline and external repository governance;
2. reconcile task/evidence ledger;
3. recapture latest-master release root;
4. verify protected backup/recovery readiness before live mutation;
5. certify exact installed workflow-provider artifact and side-channel policy;
6. close authority/policy/liveness;
7. bootstrap site inventory and intent ownership;
8. progress ContentJob → artifacts → context/writer → research → blueprint/draft/QA;
9. perform governed WordPress draft mutation and exact readback/rollback;
10. perform semantic origin/public verification;
11. run Operator/Doctor/reconciliation/recovery and formal-state review;
12. evaluate the terminal gate against one exact ETG evidence chain.

At every step:
- read current evidence before mutation;
- bind plans to exact SHA/artifact/target;
- do not reuse stale approvals/certification;
- do not infer a live PASS from disposable CI;
- do not promote a maturity-deferred lane into Critical Kernel without Architecture Freeze admission;
- stop before Production unless Production is separately authorized.

## L — Runner first bootstrap

When no Host Runner exists:
1. identify exact HostTarget/environment;
2. acquire and verify attested RunnerPackage;
3. discover bootstrap-capable provider/plugin/API/filesystem channels;
4. create exact bootstrap plan; no caller shell text;
5. authorize only dedicated runner install zone + exact scheduling profile;
6. stage/install package;
7. register fixed wake-up cron/service;
8. issue one-time, target-bound, expiring enrollment token;
9. runner validates itself and proves target/root/runtime profile;
10. consume/revoke bootstrap credential;
11. read back package, schedule, runner identity and health;
12. run executor certification before any write eligibility.

If no certified bootstrap channel exists, report `runner_bootstrap_external_required`; manual terminal bootstrap is explicit operator fallback, never silently automated.
