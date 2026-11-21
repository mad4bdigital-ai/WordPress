# PR #942 conflict provenance successor — 20 November 2026

**Review-only evidence. No functional signoffs, release authorization, merge or runtime activation.**

## What was independently verified from Git objects

- The original 57-row conflict ledger references PR #882 correctly but records `source_920_blob: null` in **57/57 rows**. Every corresponding file exists in the actual PR #920 tree; the source attribution omission is in the older ledger, not the tree.
- The older `current_candidate_blob` is stale for **9/57 rows** when verified against PR #942's exact final commit. These original historical rows are preserved unchanged.
- Exactly **46** collision paths at PR #942 are byte-identical to PR #920's tree; **11** are neither identical to PR #920 nor PR #882 and need direct review of combined changes. No conflict path matches PR #882 exclusively.
- Exactly **2** of these collision paths changed between PR #942 and PR #964: `governance/production-readiness/capability-coverage.json` and `tools/readiness_test_execution.py`.
- Against a **saved on-disk source-copy hash inventory, not a freshly authenticated WordPress runtime**, PR #964 matches 787/991 file blobs; 203 differ and one build-provenance file is absent. This count is not a deployment admission metric, and no wholesale file copying is proposed.
- A read-only `git merge-tree --write-tree` between exact `master` `c34b93fb` and PR #964 `9f610f0a` completed without text conflicts. The eight transport commits in `master` must be retained in any release candidate. An artifact tree alone is not an approved functional merge.

## Verification and review boundary

The accompanying Git-only verifier looks up exact file blobs on pinned ref SHAs, fails closed on missing paths, incorrect PR #882 hashes, duplicate rows, or forged functional signoffs, and emits a deterministic, read-only JSON supplement. The four isolated tests exercise omission/stale detection and negative cases. Existing original ledgers, attested heads and runtime installations are untouched.

**Still blocked:** Semantic review of 57 collision paths (especially authority, execution, Skills and Content Readiness); reconstruction or explicit exclusion of runtime-only behaviors; source-copy mismatches; independent Site Profile-bound runtime readbacks; live e-mail delivery evidence; rollback and approved final exact-head Release Verdict. Do not change `manual_functional_signoff` based on SHA agreement alone.
