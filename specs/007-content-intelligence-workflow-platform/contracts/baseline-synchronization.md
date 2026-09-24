# Contract — Baseline Synchronization and Drift Gate

Contract: mad4b.feature-baseline-sync.v1

## Purpose

A specification based on a moving integration branch becomes stale when the base branch advances, even if the specification's own CI remains green.

## Baseline identities

Feature metadata records:
- baseline_branch;
- baseline_head_at_creation;
- current_baseline_head;
- baseline_sync_commit;
- baseline_checked_at.

The creation SHA is historical provenance.
The current baseline SHA is the latest reviewed/synchronized parent.

## Hard gate

On every pull-request run:
1. obtain the exact current PR base SHA;
2. verify it is an ancestor of Feature HEAD;
3. if not, fail with BASELINE_STALE;
4. report ahead/behind relation;
5. do not treat specification consistency as current-runtime compatibility.

## Semantic review

When the base advances in architecture-sensitive paths, synchronization requires an impact review.

Sensitive families include:
- OAuth/authority;
- provider certification;
- Full Staging Authority;
- MCP transport;
- approvals/grants;
- runtime provenance;
- workflow providers;
- existing primitives Feature 007 depends on.

## Synchronization

Preferred synchronization:
- real merge/rebase ancestry preserving exact base commit;
- rerun all specification/consistency/provider workflows;
- update current_baseline_head;
- update affected coverage/research/reference text if semantics changed.

Copying files without ancestry does not satisfy this gate.

## Freeze

A final implementation slice is bound to an exact baseline SHA.
If base moves before merge, the gate reopens.
