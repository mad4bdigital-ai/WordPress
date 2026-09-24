# Contract — Release Lineage Reconciliation

Contract: mad4b.release-lineage-reconciliation.v1

## Inputs
- canonical candidate ref;
- competing/closed lineage ref;
- merge base;
- unique commits;
- current tests/contracts.

## Record per unique commit
- commit_sha
- commit_message
- original_intent
- affected_paths
- affected_authority_surfaces
- security_relevance
- current_replacement_paths
- replacement_tests
- replacement_commit_refs
- classification
- rationale
- reviewer
- reviewed_at

## Classification
SUPERSEDED:
A newer implementation intentionally replaces the semantic requirement and has equivalent-or-stronger tests.

EQUIVALENT:
The same semantic behavior exists on the canonical line through different history.

REQUIRED:
Material behavior is absent or weaker.

UNKNOWN:
Temporary analysis state only; blocks closure.

## Gate
Canonicalization requires:
- unknown_count = 0
- required_count = 0

If REQUIRED > 0:
- port behavior deliberately;
- add regression evidence;
- recompute classification.

Blind cherry-picking is forbidden for merge/convergence commits.

## Current Feature 007 seed

Compared line:
PR #45 head b5d697f05a939d87ca0b2ede1a08eb9e15f97312

Canonical candidate at feature creation:
PR #47 head ab179816c03acb45751c1707eddee50d19178298

Merge base:
f43ec3bead478749ead2c3939c8d362922f4b80a

Observed relation:
- canonical candidate ahead_by = 469
- canonical candidate behind_by = 10

The ten unique commits MUST be individually reconciled before rc.59 canonical merge.
