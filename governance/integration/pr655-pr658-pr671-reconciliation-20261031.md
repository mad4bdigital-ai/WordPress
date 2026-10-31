# PR #655 / #658 / #671 source reconciliation (31 October 2026)

## Immutable relationships

- PR #658 HEAD is an exact Git ancestor of PR #671 HEAD (`git merge-base --is-ancestor` succeeds). Treat #671 as containing #658's lineage; do not independently reapply the same history.
- PR #671 and the isolated all-open-PR integration HEAD share master ancestry. Direct merge produces **33 content/add-add conflicts** in the current snapshot, including adapters, startup/CLI, runtime, OAuth/MCP CI, and source-review registry. Unreviewed ours/theirs resolution is not authorized.
- PR #655 has no shared Git ancestor with the integration HEAD. A history-only merge would misrepresent provenance and could discard content.

## PR #655 source intake — tested locally, not published

- PR #655 compared against `master`: 96 changed file paths; 5 byte-identical to local integration, 91 different or missing.
- Rebased the **master-to-PR655 file delta**, not the unrelated Git history, onto an isolated integration worktree using `git apply --3way`. Two semantic conflicts were reviewed: the capability-coverage registry and `304-mad4b-central-dual-lane-registry.php`.
- Registry union retained MCP-01, added the distinct lifecycle regression test, and preserved distinct source/review records; final registry contains 507 reviewed changes and 263 reviewed capabilities, with unique keys.
- The dual-lane configuration retained the stricter explicit Production identity/scope/backup/readback requirements.
- 20 trailing EOF blank-line diffs corrected without changing program text.
- Local **candidate commit**: `9f5dfe6c008702694969bf16b418ca0577340174` at `/opt/mad4b-qa31-fix/pr655-source-intake-20261031`.
- Local checks: `git diff --cached --check` PASS before commit, PHP lint **38/38**, Python AST **27/27**, company lifecycle outcome PASS, dependency-certified runtime PASS, universal WordPress operations PASS, VPS executor admission PASS, native stage adapter **6/6**, native stage executor **12/12** (using Docker PHP wrapper on VPS without host PHP), VPS native operator policy PASS.

These are isolated source tests, not Production approval or a full WordPress runtime regression suite.

## Remaining guarded requirements

1. Reconcile PR #671's 33 conflicts by source-aware review and run the affected MCP/OAuth/QA31/Feature007 regression suite.
2. Verify that the #655 candidate does not regress other integrated plugins, release manifests, or migration surfaces.
3. Publish only using a GitHub identity authorized for workflow-file writes; **no privilege bypass, no forced updates**.
4. Recompute current open PR HEAD coverage after publishing and perform the branch's full CI gate. Do not label the branch merge-ready until all are proven.

No writes to `master`, Production, or live WordPress instances occurred in this isolated reconciliation.

## Additional isolated combined candidate evidence

- A diagnostic candidate at `/opt/mad4b-qa31-fix/pr655-pr671-combined-audit` incorporated the reviewed PR655 source patch and both PR671 / PR658 commit histories. The merge used `-X theirs` **for a diagnostic trial only** across conflicting text. Its semantics have **not** been accepted; overlapping changes from previously aggregated branches may be lost. It must **not** be published as a final integrated release.
- Candidate source review restored the immutable Feature007 workflow's exact reviewed branch source. Exact archived G4 and G9 proofs were verified against real historical Git commits and pinned to current Git blobs; CI-only review metadata was updated, with archived SHA/byte records unchanged.
- Isolated Feature007 outcome: **DRIFT 0**, **10/10** rebind tests, **62** positive/negative historical evidence assertions. QA31 source reconciliation, company bootstrap, MCP Class Provenance, RC104 integrated source, and the targeted native executor tests passed; standalone admin-page test requires a disposable WordPress administrator fixture and is not certified here.
- Latest isolated candidate commit: `ea52ec340e5d5be0a1e4bad66f571a4abf1e47cb`. The reviewed root source, two additional PHP sources and runtime-convergence regression checks are **CI-only**: no Production authority, grants or live execution are asserted.
- Production Readiness against master `b8f2e0a60fa45e28b49643f1eae6f73570c2fee8` returned **FAIL_UNCOVERED_CHANGES**: **237** unresolved gaps across **142** source files (**193 P0**, **44 P1**; 97 missing capability reviews and 140 missing exact source-change reviews).
- Complete structured read-only gap inventory: `governance/integration/pr655-pr671-readiness-gaps-20261031.json`.
- **NO-GO**: do not synthesize approvals, auto-invent source reviews or push this diagnostic merge. First perform semantic conflict reconciliation and exact regression reviews for the 237 gaps, then use a GitHub identity explicitly authorized to update workflow files, and run full CI before merging.
